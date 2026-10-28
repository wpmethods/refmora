<?php
/**
 * Optional WP Methods resources at the bottom of every Refmora admin sidebar.
 *
 * @package Refmora
 */

defined( 'ABSPATH' ) || exit;

$refm_products        = array(
	array(
		'name'        => 'Order List Table',
		'url'         => 'https://wordpress.org/plugins/order-list-table-elementor-widget',
		'image'       => 'order-list-table.gif',
		'description' => __( 'Display WooCommerce orders and products in customizable tables.', 'refmora' ),
		'type'        => __( 'Free plugin', 'refmora' ),
		'source'      => 'wordpress.org',
	),
	array(
		'name'        => 'Social Chat Floating Icons',
		'url'         => 'https://wordpress.org/plugins/wpmethods-social-chat-floating-icons/',
		'image'       => 'social-chat.gif',
		'description' => __( 'Help visitors contact you through their favorite chat apps.', 'refmora' ),
		'type'        => __( 'Free plugin', 'refmora' ),
		'source'      => 'WordPress.org',
	),
	array(
		'name'        => 'Click Shop',
		'url'         => 'https://wpmethods.com/product/click-shop-wordpress-landing-page-type-ecommerce-theme/',
		'image'       => 'click-shop.webp',
		'description' => __( 'Create an ecommerce storefront with WooCommerce.', 'refmora' ),
		'type'        => __( 'Paid theme', 'refmora' ),
		'source'      => 'WPMethods.com',
	),
);
$refm_community_links = array(
	array(
		'url'   => 'https://buymeacoffee.com/ajharrashed',
		'icon'  => 'coffee',
		'label' => __( 'Buy me a coffee', 'refmora' ),
		'class' => 'refm-resource-coffee',
	),
	array(
		'url'   => 'https://www.youtube.com/@wpmethods',
		'icon'  => 'video-alt3',
		'label' => __( 'YouTube - WP Methods', 'refmora' ),
		'class' => 'refm-resource-community-link',
	),
	array(
		'url'   => 'https://wpmethods.com',
		'icon'  => 'admin-home',
		'label' => __( 'Visit WP Methods', 'refmora' ),
		'class' => 'refm-resource-community-link',
	),
);
?>
<details class="refm-sidebar-resources" open>
	<summary class="refm-resources-summary"><?php esc_html_e( 'Other Products', 'refmora' ); ?></summary>
	<div class="refm-sidebar-resource-content">
	<section class="refm-resource-card refm-resource-products" aria-labelledby="refm-products-heading">
		<h2 id="refm-products-heading"><?php esc_html_e( 'Other Products', 'refmora' ); ?></h2>
		<p><?php esc_html_e( 'Optional tools from WP Methods.', 'refmora' ); ?></p>
		<ul class="refm-resource-product-list">
			<?php foreach ( $refm_products as $refm_product ) : ?>
				<li>
					<a class="refm-resource-product-link" href="<?php echo esc_url( $refm_product['url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php if ( $refm_product['image'] ) : ?>
							<img class="refm-resource-product-image" src="<?php echo esc_url( REFM_URL . 'assets/img/' . $refm_product['image'] ); ?>" width="40" height="40" alt="" loading="lazy" decoding="async">
						<?php else : ?>
							<span class="refm-resource-product-image refm-resource-product-icon dashicons dashicons-editor-table" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="refm-resource-product-copy">
							<strong><?php echo esc_html( $refm_product['name'] ); ?></strong>
							<span class="refm-resource-product-description"><?php echo esc_html( $refm_product['description'] ); ?></span>
							<span class="refm-resource-product-meta"><span class="refm-resource-product-type"><?php echo esc_html( $refm_product['type'] ); ?></span><span><?php echo esc_html( $refm_product['source'] ); ?></span></span>
						</span>
						<span class="dashicons dashicons-external refm-resource-link-arrow" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'refmora' ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<section class="refm-resource-card refm-resource-community" aria-labelledby="refm-community-heading">
		<p class="refm-resource-eyebrow"><?php esc_html_e( 'MADE WITH CARE', 'refmora' ); ?></p>
		<h2 id="refm-community-heading"><?php esc_html_e( 'Support & Connect', 'refmora' ); ?></h2>
		<p><?php esc_html_e( 'Enjoying Refmora? Support the creator or explore more tutorials and tools.', 'refmora' ); ?></p>
		<?php foreach ( $refm_community_links as $refm_link ) : ?>
			<a class="<?php echo esc_attr( $refm_link['class'] ); ?>" href="<?php echo esc_url( $refm_link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-<?php echo esc_attr( $refm_link['icon'] ); ?>" aria-hidden="true"></span>
				<span><?php echo esc_html( $refm_link['label'] ); ?></span>
				<span class="dashicons dashicons-external refm-resource-link-arrow" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'refmora' ); ?></span>
			</a>
		<?php endforeach; ?>
	</section>
	</div>
</details>
