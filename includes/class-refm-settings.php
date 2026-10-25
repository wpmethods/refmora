<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Settings {
	/** Store accounting currency, independent of the visitor's currency-switcher selection. */
	public static function base_currency() {
		$currency = get_option( 'woocommerce_currency', 'USD' );
		$currency = is_string( $currency ) ? strtoupper( trim( $currency ) ) : '';
		$known = get_woocommerce_currencies();
		return isset( $known[ $currency ] ) ? $currency : 'USD';
	}
	/** Keep URL names separate from WooCommerce's stable internal endpoint key. */
	public static function url_name( $value, $kind ) {
		$default = 'account_slug' === $kind ? 'refmora' : 'refm_ref';
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		$pattern = 'account_slug' === $kind ? '/^[a-z][a-z0-9-]{0,59}$/' : '/^[a-z][a-z0-9_-]{0,59}$/';
		$reserved = array( 'action', 'security', 'nonce', '_wpnonce', 'wc-ajax', 'wc-api', 'rest_route', 'key', 'token', 'password', 'access_token', 'order_key', 'pay_for_order', 'wp-admin', 'wp-json', 'wp-login', 'feed', 'embed', 'dashboard', 'orders', 'view-order', 'downloads', 'edit-account', 'edit-address', 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'lost-password', 'customer-logout', 'order-pay', 'order-received' );
		if ( class_exists( 'WP' ) ) { $reserved = array_merge( $reserved, ( new WP() )->public_query_vars ); }
		foreach ( array( 'orders', 'view_order', 'downloads', 'edit_account', 'edit_address', 'payment_methods', 'add_payment_method', 'delete_payment_method', 'set_default_payment_method', 'lost_password', 'customer_logout', 'pay', 'order_received' ) as $endpoint ) { $reserved[] = get_option( 'woocommerce_myaccount_' . $endpoint . '_endpoint', '' ); }
		foreach ( array( 'woocommerce_checkout_pay_endpoint', 'woocommerce_checkout_order_received_endpoint', 'woocommerce_logout_endpoint' ) as $option ) { $reserved[] = get_option( $option, '' ); }
		return preg_match( $pattern, $value ) && ! in_array( $value, $reserved, true ) ? $value : $default;
	}
	public static function referral_parameter() {
		return self::url_name( REFM_Security::value( (array) get_option( 'refm_settings', array() ), 'referral_parameter', 'refm_ref' ), 'referral_parameter' );
	}
	public static function account_slug() {
		return self::url_name( REFM_Security::value( (array) get_option( 'refm_settings', array() ), 'account_slug', 'refmora' ), 'account_slug' );
	}
	public static function referral_keys() { return array_values( array_unique( array( self::referral_parameter(), 'refm_ref' ) ) ); }
	public static function referral_request_key() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public referral URLs must work for guests without expiring nonces; token format, affiliate eligibility and consent are checked before attribution.
		foreach ( self::referral_keys() as $key ) { if ( isset( $_GET[ $key ] ) ) { return $key; } }
		return '';
	}
	public static function defaults() {
		return array( 'enabled' => 'yes', 'approval_required' => 'no', 'terms_content' => '', 'join_title' => __( 'Turn recommendations into rewards', 'refmora' ), 'join_description' => __( 'Join our affiliate program and earn commissions when someone buys through your personal link.', 'refmora' ), 'join_button_text' => __( 'Join affiliate program', 'refmora' ), 'join_show_rate' => 'no', 'join_url_enabled' => 'no', 'join_promotion_enabled' => 'no', 'release_mode' => 'completed', 'release_days' => 7, 'commission_type' => 'percent', 'commission_rate' => '10', 'minimum' => '50', 'currency' => self::base_currency(), 'exchange_source' => 'same', 'exchange_rates' => '', 'referral_parameter' => 'refm_ref', 'account_slug' => 'refmora', 'cookie_days' => 30, 'attribution' => 'first', 'purchase_policy' => 'multiple', 'payout_mode' => 'manual', 'method_selection' => 'multiple', 'methods' => array( array( 'id' => 'bank', 'name' => 'Bank transfer', 'instructions' => 'Enter the account holder, bank name and account details. Do not provide passwords or card details.', 'active' => 'yes' ) ) );
	}
	/** Effective program state; keep the merchant's saved preference intact. */
	public static function program_enabled() {
		return 'yes' === self::get( 'enabled' );
	}
	public static function stored() {
		$settings = wp_parse_args( get_option( 'refm_settings', array() ), self::defaults() );
		// Ignore the former plugin currency override, including older settings backups.
		$settings['currency'] = self::base_currency();
		$settings['referral_parameter'] = self::referral_parameter();
		$settings['account_slug'] = self::account_slug();
		return $settings;
	}
	public static function get( $key = null ) {
		$settings = self::stored();
		$settings['exchange_source'] = 'same'; $settings['exchange_rates'] = '';
		$settings = apply_filters( 'refm_effective_settings', $settings );
		return null === $key ? $settings : ( $settings[ $key ] ?? null );
	}
	/** Canonical content and version shared by display and enrollment validation. */
	public static function terms() {
		return trim( wp_kses_post( REFM_Security::value( self::get(), 'terms_content' ) ) );
	}
	public static function terms_version() {
		$terms = self::terms();
		return '' === $terms ? '' : hash( 'sha256', $terms );
	}
	public static function init() {
		add_action( 'update_option_refm_settings', array( 'REFM_Account', 'settings_changed' ), 10, 2 );
		add_action( 'admin_post_refm_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
	}
	public static function methods() {
		if ( 'manual' !== self::get( 'payout_mode' ) ) {
			return array();
		}
		$methods = array_values( array_filter( self::get( 'methods' ), function ( $method ) { return 'yes' === $method['active']; } ) );
		$methods = array_merge( $methods, REFM_Gateways::methods() );
		return 'single' === self::get( 'method_selection' ) ? array_slice( $methods, 0, 1 ) : $methods;
	}
	public static function clean( $input ) {
		$input = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean = $defaults;
		foreach ( array( 'paypal_icon', 'stripe_icon' ) as $key ) {
			$clean[ $key ] = esc_url_raw( substr( REFM_Security::value( $input, $key, (string) self::get( $key ) ), 0, 2048 ), array( 'http', 'https' ) );
		}
		foreach ( array( 'referral_parameter', 'account_slug' ) as $key ) { $clean[ $key ] = self::url_name( REFM_Security::value( $input, $key, $defaults[ $key ] ), $key ); }
		if ( function_exists( 'WC' ) && WC()->query ) {
			foreach ( WC()->query->get_query_vars() as $key => $slug ) {
				if ( 'refmora' === $key ) { continue; }
				foreach ( array( 'referral_parameter', 'account_slug' ) as $name ) { if ( in_array( $clean[ $name ], array( $key, $slug ), true ) ) { $clean[ $name ] = $defaults[ $name ]; } }
			}
		}
		foreach ( array( 'join_title', 'join_button_text' ) as $key ) {
			$value = mb_substr( sanitize_text_field( REFM_Security::value( $input, $key, $defaults[ $key ] ) ), 0, 180 );
			$clean[ $key ] = '' !== trim( $value ) ? $value : $defaults[ $key ];
		}
		$clean['join_description'] = mb_substr( sanitize_textarea_field( REFM_Security::value( $input, 'join_description', $defaults['join_description'] ) ), 0, 3000 );
		$clean['terms_content'] = wp_kses_post( mb_substr( REFM_Security::value( $input, 'terms_content' ), 0, 50000 ) );
		$clean['enabled'] = 'yes' === REFM_Security::value( $input, 'enabled' ) ? 'yes' : 'no';
		foreach ( array( 'join_url_enabled' => array( 'yes', 'no' ), 'join_promotion_enabled' => array( 'yes', 'no' ), 'join_show_rate' => array( 'yes', 'no' ), 'release_mode' => array( 'completed', 'delay' ), 'exchange_source' => array( 'auto', 'fox', 'curcy', 'manual', 'same' ), 'approval_required' => array( 'yes', 'no' ), 'commission_type' => array( 'percent', 'fixed' ), 'attribution' => array( 'first', 'last' ), 'purchase_policy' => array( 'once', 'multiple' ), 'payout_mode' => array( 'manual', 'disabled' ), 'method_selection' => array( 'single', 'multiple' ) ) as $key => $allowed ) {
			$clean[ $key ] = in_array( $input[ $key ] ?? '', $allowed, true ) ? $input[ $key ] : $defaults[ $key ];
		}
		$clean['commission_rate'] = wc_format_decimal( max( 0, min( 1000000, (float) wc_format_decimal( REFM_Security::value( $input, 'commission_rate', '10' ) ) ) ), 6 );
		if ( 'percent' === $clean['commission_type'] ) {
			$clean['commission_rate'] = min( 100, (float) $clean['commission_rate'] );
		}
		$clean['minimum'] = wc_format_decimal( max( 0.01, min( 1000000, (float) wc_format_decimal( REFM_Security::value( $input, 'minimum', '50' ) ) ) ), 6 );
		$clean['currency'] = self::base_currency();
		$clean['exchange_rates'] = REFM_Currency::clean_rates( REFM_Security::value( $input, 'exchange_rates' ) );
		$clean['release_days'] = max( 1, min( 365, absint( REFM_Security::value( $input, 'release_days', '7' ) ) ) );
		$clean['cookie_days'] = max( 1, min( 365, absint( REFM_Security::value( $input, 'cookie_days', '30' ) ) ) );
		$clean['methods'] = array();
		$ids = array();
		foreach ( (array) ( $input['methods'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$name = substr( sanitize_text_field( REFM_Security::value( $row, 'name' ) ), 0, 120 );
			if ( '' === $name ) { continue; }
			$id = sanitize_key( REFM_Security::value( $row, 'id' ) );
			if ( ! $id || in_array( $id, array( 'refm_paypal', 'refm_stripe' ), true ) || isset( $ids[ $id ] ) ) { $id = wp_generate_uuid4(); }
			$ids[ $id ] = true;
			$clean['methods'][] = array( 'id' => $id, 'name' => $name, 'icon' => esc_url_raw( substr( REFM_Security::value( $row, 'icon' ), 0, 2048 ), array( 'http', 'https' ) ), 'instructions' => substr( sanitize_textarea_field( REFM_Security::value( $row, 'instructions' ) ), 0, 2000 ), 'active' => 'yes' === REFM_Security::value( $row, 'active' ) ? 'yes' : 'no' );
		}
		if ( 'single' === $clean['method_selection'] ) {
			$found = false;
			foreach ( $clean['methods'] as &$method ) {
				if ( 'yes' === $method['active'] ) { $method['active'] = $found ? 'no' : 'yes'; $found = true; }
			}
			unset( $method );
		}
		// Keep the add-on's saved preferences when its fields are absent.
		$stored = self::stored();
		foreach ( array( 'exchange_source', 'exchange_rates' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) { $clean[ $key ] = $stored[ $key ]; }
		}
		return $clean;
	}
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'refmora' ), '', array( 'response' => 403 ) ); }
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) { wp_die( esc_html__( 'Use the settings form to make changes.', 'refmora' ), '', array( 'response' => 405 ) ); }
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) { wp_die( esc_html__( 'Your session expired. Refresh and try again.', 'refmora' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'refm_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- check_admin_referer() and capabilities are checked before input is passed directly to its typed schema sanitizer. The dedicated clean() schema sanitizes each permitted setting using typed, text, HTML, email, URL or credential validation; blanket text sanitization would corrupt structured settings.
		$input = self::clean( isset( $_POST['refm'] ) && is_array( $_POST['refm'] ) ? wp_unslash( $_POST['refm'] ) : array() );
		if ( isset( $_POST['refm_gateway'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only administrators can configure automatic payout credentials.', 'refmora' ), '', array( 'response' => 403 ) ); }
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes. The dedicated clean() schema sanitizes each permitted setting using typed, text, HTML, email, URL or credential validation; blanket text sanitization would corrupt structured settings.
			$gateway_input = isset( $_POST['refm_gateway'] ) && is_array( $_POST['refm_gateway'] ) ? map_deep( wp_unslash( $_POST['refm_gateway'] ), 'sanitize_text_field' ) : array();
			$gateways = REFM_Gateways::clean( $gateway_input );
			if ( is_wp_error( $gateways ) ) { wp_safe_redirect( add_query_arg( array( 'page' => 'refmora', 'tab' => 'settings', 'refm_error' => 1, 'refm_message' => $gateways->get_error_message() ), admin_url( 'admin.php' ) ) ); exit; }
			update_option( REFM_Gateways::OPTION, $gateways, false );
		}
		update_option( 'refm_settings', $input, false );
		if ( isset( $gateways ) ) {
			$result = REFM_Gateways::verify_paypal();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes. Pro setup validates mode and whsec_ secrets against its schema; only authorized, nonce-verified administrators reach this call.
			if ( ! is_wp_error( $result ) ) { $result = REFM_Stripe::setup( $gateway_input ); }
			if ( is_wp_error( $result ) ) { wp_safe_redirect( add_query_arg( array( 'page' => 'refmora', 'tab' => 'settings', 'saved' => 1, 'refm_error' => 1, 'refm_message' => $result->get_error_message() ), admin_url( 'admin.php' ) ) ); exit; }
		}
		wp_safe_redirect( admin_url( 'admin.php?page=refmora&tab=settings&saved=1' ) );
		exit;
	}
	public static function product_fields() {
		echo '<div class="options_group">';
		woocommerce_wp_select( array( 'id' => '_refm_type', 'label' => __( 'Affiliate commission', 'refmora' ), 'options' => array( '' => __( 'Use global setting', 'refmora' ), 'percent' => __( 'Percentage', 'refmora' ), 'fixed' => __( 'Fixed per unit', 'refmora' ), 'disabled' => __( 'No commission', 'refmora' ) ) ) );
		woocommerce_wp_text_input( array( 'id' => '_refm_rate', 'label' => __( 'Affiliate rate / amount', 'refmora' ), 'type' => 'number', 'custom_attributes' => array( 'min' => '0', 'step' => '0.000001' ), 'desc_tip' => true, 'description' => __( 'Percentage of the item total after discounts, excluding tax, or fixed amount per unit in the WooCommerce base currency. Applies to all variations.', 'refmora' ) ) );
		echo '</div>';
	}
	public static function save_product( $product ) {
		if ( ! current_user_can( 'edit_post', $product->get_id() ) ) { return; }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read and sanitize the product nonce solely for immediate wp_verify_nonce validation before any metadata change.
		$nonce = sanitize_text_field( ( isset( $_POST[ 'woocommerce_meta_nonce' ] ) && is_scalar( $_POST[ 'woocommerce_meta_nonce' ] ) ? wp_unslash( $_POST[ 'woocommerce_meta_nonce' ] ) : '' ) );
		if ( ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) { return; }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called from woocommerce_admin_process_product_object after WooCommerce validates its product-save nonce; edit_post capability is also required.
		$type = sanitize_key( ( isset( $_POST[ '_refm_type' ] ) && is_scalar( $_POST[ '_refm_type' ] ) ? wp_unslash( $_POST[ '_refm_type' ] ) : '' ) );
		$type = in_array( $type, array( 'percent', 'fixed', 'disabled' ), true ) ? $type : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called from woocommerce_admin_process_product_object after WooCommerce validates its product-save nonce; edit_post capability is also required.
		$rate = max( 0, min( 1000000, (float) wc_format_decimal( sanitize_text_field( ( isset( $_POST[ '_refm_rate' ] ) && is_scalar( $_POST[ '_refm_rate' ] ) ? wp_unslash( $_POST[ '_refm_rate' ] ) : '0' ) ) ) ) );
		$product->update_meta_data( '_refm_type', $type );
		$product->update_meta_data( '_refm_rate', 'percent' === $type ? min( 100, $rate ) : $rate );
	}
}
