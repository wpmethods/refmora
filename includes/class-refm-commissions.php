<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Commissions {
	public static function init() {
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'placeholder_removed' ), 10, 2 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'placeholder_removed' ) );
		add_action( 'refm_release_order', array( __CLASS__, 'sync' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'sync' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'sync' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'refund_deleted' ), 20, 2 );
	}
	/** Snapshot the hold at first completion; released and paid history stays released. */
	public static function available_at( $order, $row = null ) {
		if ( ! $order->has_status( 'completed' ) ) { return 0; }
		if ( $order->meta_exists( '_refm_available_at' ) ) { return max( 0, (int) $order->get_meta( '_refm_available_at' ) ); }
		$released = $row && ( in_array( $row->status, array( 'available', 'reserved', 'paid' ), true ) || (float) $row->paid_amount > 0 || $row->payout_id );
		$days = ! $released && 'delay' === REFM_Settings::get( 'release_mode' ) ? max( 1, min( 365, (int) REFM_Settings::get( 'release_days' ) ) ) : 0;
		$completed = $order->get_date_completed();
		$due = $days ? ( $completed ? $completed->getTimestamp() : time() ) + $days * DAY_IN_SECONDS : 0;
		$order->update_meta_data( '_refm_release_days', $days );
		$order->update_meta_data( '_refm_available_at', $due );
		return $due;
	}
	/** Use WooCommerce's durable scheduler, falling back to WordPress cron. */
	private static function schedule_release( $id, $due ) {
		$args = array( (int) $id );
		$when = max( time() + 1, (int) $due );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_get_scheduled_actions( array( 'hook' => 'refm_release_order', 'args' => $args, 'group' => 'refmora', 'status' => 'pending', 'per_page' => 1 ), 'ids' ) ) {
				if ( ! as_schedule_single_action( $when, 'refm_release_order', $args, 'refmora', true ) ) { self::retry( $id ); }
			}
		} elseif ( ! wp_next_scheduled( 'refm_release_order', $args ) && ! wp_schedule_single_event( $when, 'refm_release_order', $args ) ) { self::retry( $id ); }
	}
	public static function refund_deleted( $refund_id, $order_id ) { self::sync( $order_id ); }
	public static function customer_identity( $order ) {
		$email = strtolower( trim( $order->get_billing_email() ) );
		return array( 'customer_id' => (int) $order->get_customer_id(), 'customer_hash' => hash_hmac( 'sha256', $email ?: 'unknown-order:' . $order->get_id(), get_option( 'refm_identity_key', wp_salt( 'auth' ) ) ) );
	}
	/** Upgrade old commission identities in bounded batches, using order CRUD for HPOS. */
	private static function prepare_history() {
		global $wpdb;
		$table = REFM_Store::table( 'commissions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,order_id FROM %i WHERE customer_hash=\'\' AND (amount>0 OR paid_amount>0) ORDER BY id ASC LIMIT 100', $table ) );
		foreach ( $rows as $row ) {
			$order = wc_get_order( $row->order_id );
			$identity = $order ? self::customer_identity( $order ) : array( 'customer_id' => 0, 'customer_hash' => hash( 'sha256', 'deleted-order:' . $row->order_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			if ( false === $wpdb->update( $table, $identity, array( 'id' => $row->id ) ) ) { return false; }
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
		return ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE customer_hash=\'\' AND (amount>0 OR paid_amount>0) LIMIT 1', $table ) );
	}
	private static function retry( $id ) {
		// Only pending jobs suppress a retry; the currently running job must be able to reschedule itself.
		if ( function_exists( 'as_schedule_single_action' ) && ! as_get_scheduled_actions( array( 'hook' => 'refm_retry_order', 'args' => array( $id ), 'group' => 'refmora', 'status' => 'pending', 'per_page' => 1 ), 'ids' ) ) {
			as_schedule_single_action( time() + 30, 'refm_retry_order', array( $id ), 'refmora' );
		}
	}

	/** Pure calculation: fixed commission is per unit and prorated for monetary refunds. */
	public static function line_amount( $type, $rate, $quantity, $total, $refunded_total, $refunded_quantity ) {
		$remaining = max( 0, $total - $refunded_total );
		if ( 'percent' === $type ) { return $remaining * min( 100, max( 0, $rate ) ) / 100; }
		if ( 'fixed' === $type && $total > 0 ) {
			$units = min( max( 0, $quantity - abs( $refunded_quantity ) ), $quantity * $remaining / $total );
			return min( $remaining, max( 0, $rate ) * $units );
		}
		return 0;
	}
	/** Resolve only this order's items, including historical unsaved checkout keys. */
	public static function rate_item( $order, $key, $rate ) {
		$items = $order->get_items( 'line_item' );
		if ( isset( $items[ $key ] ) ) { return $items[ $key ]; }
		if ( preg_match( '/^new:line_items([0-9]+)$/', (string) $key, $match ) ) {
			$positions = array_values( $items ); $item = $positions[ (int) $match[1] ] ?? false;
			if ( $item instanceof WC_Order_Item_Product && isset( $rate['quantity'], $rate['total'] ) && is_numeric( $rate['quantity'] ) && is_numeric( $rate['total'] ) && abs( (float) $item->get_quantity() - (float) $rate['quantity'] ) < 0.000001 && abs( (float) $item->get_total() - (float) $rate['total'] ) < 0.000001 ) { return $item; }
		}
		return false;
	}
	public static function amount( $order ) {
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) { return 0; }
		$fx = $order->get_meta( '_refm_fx' );
		$factor = is_array( $fx ) && REFM_Currency::valid_rate( $fx['rate'] ?? null ) ? (float) $fx['rate'] : 1;
		$converted = is_array( $fx ) && (int) ( $fx['version'] ?? 1 ) >= 2;
		$amount = 0; $allocated = 0;
		foreach ( (array) $order->get_meta( '_refm_rates' ) as $key => $rate ) {
			$item = self::rate_item( $order, $key, $rate );
			$id = $item ? $item->get_id() : 0;
			$refund = $id ? (float) $order->get_total_refunded_for_item( $id ) : 0;
			$allocated += $refund;
			$taxes = $item ? $item->get_taxes() : array();
			foreach ( array_keys( $taxes['total'] ?? array() ) as $tax_id ) { $allocated += (float) $order->get_tax_refunded_for_item( $id, $tax_id ); }
			$multiplier = $converted ? $factor : 1;
			$amount += self::line_amount( $rate['type'], $rate['rate'], $rate['quantity'], (float) $rate['total'] * $multiplier, $refund * $multiplier, $id ? $order->get_qty_refunded_for_item( $id ) : 0 );
		}
		$unallocated = max( 0, (float) $order->get_total_refunded() - $allocated );
		if ( $unallocated && $order->get_total() > $allocated ) { $amount *= max( 0, 1 - $unallocated / ( (float) $order->get_total() - $allocated ) ); }
		$precision = is_array( $fx ) ? max( 0, min( 6, (int) ( $fx['precision'] ?? 2 ) ) ) : REFM_Currency::precision( $order->get_meta( '_refm_currency' ) ?: $order->get_currency() );
		return round( $amount, $precision );
	}
	/** Abandoned checkout placeholders are not submitted customer orders. */
	public static function incomplete_order( $order ) {
		if ( $order->has_status( array( 'incomplete', 'checkout-draft', 'auto-draft' ) ) || (int) $order->get_meta( '_cs_io_converted_to' ) > 0 ) { return true; }
		if ( ! $order->has_status( array( 'pending', 'trash' ) ) ) { return false; }
		foreach ( array( '_incomplete_created', '_incomplete_session_id', '_incomplete_source', '_cs_io_token_hash' ) as $key ) { if ( $order->meta_exists( $key ) ) { return true; } }
		return false;
	}
	public static function placeholder_removed( $id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $id );
		if ( $order && ! $order instanceof WC_Order_Refund && self::incomplete_order( $order ) ) { self::sync( $id ); }
	}
	/** Remove only erroneous pending entries that have never entered a payout. */
	private static function discard_placeholder_row( $row ) {
		global $wpdb;
		if ( $row && 'pending' === $row->status && 0 === (int) $row->payout_id && 0.0 === (float) $row->paid_amount ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			REFM_Store::must( $wpdb->delete( REFM_Store::table( 'commissions' ), array( 'id' => $row->id, 'status' => 'pending', 'payout_id' => 0, 'paid_amount' => 0 ) ) );
		}
	}
	public static function sync( $order_or_id, $only_missing = false ) {
		global $wpdb;
		$only_missing = true === $only_missing;
		$id = is_object( $order_or_id ) ? $order_or_id->get_id() : absint( $order_or_id );
		$order = wc_get_order( $id );
		if ( ! $order || $order instanceof WC_Order_Refund ) { return; }
		if ( ! REFM_Data::order_is_current( $order ) ) { return; }
		if ( self::incomplete_order( $order ) ) {
			$table = REFM_Store::table( 'commissions' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $table, $id ) );
			if ( ! $row ) { return; }
			return REFM_Store::locked( (int) $row->affiliate_id, function () use ( $wpdb, $id, $table ) {
				$current = wc_get_order( $id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
				if ( $current && self::incomplete_order( $current ) ) { self::discard_placeholder_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $table, $id ) ) ); }
			} );
		}
		$affiliate = (int) $order->get_meta( '_refm_affiliate' );
		if ( ! $affiliate || ! $order->get_meta( '_refm_rates' ) ) { return; }
		$currency = $order->get_meta( '_refm_currency' );

		$policy = $order->get_meta( '_refm_purchase_policy' ) ?: 'multiple';
		if ( 'once' === $policy && ! self::prepare_history() ) {
			self::retry( $id );
			return new WP_Error( 'history', __( 'Previous customer commissions are being indexed. This order will be retried automatically.', 'refmora' ) );
		}
		$identity = self::customer_identity( $order );
		$locks = array( 'customer-email:' . $identity['customer_hash'] );
		if ( $identity['customer_id'] ) { $locks[] = 'customer-account:' . $identity['customer_id']; }
		$result = REFM_Store::locked( $affiliate, function () use ( $wpdb, $id, $affiliate, $currency, $policy, $identity, $only_missing ) {
			// Reload under the lock so an older order-status callback cannot overwrite a refund.
			$order = wc_get_order( $id );
			$table = REFM_Store::table( 'commissions' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $table, $id ) );
			if ( ! $order ) { return; }
			if ( ! REFM_Data::order_is_current( $order ) ) { return; }
			if ( self::incomplete_order( $order ) ) { self::discard_placeholder_row( $row ); return; }
			if ( $only_missing && $row ) { return new WP_Error( 'exists', __( 'This order already has a commission record. Its financial history was left unchanged.', 'refmora' ) ); }
			// Existing same-currency records keep their original calculation semantics.
			$legacy = $row && ! $order->get_meta( '_refm_calculation_version' ) && $currency === $order->get_currency();
			$fx = REFM_Currency::snapshot( $order, $legacy );
			if ( is_wp_error( $fx ) ) { return $fx; }
			$due = self::available_at( $order, $row );
			$order->save_meta_data();
			$amount = self::amount( $order );
			if ( ! is_finite( $amount ) || $amount > 99999999999999 ) { return new WP_Error( 'currency', __( 'Converted commission exceeds the supported amount. Review the exchange rate.', 'refmora' ) ); }
			$status = $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ? 'rejected' : ( $order->has_status( 'completed' ) && $due <= time() ? 'available' : 'pending' );
			$eligibility = $row ? $row->eligibility : 'eligible';
			if ( 'once' === $policy ) {
				if ( 'claimed' === $eligibility && $amount <= 0 && ( ! $row || (float) $row->paid_amount <= 0 ) ) { $eligibility = 'eligible'; }
				if ( 'eligible' === $eligibility && $amount > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
					$previous = $wpdb->get_var( $wpdb->prepare(
						'SELECT id FROM %i WHERE order_id<>%d AND (customer_hash=%s OR (%d>0 AND customer_id=%d)) AND ((amount>0 AND status<>\'rejected\') OR paid_amount>0) LIMIT 1',
						$table, $id, $identity['customer_hash'], $identity['customer_id'], $identity['customer_id']
					) );
					$eligibility = $previous ? 'repeat' : 'claimed';
				}
				if ( 'repeat' === $eligibility ) { $amount = 0; $status = 'rejected'; }
			}
			if ( $row && $row->payout_id && ( abs( $amount - (float) $row->amount ) > 0.000001 || 'available' !== $status ) ) {
				REFM_Payouts::release( $row->payout_id, __( 'Order changed or refunded. Please request the updated balance again.', 'refmora' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $table, $id ) );
			}
			if ( $row && $row->payout_id ) { $status = 'reserved'; }
			elseif ( $row && (float) $row->paid_amount > 0 ) { $status = 'paid'; }
			$data = array( 'amount' => $amount, 'status' => $status, 'eligibility' => $eligibility, 'updated_at' => current_time( 'mysql', true ) );
			if ( ! $row || ! $row->customer_hash ) { $data += $identity; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			if ( $row ) { REFM_Store::must( $wpdb->update( $table, $data, array( 'id' => $row->id ) ) ); }
			else {
				$data += array( 'order_id' => $id, 'affiliate_id' => $affiliate, 'currency' => $currency, 'created_at' => current_time( 'mysql', true ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
				REFM_Store::must( $wpdb->insert( $table, $data ) );
			}
			if ( 'available' === $status && $amount > 0 && ( ! $row || 'pending' === $row->status ) ) {
				REFM_Emails::queue( 'commission_affiliate', $affiliate, array( 'order_id' => $id, 'amount' => $currency . ' ' . wc_format_decimal( $amount, REFM_Currency::precision( $currency ) ), 'status' => 'available' ) );
			}
			return true;
		}, $locks );
		if ( is_wp_error( $result ) ) {
			if ( 'currency' === $result->get_error_code() ) { $order = wc_get_order( $id ); REFM_Currency::issue( $order, $result ); }
			elseif ( 'exists' !== $result->get_error_code() ) { self::retry( $id ); }
		}
		if ( true === $result ) {
			$order = wc_get_order( $id );
			$due = (int) $order->get_meta( '_refm_available_at' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			if ( $due > time() && $order->has_status( 'completed' ) && 'pending' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d', REFM_Store::table( 'commissions' ), $id ) ) ) { self::schedule_release( $id, $due ); }
		}
		return $result;
	}
}
add_action( 'refm_retry_order', array( 'REFM_Commissions', 'sync' ) );
