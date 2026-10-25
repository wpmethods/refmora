<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Tools {
	const MAX_BACKUP_SIZE = 1048576;
	public static function init() { add_action( 'admin_post_refm_tools', array( __CLASS__, 'submit' ) ); }
	public static function guard( $operation ) {
		REFM_Security::admin_request( 'refm_tools' );
		if ( 'csv' !== $operation && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only administrators can manage these tools.', 'refmora' ), '', array( 'response' => 403 ) ); }

	}
	public static function backup() {
		return array( 'format' => 'refmora-settings', 'schema_version' => 1, 'plugin_version' => REFM_VERSION, 'created_at' => gmdate( 'c' ), 'settings' => array( 'program' => REFM_Settings::stored(), 'sharing' => REFM_Sharing::get(), 'emails' => REFM_Emails::get(), 'appearance' => REFM_Design::get() ) );
	}
	public static function decode_backup( $json ) {
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BACKUP_SIZE ) { return new WP_Error( 'backup', __( 'Choose a settings JSON backup smaller than 1 MB.', 'refmora' ) ); }
		$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $json ), true, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || ! in_array( $data['format'] ?? '', array( 'refmora-settings', 'affmint-settings' ), true ) || 1 !== ( $data['schema_version'] ?? null ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) || count( $data['settings'] ) !== 4 ) { return new WP_Error( 'backup', __( 'This is not a supported Refmora settings backup.', 'refmora' ) ); }
		foreach ( array( 'program', 'sharing', 'emails', 'appearance' ) as $key ) { if ( ! isset( $data['settings'][ $key ] ) || ! is_array( $data['settings'][ $key ] ) || empty( $data['settings'][ $key ] ) ) { return new WP_Error( 'backup', __( 'The backup is incomplete. No settings were changed.', 'refmora' ) ); } }
		return array( 'refm_settings' => REFM_Settings::clean( $data['settings']['program'] ), 'refm_sharing' => REFM_Sharing::clean( $data['settings']['sharing'] ), 'refm_emails' => REFM_Emails::clean( $data['settings']['emails'] ), 'refm_design' => REFM_Design::clean( $data['settings']['appearance'] ) );
	}
	public static function defaults() {
		$emails = array( 'admin_email' => get_option( 'admin_email' ), 'from_email' => get_option( 'admin_email' ), 'from_name' => get_bloginfo( 'name' ), 'templates' => array() );
		foreach ( REFM_Emails::definitions() as $key => $template ) { $emails['templates'][ $key ] = array( 'enabled' => 'yes', 'subject' => $template['subject'], 'body' => $template['body'] ); }
		return array( 'refm_settings' => REFM_Settings::defaults(), 'refm_sharing' => REFM_Sharing::defaults(), 'refm_emails' => REFM_Emails::clean( $emails ), 'refm_design' => REFM_Design::defaults() );
	}
	private static function apply( $settings ) { foreach ( $settings as $key => $value ) { update_option( $key, $value, false ); } }
	private static function upload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Private upload helper runs only after guard() verifies POST, capability and refm_tools nonce. Validate the uploaded-file scalar path using is_uploaded_file, size and JSON schema checks; do not alter the OS temporary path.
		$file = isset( $_FILES['backup'] ) && is_array( $_FILES['backup'] ) ? $_FILES['backup'] : array();
		$path = REFM_Security::value( $file, 'tmp_name' );
		if ( '0' !== REFM_Security::value( $file, 'error', '-1' ) || ! $path || ! is_uploaded_file( $path ) || filesize( $path ) > self::MAX_BACKUP_SIZE ) { return new WP_Error( 'upload', __( 'Upload a valid JSON settings backup, maximum 1 MB.', 'refmora' ) ); }
		return self::decode_backup( file_get_contents( $path ) );
	}
	public static function submit() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'refmora' ), '', array( 'response' => 403 ) ); }
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) { wp_die( esc_html__( 'Use the settings form to make changes.', 'refmora' ), '', array( 'response' => 405 ) ); }
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) { wp_die( esc_html__( 'Your session expired. Refresh and try again.', 'refmora' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'refm_tools' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( ! in_array( $operation, array( 'backup', 'restore', 'reset', 'clear', 'csv' ), true ) ) { wp_die( esc_html__( 'Unknown tools action.', 'refmora' ), '', array( 'response' => 400 ) ); }
		if ( 'csv' !== $operation && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only administrators can manage these tools.', 'refmora' ), '', array( 'response' => 403 ) ); }
		if ( 'backup' === $operation ) { self::download_headers( 'settings', 'json', 'application/json' ); echo wp_json_encode( self::backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); exit; }
		if ( 'csv' === $operation ) { do_action( 'refm_export_data_request' ); wp_die( esc_html__( 'CSV export is available in the separate Refmora Pro add-on.', 'refmora' ), '', array( 'response' => 403 ) ); }
		if ( 'restore' === $operation ) { $result = self::upload(); if ( ! is_wp_error( $result ) ) { self::apply( $result ); $result = __( 'Settings restored successfully.', 'refmora' ); } }
		elseif ( 'clear' === $operation ) {
			$result = REFM_Data::clear( isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '' );
		}
		elseif ( 'reset' === $operation ) {
			if ( 'RESET' !== ( isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '' ) ) { $result = new WP_Error( 'confirm', __( 'Type RESET to confirm resetting plugin settings.', 'refmora' ) ); }
			else { self::apply( self::defaults() ); $result = __( 'Plugin settings reset to defaults. Your license and financial records were preserved.', 'refmora' ); }
		} else { $result = new WP_Error( 'operation', __( 'Unknown tools action.', 'refmora' ) ); }
		set_transient( 'refm_tools_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : $result ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=refmora&tab=tools' ) ); exit;
	}


	private static function download_headers( $name, $extension, $mime ) {
		while ( ob_get_level() ) { if ( ! ob_end_clean() ) { break; } }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: ' . $mime . '; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="refmora-' . $name . '-' . gmdate( 'Y-m-d' ) . '.' . $extension . '"' );
	}






	private static function form( $operation, $upload = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="refm_tools"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'; wp_nonce_field( 'refm_tools' );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$notice = get_transient( 'refm_tools_notice_' . get_current_user_id() ); if ( is_array( $notice ) ) { delete_transient( 'refm_tools_notice_' . get_current_user_id() ); REFM_UI::toast( $notice['message'] ?? '', ! empty( $notice['error'] ) ); }
		if ( current_user_can( 'manage_options' ) ) {
			$disabled = '';
			echo '<div class="refm-form-grid"><section class="refm-card"><h2>' . wp_kses( REFM_UI::icon( 'tools' ), REFM_UI::allowed_html() ) . esc_html__( 'Back up settings', 'refmora' ) . '</h2><p class="refm-muted">' . esc_html__( 'Download program, sharing, email and appearance settings as a JSON backup.', 'refmora' ) . '</p>'; self::form( 'backup' ); echo '<button class="button refm-primary">' . esc_html__( 'Download settings backup', 'refmora' ) . '</button></form></section>';
			echo '<section class="refm-card"><h2>' . esc_html__( 'Restore settings', 'refmora' ) . '</h2><p class="refm-muted">' . esc_html__( 'Upload a settings backup to replace those four settings groups. Maximum size: 1 MB.', 'refmora' ) . '</p>'; self::form( 'restore', true ); echo '<div class="refm-tools-action-row"><div class="refm-field"><label for="refm-backup-file">' . esc_html__( 'Settings backup (.json)', 'refmora' ) . '</label><input type="file" id="refm-backup-file" name="backup" accept=".json,application/json" required' . esc_attr( $disabled ) . '></div><button class="button refm-primary"' . esc_attr( $disabled ) . '>' . esc_html__( 'Restore settings', 'refmora' ) . '</button></div></form></section></div>';
			echo '<section class="refm-card"><h2>' . esc_html__( 'Reset plugin settings', 'refmora' ) . '</h2><p class="refm-muted">' . esc_html__( 'Restore program, sharing, email and appearance defaults. Your license, affiliate accounts, per-product commission rules and financial history are kept. Download a backup first.', 'refmora' ) . '</p>'; self::form( 'reset' ); echo '<div class="refm-tools-action-row"><div class="refm-field"><label for="refm-reset-confirmation">' . esc_html__( 'Type RESET to confirm', 'refmora' ) . '</label><input type="text" id="refm-reset-confirmation" name="confirmation" placeholder="RESET" pattern="RESET" maxlength="5" required autocomplete="off"' . esc_attr( $disabled ) . '></div><button class="button refm-primary"' . esc_attr( $disabled ) . '>' . esc_html__( 'Reset settings', 'refmora' ) . '</button></div></form>';
			if ( $disabled ) { echo '<p class="refm-muted">' . esc_html__( 'Restore and reset are available in Free.', 'refmora' ) . '</p>'; } echo '</section>';
			echo '<section class="refm-card refm-clear-data"><h2>' . wp_kses( REFM_UI::icon( 'trash' ), REFM_UI::allowed_html() ) . esc_html__( 'Clear data', 'refmora' ) . '</h2><p class="refm-muted">' . esc_html__( 'Permanently delete all Refmora affiliate enrollments, referral links, clicks, referred-customer records, commissions and withdrawal history. WooCommerce customer accounts and orders, plugin settings, product rules and license remain unchanged. This cannot be undone.', 'refmora' ) . '</p>';
			self::form( 'clear' );
			echo '<div class="refm-tools-action-row"><div class="refm-field"><label for="refm-clear-confirmation">' . esc_html__( 'Type CLEAR DATA to confirm', 'refmora' ) . '</label><input type="text" id="refm-clear-confirmation" name="confirmation" placeholder="CLEAR DATA" pattern="CLEAR DATA" maxlength="10" required autocomplete="off"></div><button class="button refm-primary refm-danger">' . wp_kses( REFM_UI::icon( 'trash' ), REFM_UI::allowed_html() ) . esc_html__( 'Clear data', 'refmora' ) . '</button></div></form></section>';
		}
		if ( has_action( 'refm_export_data_panel' ) ) { do_action( 'refm_export_data_panel' ); } else { REFM_License::premium_card( __( 'Export data', 'refmora' ), __( 'CSV exports of affiliates, commissions, withdrawals, customers and referral clicks are provided by the separate Pro add-on.', 'refmora' ) ); }
	}
}
