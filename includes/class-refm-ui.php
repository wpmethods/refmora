<?php
defined( 'ABSPATH' ) || exit;

final class REFM_UI {
	/** Render a configured payment logo with a safe fallback for historical methods. */
	public static function payment_method( $name ) {
		$url = '';
		foreach ( (array) REFM_Settings::get( 'methods' ) as $method ) {
			if ( $name === $method['name'] ) { $url = $method['icon'] ?? ''; break; }
		}
		foreach ( array( 'paypal', 'stripe' ) as $provider ) {
			if ( ! $url && 0 === stripos( $name, $provider ) ) { $url = REFM_Settings::get( $provider . '_icon' ); break; }
		}
		return '<span class="refm-payment-method">' . ( $url ? '<img class="refm-payment-logo skip-lazy" src="' . esc_url( $url ) . '" alt="" width="28" height="28" decoding="async" data-skip-lazy="1">' : self::icon( 'wallet' ) ) . '<strong>' . esc_html( $name ) . '</strong></span>';
	}
	/** Image placeholder and native media-library chooser for payout logos. */
	public static function payment_icon_field( $name, $value, $label ) {
		echo '<div class="refm-payment-icon-field"><span class="refm-payment-icon-label">' . esc_html( $label ) . '</span><input type="hidden" class="refm-payment-icon-value" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"><button type="button" class="refm-choose-payment-icon" aria-label="' . esc_attr( $label . ': ' . __( 'Choose icon', 'refmora' ) ) . '" title="' . esc_attr__( 'Choose or change icon', 'refmora' ) . '"><img class="refm-payment-icon-preview"' . ( $value ? ' src="' . esc_url( $value ) . '"' : '' ) . ' alt="" width="64" height="64"' . ( $value ? '' : ' hidden' ) . '><span class="refm-payment-icon-placeholder"' . ( $value ? ' hidden' : '' ) . '>' . wp_kses( self::icon( 'image' ), self::allowed_html() ) . '<span>' . esc_html__( 'Choose icon', 'refmora' ) . '</span></span></button><button type="button" class="refm-clear-payment-icon"' . ( $value ? '' : ' hidden' ) . '>' . esc_html__( 'Remove icon', 'refmora' ) . '</button></div>';
	}
	/** Allow only the markup used by the plugin's rendered components. */
	public static function allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );
		$allowed['img']['hidden'] = true;
		$allowed['img']['data-skip-lazy'] = true;
		$allowed['svg'] = array_fill_keys( array( 'class', 'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'aria-hidden', 'focusable' ), true );
		foreach ( array( 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'g' ) as $tag ) {
			$allowed[ $tag ] = array_fill_keys( array( 'd', 'fill', 'stroke', 'stroke-width', 'cx', 'cy', 'r', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'width', 'height', 'rx', 'ry', 'points', 'transform' ), true );
		}
		$allowed['input'] = array_fill_keys( array( 'type', 'name', 'id', 'class', 'value', 'min', 'max', 'step', 'placeholder', 'readonly', 'disabled', 'required', 'autocomplete', 'aria-label', 'data-refm-reset' ), true );
		$allowed['button'] = array_fill_keys( array( 'type', 'class', 'title', 'aria-label', 'data-refm-share', 'data-refm-link' ), true );
		return $allowed;
	}
	public static function icon( $name ) {
		$paths = array(
			'trash' => 'M3 6h18 M9 6V3h6v3 M5 6l1 15h12l1-15 M10 10v7 M14 10v7',
			'restore' => 'M3 11a9 9 0 1 1 2.7 7 M3 4v7h7',
			'image' => 'M3 3h18v18H3z M3 16l6-6 5 5 3-3 4 4 M16 7h.01',
			'note' => 'M5 3h14v18H5z M8 8h8 M8 12h8 M8 16h5',
			'close' => 'M6 6l12 12 M18 6L6 18',
			'view' => 'M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
			'copy' => 'M9 9h12v12H9z M5 15H3V3h12v2',
			'alert' => 'M12 3L2 21h20L12 3z M12 9v5 M12 17h.01',
			'wallet' => 'M4 6h14V4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h15V8H5a2 2 0 0 1-2-2 M20 12h-5v5h5 M16 14.5h.01',
			'send' => 'M21 3L3 10l7 3 3 8 8-18z M10 13L21 3',
			'award' => 'M12 3l3 2 3 .5 .5 3 2 3-2 3-.5 3-3 .5-3 2-3-2-3-.5-.5-3-2-3 2-3 .5-3 3-.5 3-2z M8 12l3 3 5-6',
			'user' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z M5 21v-3a7 7 0 0 1 14 0v3H5z',
			'tools' => 'M12 3v12 M7 10l5 5 5-5 M4 16v5h16v-5',
			'license' => 'M14 3a7 7 0 1 1-5 12L3 21H1v-4l6-6a7 7 0 0 1 7-8 M16 7h.01',
			'lock' => 'M5 10h14v11H5z M8 10V6a4 4 0 0 1 8 0v4 M12 14v3',
			'overview' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
			'affiliates' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-4 M16 3a4 4 0 0 1 0 8',
			'customers' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z M5 21v-3a7 7 0 0 1 14 0v3H5z',
			'commissions' => 'M4 20h16 M6 16V9 M12 16V4 M18 16v-5',
			'refer_links' => 'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2 M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2',
			'payouts' => 'M3 5h18v15H3z M3 9h18 M16 14h3 M7 3v2',
			'settings' => 'M4 6h16 M4 12h16 M4 18h16 M8 3v6 M16 9v6 M10 15v6',
			'sharing' => 'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M9 10l6-4 M9 14l6 4',
			'emails' => 'M3 5h18v14H3z M3 6l9 7 9-7',
			'customization' => 'M12 3a9 9 0 1 0 0 18h2a2 2 0 0 0 1-4 2 2 0 0 1 1-4h2a3 3 0 0 0 3-3 9 9 0 0 0-9-7 M7 10h.01 M10 6h.01 M15 7h.01',
			'link' => 'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2 M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2',
			'clock' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l3 2',
			'check' => 'M5 12l4 4L19 6',
			'check-circle' => 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z M8 12l3 3 5-6',
			'arrow' => 'M4 12h16 M15 7l5 5-5 5',
			'click' => 'M5 3l4 17 4-7 7-4z',
			'shield' => 'M12 2l8 4v6c0 5-8 10-8 10S4 17 4 12V6z M8 12l3 3 5-6',
		);
		return '<svg class="refm-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $paths[ $name ] ?? $paths['overview'] ) . '"></path></svg>';
	}
	public static function metric( $label, $value, $icon, $note = '', $url = '' ) {
		$tones = array( 'clock' => 'amber', 'wallet' => 'green', 'payouts' => 'green', 'send' => 'blue', 'sharing' => 'blue', 'award' => 'purple', 'check' => 'purple', 'click' => 'slate', 'user' => 'purple', 'affiliates' => 'purple' );
		$url = esc_url( $url );
		$tag = $url ? 'a' : 'div';
		return '<' . $tag . ( $url ? ' href="' . $url . '"' : '' ) . ' class="refm-metric refm-metric-tone-' . esc_attr( $tones[ $icon ] ?? 'blue' ) . '"><div class="refm-metric-top"><span>' . esc_html( $label ) . '</span><span class="refm-icon-tile">' . wp_kses( self::icon( $icon ), REFM_UI::allowed_html() ) . '</span></div><strong>' . wp_kses_post( $value ) . '</strong>' . ( $note ? '<small>' . esc_html( $note ) . '</small>' : '' ) . '</' . $tag . '>';
	}
	/** Scoped feedback avoids WordPress moving native .notice elements into our header. */
	public static function toast( $message, $error = false ) {
		if ( ! is_scalar( $message ) || '' === trim( (string) $message ) ) { return; }
		$error = (bool) $error;
		echo '<div class="refm-toast refm-toast-' . ( $error ? 'error' : 'success' ) . '" role="' . ( $error ? 'alert' : 'status' ) . '" aria-live="' . ( $error ? 'assertive' : 'polite' ) . '" aria-atomic="true" data-refm-toast data-autodismiss="' . ( $error ? 'no' : 'yes' ) . '"><span class="refm-toast-icon">' . wp_kses( self::icon( $error ? 'alert' : 'check' ), REFM_UI::allowed_html() ) . '</span><div class="refm-toast-content"><strong>' . esc_html( $error ? __( 'Action needs attention', 'refmora' ) : __( 'Action completed', 'refmora' ) ) . '</strong><p>' . esc_html( $message ) . '</p></div><button type="button" class="refm-toast-dismiss refm-primary" aria-label="' . esc_attr__( 'Dismiss notification', 'refmora' ) . '">' . wp_kses( self::icon( 'close' ), REFM_UI::allowed_html() ) . '</button></div>';
	}
	public static function footer( $label, $form_id = '' ) {
		echo '<div class="refm-save-bar"><span class="refm-save-state" role="status" aria-live="polite">' . esc_html__( 'Changes apply after saving.', 'refmora' ) . '</span><button type="submit"' . ( $form_id ? ' form="' . esc_attr( $form_id ) . '"' : '' ) . ' class="button button-primary refm-primary">' . wp_kses( self::icon( 'check' ), REFM_UI::allowed_html() ) . esc_html( $label ) . '</button></div>';
	}
}
