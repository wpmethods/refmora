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
				REFM_Store::table( 'link_daily' ),
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

	/**
	 * Restrict affiliate analytics to the signed-in approved account.
	 *
	 * @param int $affiliate_id Owner of the report.
	 * @return bool Whether this exact account can view the report.
	 */
	private static function affiliate_available( $affiliate_id ) {
		$affiliate_id = absint( $affiliate_id );
		return $affiliate_id > 0 && get_current_user_id() === $affiliate_id && class_exists( 'REFM_Affiliates', false ) && 'approved' === REFM_Affiliates::status( $affiliate_id );
	}

	/**
	 * Count completed referral orders in the same range and currency as the overview.
	 *
	 * @param array $range Validated overview reporting period.
	 * @return int Completed referred orders, including held commissions.
	 */
	public static function converted( $range ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return 0; }
		global $wpdb;
		$orders = self::order_storage();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One prepared ledger aggregate joined to authoritative WooCommerce order storage; no orders are loaded individually.
		return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i AS c INNER JOIN %i AS o ON o.%i=c.order_id WHERE c.currency=%s AND c.created_at >= %s AND c.created_at < %s AND o.%i=%s AND o.%i=%s', REFM_Store::table( 'commissions' ), $orders['table'], $orders['id'], REFM_Settings::get( 'currency' ), $range['utc_start'], $range['utc_end'], $orders['status'], 'wc-completed', $orders['type'], 'shop_order' ) ) );
	}

	/**
	 * Read lifetime clicks and current completed referral orders for this affiliate only.
	 *
	 * @param int $affiliate_id Signed-in affiliate.
	 * @return array Click and converted totals, or an empty array when access is denied.
	 */
	public static function affiliate_data( $affiliate_id ) {
		if ( ! self::affiliate_available( $affiliate_id ) ) {
			return array();
		}
		global $wpdb;
		$orders = self::order_storage();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One prepared aggregate, scoped to the authenticated affiliate in both indexed subqueries; order status uses the authoritative WooCommerce storage.
		$totals = $wpdb->get_row( $wpdb->prepare( 'SELECT (SELECT COALESCE(SUM(clicks),0) FROM %i WHERE affiliate_id=%d) AS clicks, (SELECT COUNT(*) FROM %i AS c INNER JOIN %i AS o ON o.%i=c.order_id WHERE c.affiliate_id=%d AND c.currency=%s AND o.%i=%s AND o.%i=%s) AS converted', REFM_Store::table( 'link_totals' ), $affiliate_id, REFM_Store::table( 'commissions' ), $orders['table'], $orders['id'], $affiliate_id, REFM_Settings::get( 'currency' ), $orders['status'], 'wc-completed', $orders['type'], 'shop_order' ), ARRAY_A );
		return array(
			'clicks'    => absint( $totals['clicks'] ?? 0 ),
			'converted' => absint( $totals['converted'] ?? 0 ),
		);
	}

	/**
	 * Render this affiliate's own lifetime clicks and conversion rate.
	 *
	 * @param int $affiliate_id Account ID supplied by the Free dashboard hook.
	 */
	public static function render_affiliate( $affiliate_id ) {
		$totals = self::affiliate_data( $affiliate_id );
		if ( ! $totals ) {
			return;
		}
		echo '<div class="refm-affiliate-activity" id="refm-affiliate-activity">';
		self::activity_donut( array( 'compact' => true ), $totals['clicks'], $totals['converted'] );
		echo '</div>';
	}

	/**
	 * Select the authoritative WooCommerce storage for read-only joined aggregates.
	 *
	 * @return array Table and column identifiers from the installed WooCommerce configuration.
	 */
	public static function order_storage() {
		global $wpdb;
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return array(
				'table'  => \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name(),
				'id'     => 'id',
				'status' => 'status',
				'type'   => 'type',
			);
		}
		return array(
			'table'  => $wpdb->posts,
			'id'     => 'ID',
			'status' => 'post_status',
			'type'   => 'post_type',
		);
	}

	/**
	 * Render a conversion donut against the recorded-click baseline.
	 *
	 * @param array $range Validated period or compact affiliate context.
	 * @param int   $clicks Recorded clicks.
	 * @param int   $converted Completed referred orders.
	 */
	public static function activity_donut( $range, $clicks, $converted ) {
		$clicks    = max( 0, (int) $clicks );
		$converted = max( 0, (int) $converted );
		$compact   = ! empty( $range['compact'] );
		$title_id  = $compact ? 'refm-affiliate-conversion-title' : 'refm-conversion-title';
		$rate      = $clicks > 0 ? 100 * $converted / $clicks : 0;
		$available = $clicks > 0 || 0 === $converted;
		$percent   = $available ? number_format_i18n( $rate, 2 ) . '%' : '—';
		$share     = number_format( min( 100, $rate ), 4, '.', '' );
		/* translators: 1: tracked click count, 2: completed referred order count, 3: conversion percentage. */
		$description = sprintf( __( '%1$s total clicks; %2$s converted orders; conversion rate %3$s.', 'refmora' ), number_format_i18n( $clicks ), number_format_i18n( $converted ), $percent );
		echo '<section class="refm-card refm-conversion-chart refm-activity-donut" aria-labelledby="' . esc_attr( $title_id ) . '"><h2 id="' . esc_attr( $title_id ) . '">' . esc_html__( 'Clicks & converted', 'refmora' ) . '</h2>';
		if ( ! $compact ) {
			echo '<p class="refm-muted">' . esc_html( $range['label'] ) . '</p>';
		}
		echo '<div class="refm-donut-layout"><div class="refm-donut-visual' . ( $clicks > 0 ? ' has-clicks' : '' ) . '" role="img" aria-label="' . esc_attr( $description ) . '"><svg viewBox="0 0 160 160" width="160" height="160" aria-hidden="true" focusable="false"><circle class="refm-donut-track" cx="80" cy="80" r="64" fill="none" stroke-width="16"></circle>';
		if ( $clicks > 0 && $converted > 0 ) {
			echo '<circle class="refm-donut-progress" cx="80" cy="80" r="64" fill="none" stroke-width="16" pathLength="100" stroke-dasharray="' . esc_attr( $share ) . ' 100" transform="rotate(-90 80 80)"></circle>';
		}
		echo '</svg><div class="refm-donut-center" aria-hidden="true"><strong>' . esc_html( $percent ) . '</strong><span>' . esc_html__( 'Conversion rate', 'refmora' ) . '</span></div></div><dl class="refm-donut-legend">';
		foreach ( array( 'clicks' => array( __( 'Total clicks', 'refmora' ), $clicks ), 'converted' => array( __( 'Converted', 'refmora' ), $converted ) ) as $key => $row ) {
			echo '<div class="refm-donut-stat refm-chart-series-' . esc_attr( $key ) . '"><dt><span class="refm-donut-swatch" aria-hidden="true"></span>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( number_format_i18n( $row[1] ) ) . '</dd></div>';
		}
		echo '</dl></div></section>';
	}

	/** Preserve the public renderer entry point for existing integrations. */
	public static function activity_bars( $range, $clicks, $converted ) {
		self::activity_donut( $range, $clicks, $converted );
	}
}
