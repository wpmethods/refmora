<?php
/**
 * Plugin Name: Refmora
 * Plugin URI: https://wpmethods.com/product/refmora-best-affiliate-plugin-for-woocommerce/
 * Description: WooCommerce referral tracking, product commissions and manual affiliate withdrawals. Optional Pro add-on.
 * Version: 1.0.0
 * Author: WP Methods
 * Author URI: https://wpmethods.com/
 * Text Domain: refmora
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 */
defined( 'ABSPATH' ) || exit;
define( 'REFM_VERSION', '1.0.0' );
define( 'REFM_FILE', __FILE__ );
define( 'REFM_PATH', plugin_dir_path( __FILE__ ) );
define( 'REFM_URL', plugin_dir_url( __FILE__ ) );
require_once REFM_PATH . 'includes/class-refm-store.php';
require_once REFM_PATH . 'includes/class-refm-reports.php';
require_once REFM_PATH . 'includes/class-refm-security.php';
require_once REFM_PATH . 'includes/class-refm-ui.php';
require_once REFM_PATH . 'includes/class-refm-settings.php';
require_once REFM_PATH . 'includes/class-refm-currency.php';
require_once REFM_PATH . 'includes/class-refm-license.php';
require_once REFM_PATH . 'includes/class-refm-updates.php';
require_once REFM_PATH . 'includes/class-refm-cache.php';
REFM_Cache::init();
REFM_License::init();
REFM_Updates::init();
add_action( 'admin_post_refm_currency_recovery', array( 'REFM_Currency', 'recover' ) );
require_once REFM_PATH . 'includes/class-refm-emails.php';
require_once REFM_PATH . 'includes/class-refm-affiliates.php';
require_once REFM_PATH . 'includes/class-refm-design.php';
require_once REFM_PATH . 'includes/class-refm-tracking.php';
require_once REFM_PATH . 'includes/class-refm-commissions.php';
require_once REFM_PATH . 'includes/class-refm-payouts.php';
require_once REFM_PATH . 'includes/class-refm-gateways.php';
require_once REFM_PATH . 'includes/class-refm-stripe.php';
require_once REFM_PATH . 'includes/class-refm-account.php';
require_once REFM_PATH . 'includes/class-refm-sharing.php';
require_once REFM_PATH . 'includes/class-refm-admin.php';
require_once REFM_PATH . 'includes/class-refm-tools.php';
require_once REFM_PATH . 'includes/class-refm-checkout.php';
register_activation_hook( __FILE__, function () {
	REFM_Store::install();
	REFM_Account::endpoint();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function () { flush_rewrite_rules(); } );
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', REFM_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', REFM_FILE, true );
	}
} );
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Refmora requires WooCommerce to be active.', 'refmora' ) . '</p></div>';
		} );
		return;
	}
	if ( get_option( 'refm_db_version' ) !== REFM_VERSION ) {
		REFM_Store::install();
	}
	REFM_Settings::init();
	REFM_Emails::init();
	REFM_Design::init();
	REFM_Tracking::init();
	REFM_Checkout::init();
	REFM_Commissions::init();
	REFM_Gateways::init();
	REFM_Account::init();
	REFM_Sharing::init();
	REFM_Admin::init();
	REFM_Tools::init();
} );
