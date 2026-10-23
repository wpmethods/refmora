<?php
/**
 * Date ranges and summaries available in the Free overview.
 *
 * @package Refmora
 */

defined( 'ABSPATH' ) || exit;

/** Query ledger summaries for authorized store managers. */
final class REFM_Reports {

	/** Only these reporting periods may reach the database. */
	public static function periods() {
		return array(
			'daily'     => __( 'Daily (Today)', 'refmora' ),
			'weekly'    => __( 'Weekly (Last 7 days)', 'refmora' ),
			'monthly'   => __( 'Monthly (Last 30 days)', 'refmora' ),
			'this_year' => __( 'This Year', 'refmora' ),
			'last_year' => __( 'Last Year', 'refmora' ),
			'lifetime'  => __( 'Lifetime', 'refmora' ),
		);
	}

	/**
	 * Use local calendar boundaries and an exclusive UTC end for stored records.
	 *
	 * @param string                 $key Reporting period key.
	 * @param DateTimeImmutable|null $now Optional clock for deterministic date calculations.
	 * @return array Local and UTC range boundaries.
	 */
	public static function range( $key = 'monthly', $now = null ) {
		$periods = self::periods();
		$key     = is_string( $key ) && isset( $periods[ $key ] ) ? $key : 'monthly';
		$now     = $now instanceof DateTimeImmutable ? $now->setTimezone( wp_timezone() ) : current_datetime();
		$today   = $now->setTime( 0, 0, 0 );
		$days    = array(
			'daily'   => 0,
			'weekly'  => 6,
			'monthly' => 29,
		);
		$end     = $today->modify( '+1 day' );
		$utc     = new DateTimeZone( 'UTC' );
		if ( 'this_year' === $key ) {
			$start = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
		} elseif ( 'last_year' === $key ) {
			$end   = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
			$start = $end->modify( '-1 year' );
		} elseif ( 'lifetime' === $key ) {
			// MySQL DATETIME's earliest supported date includes the complete stored history.
			$start = new DateTimeImmutable( '1000-01-01 00:00:00', $utc );
		} else {
			$start = $today->modify( '-' . $days[ $key ] . ' days' );
		}

		return array(
			'key'         => $key,
			'label'       => $periods[ $key ],
			'start'       => $start->format( 'Y-m-d' ),
			'end'         => $end->format( 'Y-m-d' ),
			'utc_start'   => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'utc_end'     => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'start_stamp' => $start->getTimestamp(),
			'end_stamp'   => $end->getTimestamp(),
			'last_stamp'  => $end->modify( '-1 day' )->getTimestamp(),
			'invalid'     => false,
		);
	}

	/** The nonce protects the filter form; capabilities protect the report itself. */
	public static function requested_range() {
		$range = self::range();
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $range;
		}
		if ( ! isset( $_GET['refm_range'] ) ) {
			return $range;
		}
		$nonce = isset( $_GET['refm_report_nonce'] ) && is_string( $_GET['refm_report_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['refm_report_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'refm_report_range' ) ) {
			$range['invalid'] = true;
			return $range;
		}
		$key = is_string( $_GET['refm_range'] ) ? sanitize_key( wp_unslash( $_GET['refm_range'] ) ) : '';
		if ( ! isset( self::periods()[ $key ] ) ) {
			$range['invalid'] = true;
			return $range;
		}
		return self::range( $key );
	}

	/**
	 * Render the Free date filter.
	 *
	 * @param array $range Validated reporting period.
	 */
	public static function controls( $range ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<form class="refm-range-control" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="refmora"><input type="hidden" name="tab" value="overview">';
		wp_nonce_field( 'refm_report_range', 'refm_report_nonce', false );
		echo '<div><select id="refm-overview-range" name="refm_range" aria-label="' . esc_attr__( 'Date range', 'refmora' ) . '" data-refm-report-range>';
		foreach ( self::periods() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $range['key'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><button type="submit" class="button refm-primary">' . esc_html__( 'Apply', 'refmora' ) . '</button></div><small>';
		if ( 'lifetime' === $range['key'] ) {
			/* translators: %s: last included date in the site's time zone. */
			echo esc_html( sprintf( __( 'All time through %s', 'refmora' ), wp_date( get_option( 'date_format' ), $range['last_stamp'] ) ) );
		} else {
			/* translators: 1: first date, 2: last date in the site's time zone. */
			echo esc_html( sprintf( __( '%1$s - %2$s', 'refmora' ), wp_date( get_option( 'date_format' ), $range['start_stamp'] ), wp_date( get_option( 'date_format' ), $range['last_stamp'] ) ) );
		}
		echo '</small></form>';
		if ( $range['invalid'] ) {
			echo '<p class="refm-range-notice" role="status">' . esc_html__( 'The date filter was invalid or expired. Showing the last 30 days; choose a range and apply again.', 'refmora' ) . '</p>';
		}
	}

	/**
	 * Run three aggregates without fetching orders or caching changing balances.
	 *
	 * @param array $range Validated reporting period.
	 * @return array Financial and referral totals.
	 */
	public static function summary( $range ) {
		$empty = array(
			'pending'   => 0,
			'available' => 0,
			'paid'      => 0,
			'requested' => 0,
			'clicks'    => 0,
			'customers' => 0,
		);
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $empty;
		}
		global $wpdb;
		$currency = REFM_Settings::get( 'currency' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed aggregates over the custom financial ledger need fresh balances, with no WordPress CRUD equivalent; Lifetime includes all stored history through today.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending,
				COALESCE(SUM(CASE WHEN payout_id=0 AND status IN ('available','paid','rejected') THEN amount-paid_amount ELSE 0 END),0) AS available,
				COALESCE(SUM(paid_amount),0) AS paid FROM %i WHERE currency=%s AND created_at >= %s AND created_at < %s",
				REFM_Store::table( 'commissions' ),
				$currency,
				$range['utc_start'],
				$range['utc_end']
			),
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed aggregate over custom withdrawal records, without stale cross-request financial caching.
		$requested = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE currency=%s AND status IN ('pending','approved','processing') AND created_at >= %s AND created_at < %s", REFM_Store::table( 'payouts' ), $currency, $range['utc_start'], $range['utc_end'] ) );
		// Click dates are already stored in the site time zone; customer timestamps are UTC.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Date-filtered counts from custom aggregate tables have no WordPress CRUD equivalent; Lifetime includes all history through today.
		$activity = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT (SELECT COALESCE(SUM(clicks),0) FROM %i WHERE click_date >= %s AND click_date < %s) AS clicks, (SELECT COUNT(*) FROM %i WHERE created_at >= %s AND created_at < %s) AS customers',
				REFM_Store::table( 'clicks' ),
				$range['start'],
				$range['end'],
				REFM_Store::table( 'customers' ),
				$range['utc_start'],
				$range['utc_end']
			),
			ARRAY_A
		);
		return array(
			'pending'   => (float) ( $totals['pending'] ?? 0 ),
			'available' => (float) ( $totals['available'] ?? 0 ),
			'paid'      => (float) ( $totals['paid'] ?? 0 ),
			'requested' => (float) $requested,
			'clicks'    => absint( $activity['clicks'] ?? 0 ),
			'customers' => absint( $activity['customers'] ?? 0 ),
		);
	}
}
