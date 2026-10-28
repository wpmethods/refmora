<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Tracking {
	const COOKIE = 'refm_referral';
	const CLICK_COOKIE = 'refm_click';
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'capture' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'attach' ), 20 );
		add_action( 'user_register', array( __CLASS__, 'register_customer' ), 20 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'register_customer' ), 20 );
		add_action( 'deleted_user', array( __CLASS__, 'delete_customer' ) );
	}
	public static function active( $id ) {
		return $id && get_userdata( $id ) && 'approved' === REFM_Affiliates::status( $id ) && 'yes' !== get_user_meta( $id, '_refm_blocked', true );
	}
	public static function token( $id ) {
		$token = get_user_meta( $id, '_refm_token', true );
		if ( ! $token ) {
			$token = strtolower( wp_generate_password( 32, false, false ) );
			add_user_meta( $id, '_refm_token', $token, true );
			$token = get_user_meta( $id, '_refm_token', true );
		}
		return $token;
	}
	public static function payload( $id, $expires, $epoch = null ) {
		$data = absint( $id ) . '.' . absint( $expires );
		$epoch = null === $epoch ? REFM_Data::affiliate_epoch( $id ) : $epoch;
		$key = REFM_Data::cookie_key();
		if ( $epoch > 0 ) {
			$key = hash_hmac( 'sha256', 'affiliate:' . absint( $id ) . ':' . $epoch, $key );
		}
		return $data . '.' . hash_hmac( 'sha256', $data, $key );
	}
	public static function referral() {
		return REFM_Settings::program_enabled() ? self::cookie_id( self::COOKIE ) : 0;
	}
	private static function cookie_id( $name ) {
		$value = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		$parts = explode( '.', $value );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || (int) $parts[1] < time() ) { return 0; }
		if ( ! hash_equals( self::payload( $parts[0], $parts[1] ), $value ) ) { return 0; }
		return self::active( (int) $parts[0] ) ? (int) $parts[0] : 0;
	}
	public static function capture() {
		$key = REFM_Settings::referral_request_key();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public referral URLs must work for guests without expiring nonces; token format, affiliate eligibility and consent are checked before attribution.
		if ( ! REFM_Settings::program_enabled() || ! $key || ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) || is_admin() || headers_sent() ) { return; }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public referral URLs must work for guests without expiring nonces; token format, affiliate eligibility and consent are checked before attribution.
		$token = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		if ( ! preg_match( '/^[a-z0-9]{32}$/', $token ) ) { return; }
		// phpcs:ignore WordPress.DB.SlowDBQuery -- Exact validated token lookup returns one ID with no count; WordPress caches the query, and eligibility is checked separately.
		$users = get_users( array( 'count_total' => false, 'cache_results' => true, 'meta_key' => '_refm_token', 'meta_value' => $token, 'number' => 1, 'fields' => 'ID' ) );
		$id = $users ? (int) $users[0] : 0;
		$epoch = REFM_Data::affiliate_epoch( $id );
		wp_cache_delete( $id, 'user_meta' );
		if ( $epoch < 0 || ! self::active( $id ) || $id === get_current_user_id() ) { return; }
		// Consent integrations can veto tracking before a cookie is created.
		if ( ! apply_filters( 'refm_tracking_allowed', true, $id ) ) { return; }
		self::record_click( $id );
		if ( 'first' === REFM_Settings::get( 'attribution' ) && self::referral() ) { return; }
		$expires = time() + DAY_IN_SECONDS * REFM_Settings::get( 'cookie_days' );
		$value = self::payload( $id, $expires, $epoch );
		setcookie( self::COOKIE, $value, array( 'expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$_COOKIE[ self::COOKIE ] = $value;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This compatibility name is defined by WordPress/LiteSpeed and must match the external cache integration API.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
	}
	/** Compatibility entry point: one URL counter supplies every current click report. */
	public static function record_click( $id ) {
		return REFM_Refer_Links::record( $id );
	}
	public static function register_customer( $customer_id ) {
		global $wpdb;
		$id = self::referral();
		$customer = get_userdata( $customer_id );
		$affiliate = $id ? get_userdata( $id ) : false;
		if ( ! REFM_Settings::program_enabled() || ! $customer || ! $affiliate || $id === (int) $customer_id || ! array_intersect( array( 'customer', 'subscriber' ), $customer->roles ) || strtolower( $customer->user_email ) === strtolower( $affiliate->user_email ) || ! apply_filters( 'refm_tracking_allowed', true, $id ) ) { return; }
		$table = REFM_Store::table( 'customers' );
		// Recheck the signed cookie under the deletion lock before attributing a new customer.
		REFM_Store::locked( $id, static function () use ( $wpdb, $table, $customer_id, $id ) {
			wp_cache_delete( $id, 'user_meta' );
			if ( ! self::active( $id ) || self::referral() !== $id ) {
				return new WP_Error( 'affiliate', __( 'Referral tracking is unavailable.', 'refmora' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Unique customer key prevents duplicate attribution; serialization prevents a concurrent affiliate deletion from recreating history.
			REFM_Store::must( $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (customer_id,affiliate_id,created_at) VALUES (%d,%d,%s)', $table, $customer_id, $id, current_time( 'mysql', true ) ) ) );
		} );
	}
	public static function delete_customer( $customer_id ) {
		global $wpdb;
		$table = REFM_Store::table( 'customers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom aggregate/referral table has no WordPress CRUD API; the statement must read or update current counters.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE customer_id=%d OR affiliate_id=%d', $table, $customer_id, $customer_id ) );
		REFM_Refer_Links::erase_counters( $customer_id );
	}
	public static function attach( $order ) {
		// Store API can attribute a draft before submission; sync still waits for a real order.
		if ( REFM_Commissions::incomplete_order( $order ) && ! $order->has_status( 'checkout-draft' ) ) { return; }
		if ( ! REFM_Settings::program_enabled() || $order->get_meta( '_refm_affiliate' ) ) { return; }
		$id = self::referral();
		$user = $id ? get_userdata( $id ) : false;
		if ( ! $user || $id === (int) $order->get_customer_id() || $id === get_current_user_id() || strtolower( $user->user_email ) === strtolower( $order->get_billing_email() ) ) { return; }
		$state = REFM_Data::state();
		$epoch = REFM_Data::affiliate_epoch( $id );
		wp_cache_delete( $id, 'user_meta' );
		if ( ! is_array( $state ) || ! empty( $state['clearing'] ) || $epoch < 0 || ! self::active( $id ) || self::referral() !== $id ) { return; }
		$order->update_meta_data( '_refm_affiliate', $id );
		$order->update_meta_data( '_refm_affiliate_epoch', $epoch );
		$order->update_meta_data( '_refm_generation', (int) $state['generation'] );
		$order->update_meta_data( '_refm_currency', REFM_Settings::get( 'currency' ) );
		$order->update_meta_data( '_refm_purchase_policy', REFM_Settings::get( 'purchase_policy' ) );
		$rates = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) { continue; }
			if ( $product->is_type( 'variation' ) ) { $product = wc_get_product( $product->get_parent_id() ); }
			$type = $product ? $product->get_meta( '_refm_type' ) : '';
			$rates[ $item_id ] = array( 'title' => REFM_Account::item_title( $item ), 'type' => $type ?: REFM_Settings::get( 'commission_type' ), 'rate' => $type ? (float) $product->get_meta( '_refm_rate' ) : (float) REFM_Settings::get( 'commission_rate' ), 'quantity' => (float) $item->get_quantity(), 'total' => (float) $item->get_total() );
		}
		$order->update_meta_data( '_refm_rates', $rates );
		$order->update_meta_data( '_refm_calculation_version', 2 );
	}
}
