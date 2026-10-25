<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Account {
	const PAGE_SIZE = 5;
	public static function endpoint() {
		global $wp_rewrite;
		foreach ( array_unique( array( 'refmora', REFM_Settings::account_slug() ) ) as $slug ) {
			if ( ! in_array( $slug, array_column( $wp_rewrite->endpoints, 1 ), true ) ) { add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES ); }
		}
	}
	public static function settings_changed( $old, $new ) {
		$previous = REFM_Settings::url_name( REFM_Security::value( (array) $old, 'account_slug', 'refmora' ), 'account_slug' );
		$current = REFM_Settings::url_name( REFM_Security::value( (array) $new, 'account_slug', 'refmora' ), 'account_slug' );
		if ( $previous === $current ) { return; }
		global $wp_rewrite;
		if ( 'refmora' !== $previous ) { $wp_rewrite->endpoints = array_values( array_filter( $wp_rewrite->endpoints, function ( $endpoint ) use ( $previous ) { return $endpoint[1] !== $previous; } ) ); }
		self::endpoint();
		flush_rewrite_rules( false );
	}
	/** Normalize the default URL alias so WooCommerce conditionals also recognize it. */
	public static function parse_request( $request ) {
		$slug = REFM_Settings::account_slug();
		if ( 'refmora' !== $slug && isset( $request->query_vars['refmora'] ) && ! isset( $request->query_vars[ $slug ] ) ) { $request->query_vars[ $slug ] = $request->query_vars['refmora']; }
	}
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), 1 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_account_refmora_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_refmora_title', function () { return __( 'Affiliate Area', 'refmora' ); } );
		add_action( 'template_redirect', array( __CLASS__, 'submit' ) );
		add_action( 'wp_enqueue_scripts', function () {
			if ( is_account_page() && is_wc_endpoint_url( 'refmora' ) ) {
				wp_enqueue_style( 'refm-account', REFM_URL . 'assets/refmora.css', array(), (string) filemtime( REFM_PATH . 'assets/refmora.css' ) );
				wp_enqueue_style( 'refm-panel', REFM_URL . 'assets/panel.css', array( 'refm-account' ), (string) filemtime( REFM_PATH . 'assets/panel.css' ) );
				REFM_Design::enqueue( 'refm-panel' );
				wp_enqueue_script( 'refm-account', REFM_URL . 'assets/account.js', array(), (string) filemtime( REFM_PATH . 'assets/account.js' ), true );
				wp_enqueue_script( 'refm-refer-links', REFM_URL . 'assets/refer-links.js', array(), (string) filemtime( REFM_PATH . 'assets/refer-links.js' ), true );
			}
		} );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}
	public static function query_vars( $vars ) {
		// WooCommerce conditionals, endpoint titles and URLs use its own registry.
		$vars['refmora'] = REFM_Settings::account_slug();
		return $vars;
	}
	public static function menu( $items ) {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['refmora'] = __( 'Affiliate Area', 'refmora' );
		if ( $logout ) { $items['customer-logout'] = $logout; }
		return $items;
	}
	public static function submit() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) { return; }
		if ( ! isset( $_POST['refm_action'] ) || ! is_scalar( $_POST['refm_action'] ) || ! is_account_page() || ! is_wc_endpoint_url( 'refmora' ) ) { return; }
		if ( ! is_user_logged_in() ) { return; }
		$nonce = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'refm_account' ) ) { wc_add_notice( __( 'Your session expired. Please try again.', 'refmora' ), 'error' ); }
		else {
			$id = get_current_user_id();
			$action = sanitize_key( wp_unslash( $_POST['refm_action'] ) );
			if ( 'join' === $action ) {
				$result = REFM_Affiliates::join( $id, 'yes' === sanitize_text_field( ( isset( $_POST[ 'refm_accept_terms' ] ) && is_scalar( $_POST[ 'refm_accept_terms' ] ) ? wp_unslash( $_POST[ 'refm_accept_terms' ] ) : '' ) ), sanitize_text_field( ( isset( $_POST[ 'refm_terms_version' ] ) && is_scalar( $_POST[ 'refm_terms_version' ] ) ? wp_unslash( $_POST[ 'refm_terms_version' ] ) : '' ) ), array( 'website' => isset( $_POST['refm_join']['website'] ) && is_string( $_POST['refm_join']['website'] ) ? esc_url_raw( wp_unslash( $_POST['refm_join']['website'] ), array( 'http', 'https' ) ) : '', 'promotion' => isset( $_POST['refm_join']['promotion'] ) && is_string( $_POST['refm_join']['promotion'] ) ? sanitize_textarea_field( wp_unslash( $_POST['refm_join']['promotion'] ) ) : '' ) );
				$messages = array( 'approved' => __( 'Your affiliate account is ready. Start sharing your referral link!', 'refmora' ), 'pending' => __( 'Your affiliate application is awaiting administrator approval.', 'refmora' ), 'rejected' => __( 'Your affiliate application was rejected. Please contact the store.', 'refmora' ) );
				wc_add_notice( is_wp_error( $result ) ? $result->get_error_message() : ( $messages[ $result ] ?? '' ), is_wp_error( $result ) || 'rejected' === $result ? 'error' : 'success' );
			} elseif ( 'stripe_connect' === $action ) {
				$result = REFM_Gateways::connect( sanitize_text_field( ( isset( $_POST[ 'stripe_country' ] ) && is_scalar( $_POST[ 'stripe_country' ] ) ? wp_unslash( $_POST[ 'stripe_country' ] ) : '' ) ) ); if ( is_wp_error( $result ) ) { wc_add_notice( $result->get_error_message(), 'error' ); }
			} elseif ( 'withdraw' === $action ) {
				$method = isset( $_POST['method'] ) && is_scalar( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
				$details = isset( $_POST['details'] ) && is_scalar( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
				$result = REFM_Payouts::request( $id, $method, $details );
				wc_add_notice( is_wp_error( $result ) ? $result->get_error_message() : __( 'Withdrawal requested. You can follow its status below.', 'refmora' ), is_wp_error( $result ) ? 'error' : 'success' );
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'refmora' ) );
		exit;
	}
	public static function render() {
		global $wpdb;
		$id = get_current_user_id(); if ( ! $id ) { return; }
		REFM_Cache::no_cache( 'Refmora customer dashboard' );
		$user = wp_get_current_user(); $status = REFM_Affiliates::status( $id );
		$active = REFM_Sharing::allowed( $id ); $currency = REFM_Settings::get( 'currency' );
		$name = $user->first_name ?: $user->display_name;
		/* translators: %s: affiliate display name. */
		echo '<div class="refm refm-account"><header class="refm-account-hero"><div><span class="refm-eyebrow">' . esc_html__( 'YOUR PARTNER SPACE', 'refmora' ) . '</span><h2>' . esc_html__( 'Affiliate Area', 'refmora' ) . '</h2><p>' . sprintf( esc_html__( 'Hello %s. Share your favorites and follow your earnings.', 'refmora' ), esc_html( $name ) ) . '</p></div><div class="refm-hero-side">';
		if ( 'none' !== $status ) { echo wp_kses_post( REFM_Store::status( $active ? 'approved' : ( 'approved' === $status ? 'paused' : $status ) ) ); }
		else { echo '<span class="refm-hero-mark">' . wp_kses( REFM_UI::icon( 'sharing' ), REFM_UI::allowed_html() ) . '</span>'; }
		echo '</div></header>';
		if ( 'none' === $status ) {
			echo '<section class="refm-card refm-onboarding"><span class="refm-icon-tile">' . wp_kses( REFM_UI::icon( 'link' ), REFM_UI::allowed_html() ) . '</span><h3>' . esc_html( REFM_Settings::get( 'join_title' ) ) . '</h3>' . ( '' !== trim( REFM_Settings::get( 'join_description' ) ) ? '<p>' . nl2br( esc_html( REFM_Settings::get( 'join_description' ) ) ) . '</p>' : '' ) . '<ol class="refm-onboarding-steps"><li><strong>' . esc_html__( 'Join the program', 'refmora' ) . '</strong><span>' . esc_html__( 'Create your affiliate account from here.', 'refmora' ) . '</span></li><li><strong>' . esc_html__( 'Share your link', 'refmora' ) . '</strong><span>' . esc_html__( 'Recommend a product or a page you love.', 'refmora' ) . '</span></li><li><strong>' . esc_html__( 'Earn & withdraw', 'refmora' ) . '</strong><span>' . esc_html__( 'Completed orders release eligible earnings.', 'refmora' ) . '</span></li></ol>';
			if ( 'yes' === REFM_Settings::get( 'join_show_rate' ) ) {
				$rate = 'percent' === REFM_Settings::get( 'commission_type' ) ? esc_html( wc_format_decimal( REFM_Settings::get( 'commission_rate' ), 6, true ) . '%' ) : wp_kses_post( REFM_Store::money( REFM_Settings::get( 'commission_rate' ), $currency ) ) . ' ' . esc_html__( 'per purchased unit', 'refmora' );
				echo '<div class="refm-join-commission"><strong>' . esc_html__( 'Default commission:', 'refmora' ) . ' ' . wp_kses_post( $rate ) . '</strong><p class="refm-muted">' . esc_html__( 'Individual products may have different commission rates. Commissions apply to eligible purchases.', 'refmora' ) . '</p></div>';
			}
			if ( REFM_Settings::program_enabled() && 'yes' !== get_user_meta( $id, '_refm_blocked', true ) ) {
				echo '<form method="post">'; wp_nonce_field( 'refm_account' );
				if ( 'yes' === REFM_Settings::get( 'join_url_enabled' ) ) { echo '<div class="refm-field"><label for="refm-join-website">' . esc_html__( 'Website / Promote URL', 'refmora' ) . '</label><input type="url" id="refm-join-website" name="refm_join[website]" maxlength="2048" placeholder="https://example.com/your-profile" autocomplete="url" required><p class="refm-muted">' . esc_html__( 'Your website, blog, channel or social profile.', 'refmora' ) . '</p></div>'; }
				if ( 'yes' === REFM_Settings::get( 'join_promotion_enabled' ) ) { echo '<div class="refm-field"><label for="refm-join-promotion">' . esc_html__( 'How will you promote us?', 'refmora' ) . '</label><textarea id="refm-join-promotion" name="refm_join[promotion]" maxlength="3000" rows="4" style="width:100%" required></textarea><p class="refm-muted">' . esc_html__( 'Tell us about your audience and how you plan to share our products.', 'refmora' ) . '</p></div>'; }
				$terms = REFM_Settings::terms();
				if ( '' !== $terms ) {
					echo '<section class="refm-join-terms" aria-labelledby="refm-terms-title"><h3 id="refm-terms-title">' . esc_html__( 'Terms & Conditions', 'refmora' ) . '</h3><div class="refm-terms-content" tabindex="0" role="region" aria-labelledby="refm-terms-title">' . wp_kses_post( wpautop( $terms ) ) . '</div><label class="refm-terms-accept"><input type="checkbox" name="refm_accept_terms" value="yes" required><span>' . esc_html__( 'I have read and agree to the affiliate Terms & Conditions.', 'refmora' ) . '</span></label><input type="hidden" name="refm_terms_version" value="' . esc_attr( REFM_Settings::terms_version() ) . '"></section>';
				}
				echo '<input type="hidden" name="refm_action" value="join"><button class="button refm-primary">' . esc_html( REFM_Settings::get( 'join_button_text' ) ) . wp_kses( REFM_UI::icon( 'arrow' ), REFM_UI::allowed_html() ) . '</button></form><p class="refm-muted">' . esc_html( 'yes' === REFM_Settings::get( 'approval_required' ) ? __( 'Your application will be reviewed by the store.', 'refmora' ) : __( 'Join now to get your personal referral link.', 'refmora' ) ) . '</p>';
			} else { echo '<p class="refm-notice">' . esc_html__( 'Enrollment is currently unavailable.', 'refmora' ) . '</p>'; }
			echo '</section></div>'; return;
		}
		if ( in_array( $status, array( 'pending', 'rejected' ), true ) ) {
			echo '<section class="refm-card refm-application"><span class="refm-icon-tile">' . wp_kses( REFM_UI::icon( 'clock' ), REFM_UI::allowed_html() ) . '</span><h3>' . esc_html__( 'Affiliate application', 'refmora' ) . '</h3><p>' . esc_html( 'pending' === $status ? __( 'Your application is awaiting administrator approval. Your links and withdrawal tools will be ready once you are approved.', 'refmora' ) : __( 'Your application was rejected. Please contact the store if you have questions.', 'refmora' ) ) . '</p></section></div>'; return;
		}
		echo '<nav class="refm-account-nav" aria-label="' . esc_attr__( 'Affiliate sections', 'refmora' ) . '">';
		foreach ( array( 'earnings' => __( 'Overview', 'refmora' ), 'sharing' => __( 'Sharing tools', 'refmora' ), 'refer-links' => __( 'Top links', 'refmora' ), 'withdraw' => __( 'Withdraw', 'refmora' ), 'commissions' => __( 'History', 'refmora' ), 'customers' => __( 'Customers', 'refmora' ) ) as $key => $label ) { echo '<a href="#refm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>'; }
		echo '</nav>';
		if ( ! $active ) { echo '<p class="refm-notice">' . esc_html__( 'The affiliate program or your account is paused. Your history is still available.', 'refmora' ) . '</p>'; }
		$commissions = REFM_Store::table( 'commissions' ); $payouts = REFM_Store::table( 'payouts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE affiliate_id=%d AND currency=%s AND status=\'pending\'', $commissions, $id, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$reserved = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE affiliate_id=%d AND currency=%s AND status IN (\'pending\',\'approved\',\'processing\')', $payouts, $id, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$paid = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(paid_amount),0) FROM %i WHERE affiliate_id=%d AND currency=%s', $commissions, $id, $currency ) );
		$balance = REFM_Store::balance( $id, $currency );
		echo '<div class="refm-metrics" id="refm-earnings">';
		echo wp_kses( REFM_UI::metric( __( 'Available balance', 'refmora' ), REFM_Store::money( $balance, $currency ), 'wallet', __( 'Ready for withdrawal', 'refmora' ) ), REFM_UI::allowed_html() );
		echo wp_kses( REFM_UI::metric( __( 'Pending earnings', 'refmora' ), REFM_Store::money( $pending, $currency ), 'clock', __( 'Awaiting order completion or the waiting period', 'refmora' ) ), REFM_UI::allowed_html() );
		echo wp_kses( REFM_UI::metric( __( 'In withdrawal', 'refmora' ), REFM_Store::money( $reserved, $currency ), 'send', __( 'Requests in progress', 'refmora' ) ), REFM_UI::allowed_html() );
		echo wp_kses( REFM_UI::metric( __( 'Total paid out', 'refmora' ), REFM_Store::money( $paid, $currency ), 'award', __( 'Completed payouts', 'refmora' ) ), REFM_UI::allowed_html() );
		$stats = REFM_Store::referral_stats( $id );
		echo '</div><div class="refm-referral-metrics">' . wp_kses( REFM_UI::metric( __( 'Referral clicks', 'refmora' ), number_format_i18n( $stats['clicks'] ), 'click', '', '#refm-refer-links' ), REFM_UI::allowed_html() ) . wp_kses( REFM_UI::metric( __( 'Registered customers', 'refmora' ), number_format_i18n( $stats['customers'] ), 'user', '', '#refm-customers' ), REFM_UI::allowed_html() ) . '</div><details class="refm-stat-help"><summary>' . esc_html__( 'How your activity is counted', 'refmora' ) . '</summary><p>' . esc_html__( 'Referral clicks are counted once per affiliate/destination URL per browser within 30 minutes. Public pages, posts, products and archives are supported. Totals are calculated from these same URL counters. Registered customers are new accounts created through your referral cookie. These statistics start from when tracking was enabled.', 'refmora' ) . '</p></details>';
		echo '<div class="refm-grid refm-account-tools"><section class="refm-card refm-link-card" id="refm-sharing"><div class="refm-card-heading"><span class="refm-icon-tile">' . wp_kses( REFM_UI::icon( 'link' ), REFM_UI::allowed_html() ) . '</span><div><h3>' . esc_html__( 'Your referral link', 'refmora' ) . '</h3><p>' . esc_html__( 'One link to share your favorite store.', 'refmora' ) . '</p></div></div>';
		if ( $active ) {
			$link = add_query_arg( REFM_Settings::referral_parameter(), REFM_Tracking::token( $id ), home_url( '/' ) );
		/* translators: %d: referral cookie lifetime in days. */
			echo '<label for="refm-ref-link" class="screen-reader-text">' . esc_html__( 'Referral link', 'refmora' ) . '</label><div class="refm-copy"><input id="refm-ref-link" readonly value="' . esc_attr( $link ) . '"><button type="button" class="button refm-primary" data-refm-copy data-copied="' . esc_attr__( 'Copied!', 'refmora' ) . '">' . esc_html__( 'Copy', 'refmora' ) . '</button></div><p class="refm-muted refm-link-hint">' . sprintf( esc_html__( 'Referral cookie: %d days. Self-referrals do not earn commission.', 'refmora' ), (int) REFM_Settings::get( 'cookie_days' ) ) . '</p><p class="refm-copy-feedback" role="status" aria-live="polite"></p>';
			REFM_Sharing::generator();
			self::best_sellers( $id );
		} else { echo '<p class="refm-muted">' . esc_html__( 'Your sharing tools will return when your affiliate account and the program are active.', 'refmora' ) . '</p>'; }
		echo '</section><div class="refm-account-sidebar">';
		/** Render optional affiliate-scoped analytics above the withdrawal card. */
		REFM_Reports::render_affiliate( $id );
		do_action( 'refm_affiliate_activity_chart', $id );
		echo '<section class="refm-card refm-withdraw-card" id="refm-withdraw"><div class="refm-card-heading"><span class="refm-icon-tile">' . wp_kses( REFM_UI::icon( 'payouts' ), REFM_UI::allowed_html() ) . '</span><div><h3>' . esc_html__( 'Request a withdrawal', 'refmora' ) . '</h3><p>' . esc_html__( 'Choose how you receive your earnings.', 'refmora' ) . '</p></div></div>';
		$minimum = max( 0.01, (float) REFM_Settings::get( 'minimum' ) );
		echo '<div class="refm-withdraw-balance"><span>' . esc_html__( 'Available to withdraw', 'refmora' ) . '</span><strong>' . wp_kses_post( REFM_Store::money( $balance, $currency ) ) . '</strong><p class="refm-muted">' . esc_html__( 'Minimum withdrawal:', 'refmora' ) . ' ' . wp_kses_post( REFM_Store::money( $minimum, $currency ) ) . '</p>';
		$progress = min( 100, max( 0, $balance / $minimum * 100 ) );
		$progress_value = number_format( $progress, 4, '.', '' );
		$progress_level = $balance >= $minimum ? 'complete' : ( $progress <= 20 ? 'low' : ( $progress <= 50 ? 'medium' : 'high' ) );
		echo '<div class="refm-withdraw-progress-heading"><span class="refm-withdraw-progress-label">' . esc_html__( 'Withdrawal progress', 'refmora' );
		if ( $balance >= $minimum ) {
			echo '<span class="refm-withdraw-ready" aria-hidden="true">' . wp_kses( REFM_UI::icon( 'check' ), REFM_UI::allowed_html() ) . '</span><span class="screen-reader-text">' . esc_html__( 'Minimum withdrawal reached', 'refmora' ) . '</span>';
		}
		echo '</span><strong data-level="' . esc_attr( $progress_level ) . '">' . esc_html( number_format_i18n( floor( $progress ), 0 ) . '%' ) . '</strong></div><div class="refm-withdraw-progress" data-level="' . esc_attr( $progress_level ) . '" role="progressbar" aria-label="' . esc_attr__( 'Progress toward minimum withdrawal', 'refmora' ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $progress_value ) . '"><span style="width:' . esc_attr( $progress_value ) . '%"></span></div>';
		/* translators: %s: formatted amount needed to reach the withdrawal minimum. */
		if ( $balance < $minimum ) { echo '<p class="refm-muted">' . sprintf( esc_html__( '%s more to reach your minimum.', 'refmora' ), wp_kses_post( REFM_Store::money( $minimum - $balance, $currency ) ) ) . '</p>'; }
		echo '</div>';
		REFM_Gateways::connection_ui( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$methods = REFM_Settings::methods(); $open = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE affiliate_id=%d AND status IN (\'pending\',\'approved\',\'processing\') LIMIT 1', $payouts, $id ) );
		if ( $active && $methods && ! $open ) {
			echo '<form method="post" class="refm-withdraw">'; wp_nonce_field( 'refm_account' );
			echo '<input type="hidden" name="refm_action" value="withdraw"><details class="refm-withdraw-options"><summary>' . esc_html__( 'Payment options', 'refmora' ) . '</summary><div class="refm-withdraw-options-body"><label for="refm-method">' . esc_html__( 'Payout method', 'refmora' ) . '</label><select id="refm-method" name="method">';
			foreach ( $methods as $method ) { echo '<option value="' . esc_attr( $method['id'] ) . '" data-provider="' . esc_attr( $method['provider'] ?? 'manual' ) . '" data-instructions="' . esc_attr( $method['instructions'] ) . '">' . esc_html( $method['name'] ) . '</option>'; }
			echo '</select><p id="refm-instructions" class="refm-muted">' . esc_html( $methods[0]['instructions'] ) . '</p><label for="refm-details">' . esc_html__( 'Payment destination details', 'refmora' ) . '</label><textarea id="refm-details" name="details" rows="3" required minlength="3" maxlength="2000"></textarea><p class="refm-muted">' . esc_html__( 'Enter only the details needed to receive payment. Never share passwords or card security codes.', 'refmora' ) . '</p></div></details><button class="button refm-primary"' . disabled( $balance < $minimum, true, false ) . '>' . esc_html__( 'Withdraw available balance', 'refmora' ) . wp_kses( REFM_UI::icon( 'arrow' ), REFM_UI::allowed_html() ) . '</button></form>';
		} else { echo '<p class="refm-notice">' . esc_html( $open ? __( 'Your withdrawal is being reviewed. Follow its status in the history below.', 'refmora' ) : __( 'Withdrawals are currently unavailable.', 'refmora' ) ) . '</p>'; }
		echo '<p class="refm-muted refm-balance-note">' . esc_html__( 'Refunds adjust your earnings. Refunds after a payout reduce future earnings. History keeps each transaction in its original currency.', 'refmora' ) . '</p></section></div></div>';
		REFM_Refer_Links::render( $id, 'affiliate' );
		self::history( 'commissions', $id ); self::history( 'payouts', $id ); self::customers( $id ); echo '</div>';
	}
	private static function best_sellers( $id ) {
		if ( get_current_user_id() !== (int) $id || ! REFM_Sharing::allowed( $id ) ) { return; }
		// Rank with WooCommerce's product lookup table instead of casting/sorting postmeta.
		// Scope the adapter to this query; never change unrelated product/archive queries.
		$rank = static function ( $clauses, $query ) {
			if ( ! $query->get( 'refm_best_sellers' ) ) { return $clauses; }
			$clauses = WC()->query->order_by_popularity_post_clauses( $clauses );
			$clauses['where'] .= ' AND wc_product_meta_lookup.total_sales > 0';
			return $clauses;
		};
		add_filter( 'posts_clauses', $rank, 20, 2 );
		try {
			$query = new WP_Query( array(
				'post_type' => 'product', 'post_status' => 'publish', 'post_password' => '',
				'posts_per_page' => 5, 'fields' => 'ids', 'no_found_rows' => true,
				'ignore_sticky_posts' => true, 'cache_results' => true,
				'refm_best_sellers' => true, 'orderby' => 'none',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required commission eligibility predicate; only five IDs are returned and WordPress query caching remains enabled.
				'meta_query' => array( 'relation' => 'OR',
					array( 'key' => '_refm_type', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_refm_type', 'value' => 'disabled', 'compare' => '!=' ),
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- WooCommerce type/catalog visibility rules exclude hidden and unsupported products; the bounded query uses WordPress caches.
				'tax_query' => array( 'relation' => 'AND',
					array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => array( 'simple', 'variable' ) ),
					array( 'taxonomy' => 'product_visibility', 'field' => 'slug', 'terms' => array( 'exclude-from-catalog' ), 'operator' => 'NOT IN' ),
				),
			) );
		} finally {
			remove_filter( 'posts_clauses', $rank, 20 );
		}
		echo '<section class="refm-best-sellers"><h3>' . esc_html__( 'Top selling products', 'refmora' ) . '</h3><p class="refm-muted">' . esc_html__( 'Popular products across the store, ranked by lifetime sales. Copy a link to share.', 'refmora' ) . '</p><ol class="refm-best-seller-list">';
		$shown = 0;
		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_visible() ) { continue; }
			$url = $product->get_permalink(); $link = REFM_Sharing::link( $id, $url );
			if ( is_wp_error( $link ) ) { continue; }
			$shown++;
			// Keep native lazy loading: theme fallbacks can become duplicate images when wp_kses_post() strips noscript.
		/* translators: %s: product name. */
			echo '<li><span class="refm-best-seller-rank" aria-hidden="true">' . esc_html( $shown ) . '</span><a class="refm-best-seller-product" href="' . esc_url( $url ) . '">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '', 'class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail skip-lazy' ) ) ) . '<span>' . esc_html( $product->get_name() ) . '</span></a><button type="button" class="button refm-primary" data-refm-copy data-refm-url="' . esc_attr( $link ) . '" data-copied="' . esc_attr__( 'Copied!', 'refmora' ) . '" data-copy-failed="' . esc_attr__( 'Could not copy. Please try again.', 'refmora' ) . '" aria-label="' . esc_attr( sprintf( __( 'Copy affiliate link for %s', 'refmora' ), $product->get_name() ) ) . '">' . esc_html__( 'Copy link', 'refmora' ) . '</button></li>';
		}
		echo '</ol>';
		if ( ! $shown ) { echo '<p class="refm-muted">' . esc_html__( 'Top-selling products will appear here when eligible products have sales.', 'refmora' ) . '</p>'; }
		echo '<p class="refm-copy-feedback" role="status" aria-live="polite"></p></section>';
	}
	/** Include only variation attributes, never arbitrary order-item/customer metadata. */
	public static function item_title( $item ) {
		$title = wp_strip_all_tags( $item->get_name() );
		$product = $item->get_product();
		$details = array();
		if ( $product && $product->is_type( 'variation' ) ) {
			foreach ( $product->get_variation_attributes() as $attribute => $fallback ) {
				$key = preg_replace( '/^attribute_/', '', $attribute );
				$value = $item->get_meta( $key, true );
				$value = is_scalar( $value ) && '' !== (string) $value ? (string) $value : $fallback;
				if ( ! is_scalar( $value ) || '' === (string) $value ) { continue; }
				if ( taxonomy_exists( $key ) ) { $term = get_term_by( 'slug', $value, $key ); if ( $term && ! is_wp_error( $term ) ) { $value = $term->name; } }
				if ( false === stripos( $title, (string) $value ) ) { $details[] = wc_attribute_label( $key ) . ': ' . wp_strip_all_tags( (string) $value ); }
			}
		}
		return $title . ( $details ? ' — ' . implode( ', ', $details ) : '' );
	}
	public static function masked_email( $email ) {
		$email = is_scalar( $email ) ? sanitize_email( (string) $email ) : '';
		if ( ! is_email( $email ) ) { return ''; }
		list( $local, $domain ) = explode( '@', $email, 2 );
		$suffix = substr( $domain, strrpos( $domain, '.' ) );
		return substr( $local, 0, min( 4, max( 0, strlen( $local ) - 2 ) ) ) . '**@*****' . $suffix;
	}
	public static function masked_phone( $phone ) {
		$digits = is_scalar( $phone ) ? preg_replace( '/\D/', '', (string) $phone ) : '';
		return $digits ? substr( $digits, 0, min( 3, max( 0, strlen( $digits ) - 2 ) ) ) . '*****' : '';
	}
	private static function pagination( $key, $page, $count, $anchor ) {
		$total = max( 1, (int) ceil( $count / self::PAGE_SIZE ) );
		if ( $total <= 1 ) { return; }
		$page = min( REFM_Security::page_number( $page ), $total );
		$args = array();
		foreach ( array( 'refm_cp', 'refm_pp', 'refm_rp', 'refm_lp' ) as $other ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve only bounded read-only table pages; ownership is checked before each table query.
			if ( isset( $_GET[ $other ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Shared validator rejects non-scalar or unbounded pagination values.
				$args[ $other ] = REFM_Security::page( $_GET, $other );
			}
		}
		$base = wc_get_account_endpoint_url( 'refmora' );
		$link = static function ( $target, $label ) use ( $args, $base, $key, $anchor ) {
			$args[ $key ] = $target;
			echo '<a class="page-numbers refm-primary" href="' . esc_url( add_query_arg( $args, $base ) . '#' . $anchor ) . '">' . esc_html( $label ) . '</a>';
		};
		/* translators: 1: current page, 2: total pages. */
		echo '<nav class="refm-pagination refm-admin-pagination" aria-label="' . esc_attr__( 'Table pages', 'refmora' ) . '"><span class="refm-page-summary">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'refmora' ), $page, $total ) ) . '</span><div class="refm-page-links">';
		if ( $page > 1 ) { $link( $page - 1, __( 'Previous', 'refmora' ) ); }
		$pages = array_unique( array( 1, $total, max( 1, $page - 1 ), $page, min( $total, $page + 1 ) ) );
		sort( $pages );
		$previous = 0;
		foreach ( $pages as $number ) {
			if ( $previous && $number - $previous > 1 ) { echo '<span class="page-numbers dots" aria-hidden="true">&hellip;</span>'; }
			if ( $number === $page ) {
				echo '<span class="page-numbers current" aria-current="page">' . esc_html( number_format_i18n( $number ) ) . '</span>';
			} else { $link( $number, number_format_i18n( $number ) ); }
			$previous = $number;
		}
		if ( $page < $total ) { $link( $page + 1, __( 'Next', 'refmora' ) ); }
		echo '</div></nav>';
	}

	private static function customers( $id ) {
		global $wpdb;
		$table = REFM_Store::table( 'customers' );
		$count = REFM_Store::referral_stats( $id )['customers'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = min( REFM_Security::page_number( absint( ( isset( $_GET[ 'refm_rp' ] ) && is_scalar( $_GET[ 'refm_rp' ] ) ? wp_unslash( $_GET[ 'refm_rp' ] ) : '1' ) ) ), max( 1, (int) ceil( $count / self::PAGE_SIZE ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT customer_id FROM %i WHERE affiliate_id=%d ORDER BY created_at DESC,customer_id DESC LIMIT %d OFFSET %d', $table, $id, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ) );
		if ( $ids ) { update_meta_cache( 'user', $ids ); }
		echo '<section class="refm-card refm-history" id="refm-customers"><h3>' . wp_kses( REFM_UI::icon( 'affiliates' ), REFM_UI::allowed_html() ) . esc_html__( 'Your registered customers', 'refmora' ) . '</h3><p class="refm-muted">' . esc_html__( 'Only first names and masked contact details are shown.', 'refmora' ) . '</p><div class="refm-table-scroll"><table><thead><tr><th scope="col">' . esc_html__( 'SL', 'refmora' ) . '</th><th>' . esc_html__( 'First name', 'refmora' ) . '</th><th>' . esc_html__( 'Email', 'refmora' ) . '</th><th>' . esc_html__( 'Phone', 'refmora' ) . '</th></tr></thead><tbody>';
		$serial = ( $page - 1 ) * self::PAGE_SIZE;
		foreach ( $ids as $customer_id ) {
			$name = get_user_meta( $customer_id, 'first_name', true ) ?: get_user_meta( $customer_id, 'billing_first_name', true );
			$user = get_userdata( $customer_id );
			$email = self::masked_email( $user ? $user->user_email : '' );
			$phone = self::masked_phone( get_user_meta( $customer_id, 'billing_phone', true ) );
			echo '<tr><td>' . esc_html( number_format_i18n( ++$serial ) ) . '</td><td>' . esc_html( $name ?: __( 'Customer', 'refmora' ) ) . '</td><td>' . esc_html( $email ?: '—' ) . '</td><td>' . esc_html( $phone ?: '—' ) . '</td></tr>';
		}
		if ( ! $ids ) { echo '<tr><td colspan="4" class="refm-empty">' . esc_html__( 'No registered customers yet.', 'refmora' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( 'refm_rp', esc_html( $page ), $count, 'refm-customers' ); echo '</section>';
	}
	private static function commission_items( $row, $id ) {
		$order = wc_get_order( $row->order_id );
		// Never expose items from a different affiliate's order, even if history is inconsistent.
		if ( ! $order || $order instanceof WC_Order_Refund || (int) $order->get_meta( '_refm_affiliate' ) !== (int) $id ) { return '<span class="refm-muted">' . esc_html__( 'Item details unavailable', 'refmora' ) . '</span>'; }
		$rates = $order->get_meta( '_refm_rates' );
		if ( ! is_array( $rates ) || ! $rates ) { return '<span class="refm-muted">' . esc_html__( 'Saved rates unavailable', 'refmora' ) . '</span>'; }
		$html = '<ul class="refm-commission-items">';
		$items = $order->get_items( 'line_item' );
		$positions = array_values( $items );
		foreach ( $rates as $item_id => $rate ) {
			if ( ! is_array( $rate ) ) { continue; }
			// Historical checkout snapshots may use keys assigned before the order is saved.
			// Only resolve items within this order; never load an arbitrary global item ID.
			$item = $items[ $item_id ] ?? false;
			if ( ! $item && preg_match( '/^new:line_items([0-9]+)$/', (string) $item_id, $match ) ) {
				$candidate = $positions[ (int) $match[1] ] ?? false;
				if ( $candidate instanceof WC_Order_Item_Product && isset( $rate['quantity'], $rate['total'] ) && is_numeric( $rate['quantity'] ) && is_numeric( $rate['total'] ) && abs( (float) $candidate->get_quantity() - (float) $rate['quantity'] ) < 0.000001 && abs( (float) $candidate->get_total() - (float) $rate['total'] ) < 0.000001 ) { $item = $candidate; }
			}
			$title = isset( $rate['title'] ) && is_scalar( $rate['title'] ) && '' !== trim( (string) $rate['title'] ) ? $rate['title'] : ( $item instanceof WC_Order_Item_Product ? self::item_title( $item ) : __( 'Item unavailable', 'refmora' ) );
			$type = $rate['type'] ?? ''; $value = isset( $rate['rate'] ) && is_numeric( $rate['rate'] ) ? max( 0, (float) $rate['rate'] ) : 0;
			$quantity = isset( $rate['quantity'] ) && is_numeric( $rate['quantity'] ) ? max( 0, (float) $rate['quantity'] ) : 0;
			if ( 'percent' === $type ) { $label = esc_html( wc_format_localized_decimal( wc_format_decimal( min( 100, $value ), 4, true ) ) . '%' ); }
			elseif ( 'fixed' === $type ) { $label = wp_kses_post( REFM_Store::money( $value, $row->currency ) ) . ' ' . esc_html__( 'fixed / unit', 'refmora' ); }
			elseif ( 'disabled' === $type ) { $label = esc_html__( 'Excluded', 'refmora' ); }
			else { $label = esc_html__( 'Rate unavailable', 'refmora' ); }
		/* translators: %s: item quantity. */
			$html .= '<li><strong>' . esc_html( $title ) . '</strong><small>' . $label . ' · ' . sprintf( esc_html__( 'Qty: %s', 'refmora' ), esc_html( wc_format_localized_decimal( $quantity ) ) ) . '</small></li>';
		}
		$html .= '</ul>';
		$fx = $order->get_meta( '_refm_fx' );
		if ( is_array( $fx ) && ( $fx['from'] ?? '' ) !== ( $fx['to'] ?? '' ) && REFM_Currency::valid_rate( $fx['rate'] ?? null ) ) {
		/* translators: %1$s: source currency, %2$s: exchange rate, %3$s: target currency. */
			$html .= '<small class="refm-note">' . esc_html( sprintf( __( 'Saved rate: 1 %1$s = %2$s %3$s', 'refmora' ), $fx['from'], rtrim( rtrim( sprintf( '%.12f', $fx['rate'] ), '0' ), '.' ), $fx['to'] ) ) . '</small>';
		}
		return $html;
	}
	private static function history( $type, $id ) {
		global $wpdb;
		$commission = 'commissions' === $type; $table = REFM_Store::table( $type );
		$key = $commission ? 'refm_cp' : 'refm_pp';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE affiliate_id=%d', $table, $id ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = min( REFM_Security::page_number( absint( ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '1' ) ) ), max( 1, (int) ceil( $count / self::PAGE_SIZE ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE affiliate_id=%d ORDER BY id DESC LIMIT %d OFFSET %d', $table, $id, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ) );
		echo '<section class="refm-card refm-history" id="refm-' . esc_attr( $type ) . '"><h3>' . wp_kses( REFM_UI::icon( $type ), REFM_UI::allowed_html() ) . esc_html( $commission ? __( 'Commission history', 'refmora' ) : __( 'Withdrawal history', 'refmora' ) ) . '</h3><div class="refm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Reference', 'refmora' ) . '</th><th>' . esc_html__( 'Date', 'refmora' ) . '</th>';
		if ( $commission ) { echo '<th>' . esc_html__( 'Items / commission rate', 'refmora' ) . '</th>'; }
		echo '<th>' . esc_html__( 'Amount', 'refmora' ) . '</th><th>' . esc_html__( 'Status', 'refmora' ) . '</th><th>' . esc_html( $commission ? __( 'Adjustment', 'refmora' ) : __( 'Method / note', 'refmora' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>#' . esc_html( $commission ? $row->order_id : $row->id ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ) . '</td>';
			if ( $commission ) { echo '<td>' . wp_kses_post( self::commission_items( $row, $id ) ) . '</td>'; }
			echo '<td>' . wp_kses_post( REFM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( REFM_Store::status( $row->status ) ) . '</td><td>';
			if ( $commission ) {
				if ( 'repeat' === $row->eligibility ) { echo esc_html__( 'Repeat purchase excluded', 'refmora' ); }
				else { echo (float) $row->paid_amount > (float) $row->amount ? wp_kses_post( REFM_Store::money( (float) $row->amount - (float) $row->paid_amount, $row->currency ) ) : '&mdash;'; }
			} else { echo wp_kses( REFM_UI::payment_method( $row->method ), REFM_UI::allowed_html() ); echo '<details class="refm-payment-details"><summary>' . esc_html__( 'Payment details', 'refmora' ) . '</summary><div class="refm-payout-details">' . nl2br( esc_html( $row->details ) ) . '</div></details>'; REFM_Gateways::summary( $row->id ); if ( $row->note ) { echo '<small class="refm-note">' . esc_html( $row->note ) . '</small>'; } }
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="' . ( $commission ? '6' : '5' ) . '" class="refm-empty">' . esc_html__( 'Nothing here yet. Your activity will appear here.', 'refmora' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $key, esc_html( $page ), $count, 'refm-' . $type ); echo '</section>';
	}
	public static function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Refmora', '<p>' . esc_html__( 'We count referral clicks in daily aggregate counters, without visitor IP addresses or browser fingerprints. Public destination URLs are stored once in a shared link directory. Daily counters and lifetime affiliate/link totals reference that link ID. Ranking lists use indexed lifetime totals; charts use daily counters. Both update in one transaction. Unified URL tracking starts with fresh counters. Obsolete product and aggregate counter tables are removed. A signed cookie remembers up to 12 recently visited affiliate/URL pairs for 30-minute duplicate suppression. Canonical public URLs exclude referral and marketing parameters; visitor IP addresses, browser fingerprints, private account pages and search terms are not stored. URL counters are included in affiliate privacy exports and erasure. New customer account registrations may be linked to the referring affiliate while a valid referral cookie is present. Affiliates see the customer first name and masked email and phone details; full contact details, surnames and other account details are not shown. Registration attribution and affiliate counters are removed through WordPress personal-data erasure or when the associated account is deleted.', 'refmora' ) . '</p>' );
			wp_add_privacy_policy_content( 'Refmora', '<p>' . esc_html__( 'Our affiliate program uses a signed referral cookie to attribute purchases. The cookie stores an affiliate identifier and expiry time. Affiliate applications may include a website/profile URL and promotion plan when these fields are enabled for administrator review. We store affiliate enrollment, order-linked commissions, and withdrawal requests with payment destination details in our WordPress database. Authorized store managers can access these records to process payouts. Referral cookies expire after the configured duration. Financial records are retained until the store administrator removes them according to the store retention policy. If automatic payouts are configured, payment recipient identifiers, payout amounts and currencies are sent to PayPal or Stripe when a store manager sends an approved withdrawal. Connected Stripe account identifiers are stored for this purpose. Provider transaction records are retained for financial reconciliation.', 'refmora' ) . '</p>' );
		}
	}
	public static function exporter( $exporters ) {
		$exporters['refmora'] = array( 'exporter_friendly_name' => 'Refmora', 'callback' => array( __CLASS__, 'export_data' ) );
		return $exporters;
	}
	public static function export_data( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) { return array( 'data' => array(), 'done' => true ); }
		$data = array();
		$done = true;
		$page = max( 1, absint( $page ) );
		if ( 1 === $page && get_user_meta( $user->ID, '_refm_enrolled', true ) ) {
			$data[] = array( 'group_id' => 'refmora', 'group_label' => 'Refmora', 'item_id' => 'refm-profile-' . $user->ID, 'data' => array( array( 'name' => __( 'Enrollment', 'refmora' ), 'value' => get_user_meta( $user->ID, '_refm_enrolled', true ) ), array( 'name' => __( 'Referral token', 'refmora' ), 'value' => get_user_meta( $user->ID, '_refm_token', true ) ), array( 'name' => __( 'Blocked', 'refmora' ), 'value' => get_user_meta( $user->ID, '_refm_blocked', true ) ) ) );
		}
		if ( 1 === $page ) {
			foreach ( array( 'test', 'live' ) as $mode ) {
				$connection = get_user_meta( $user->ID, '_refm_stripe_' . $mode, true );
				if ( is_array( $connection ) && ! empty( $connection['id'] ) ) { $data[] = array( 'group_id' => 'refmora', 'group_label' => 'Refmora', 'item_id' => 'refm-stripe-' . $mode . '-' . $user->ID, 'data' => array( array( 'name' => 'Stripe ' . $mode . ' connected account', 'value' => $connection['id'] ) ) ); }
			}
		}
		$customers = REFM_Store::table( 'customers' );
		$application = get_user_meta( $user->ID, '_refm_application', true );
		if ( 1 === $page && is_array( $application ) ) {
			$values = array(); foreach ( array( 'website', 'promotion', 'submitted_at' ) as $key ) { $values[] = array( 'name' => $key, 'value' => REFM_Security::value( $application, $key ) ); }
			$data[] = array( 'group_id' => 'refmora', 'group_label' => 'Refmora', 'item_id' => 'refm-application-' . $user->ID, 'data' => $values );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$referrals = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id=%d OR affiliate_id=%d ORDER BY customer_id ASC LIMIT 50 OFFSET %d', $customers, $user->ID, $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
		if ( 1 === $page && 'none' !== REFM_Affiliates::status( $user->ID ) ) {
			$data[] = array( 'group_id' => 'refmora', 'group_label' => 'Refmora', 'item_id' => 'refm-approval-' . $user->ID, 'data' => array( array( 'name' => __( 'Affiliate approval status', 'refmora' ), 'value' => REFM_Affiliates::status( $user->ID ) ) ) );
		}
		if ( count( $referrals ) === 50 ) { $done = false; }
		foreach ( $referrals as $referral ) {
			$values = array();
			foreach ( $referral as $key => $value ) { $values[] = array( 'name' => $key, 'value' => (string) $value ); }
			$data[] = array( 'group_id' => 'refm-customers', 'group_label' => __( 'Affiliate customer registration', 'refmora' ), 'item_id' => 'refm-customer-' . $referral['customer_id'], 'data' => $values );
		}
		foreach ( array( 'link_daily', 'link_totals' ) as $counter ) {
			$is_daily = 'link_daily' === $counter;
			$columns  = $is_daily ? 'd.link_id,k.link_url,d.click_date,d.clicks' : 'd.link_id,k.link_url,d.clicks';
			$order    = $is_daily ? 'd.link_id ASC,d.click_date ASC' : 'd.link_id ASC';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Columns and order are fixed local alternatives; table names, subject ID and page bounds are prepared.
			$records = $wpdb->get_results( $wpdb->prepare( "SELECT $columns FROM %i AS d INNER JOIN %i AS k ON k.id=d.link_id WHERE d.affiliate_id=%d ORDER BY $order LIMIT 50 OFFSET %d", REFM_Store::table( $counter ), REFM_Store::table( 'links' ), $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
			if ( count( $records ) === 50 ) { $done = false; }
			foreach ( $records as $row ) {
				$values = array( array( 'name' => 'link_url', 'value' => $row['link_url'] ), array( 'name' => 'clicks', 'value' => (string) $row['clicks'] ) );
				if ( $is_daily ) { $values[] = array( 'name' => 'click_date', 'value' => $row['click_date'] ); }
				$data[] = array( 'group_id' => 'refm-' . str_replace( '_', '-', $counter ), 'group_label' => $is_daily ? __( 'Affiliate daily link counters', 'refmora' ) : __( 'Affiliate lifetime link totals', 'refmora' ), 'item_id' => 'refm-' . $counter . '-' . $row['link_id'] . ( $is_daily ? '-' . $row['click_date'] : '' ), 'data' => $values );
			}
		}
		foreach ( array( 'commissions', 'payouts' ) as $type ) {
			$table = REFM_Store::table( $type );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE affiliate_id=%d ORDER BY id ASC LIMIT 50 OFFSET %d', $table, $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
			if ( count( $rows ) === 50 ) { $done = false; }
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $key => $value ) { $values[] = array( 'name' => $key, 'value' => (string) $value ); }
				$data[] = array( 'group_id' => 'refm-' . $type, 'group_label' => 'commissions' === $type ? __( 'Affiliate commissions', 'refmora' ) : __( 'Affiliate withdrawals', 'refmora' ), 'item_id' => 'refm-' . $type . '-' . $row['id'], 'data' => $values );
			}
		}
		return array( 'data' => $data, 'done' => $done );
	}
	public static function eraser( $erasers ) {
		$erasers['refmora'] = array( 'eraser_friendly_name' => 'Refmora', 'callback' => array( __CLASS__, 'erase_data' ) );
		return $erasers;
	}
	public static function erase_data( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$result = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		if ( ! $user ) { return $result; }
		foreach ( array( '_refm_token', '_refm_enrolled', '_refm_blocked', '_refm_approval', '_refm_application', '_refm_stripe_test', '_refm_stripe_live', '_refm_stripe_oauth', '_refm_stripe_onboarding', '_refm_stripe_create_test', '_refm_stripe_create_live', '_refm_stripe_country_test', '_refm_stripe_country_live' ) as $key ) { $result['items_removed'] = delete_user_meta( $user->ID, $key ) || $result['items_removed']; }
		$customers = REFM_Store::table( 'customers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE customer_id=%d OR affiliate_id=%d', $customers, $user->ID, $user->ID ) );
		$result['items_removed'] = $removed > 0 || $result['items_removed'];
		$erased = REFM_Refer_Links::erase_counters( $user->ID );
		if ( is_wp_error( $erased ) ) {
			$result['items_retained'] = true;
			$result['messages'][] = __( 'Referral counters could not be erased. Please try again.', 'refmora' );
		} else { $result['items_removed'] = $erased > 0 || $result['items_removed']; }
		foreach ( array( 'commissions', 'payouts' ) as $type ) {
			$table = REFM_Store::table( $type );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE affiliate_id=%d LIMIT 1', $table, $user->ID ) ) ) { $result['items_retained'] = true; }
		}
		if ( $result['items_retained'] ) { $result['messages'][] = __( 'Refmora retained commission and withdrawal records, including payment destinations, for financial reconciliation. The store administrator must review their retention policy before removing these records.', 'refmora' ); }
		return $result;
	}
}
