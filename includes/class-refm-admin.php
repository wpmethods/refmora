<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Admin {
	const PAGE_SIZE = 10;
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( 'woocommerce_page_refmora' === $hook ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; loading the media chooser does not modify settings or attachments.
				if ( 'settings' === REFM_Security::value( $_GET, 'tab' ) ) {
					wp_enqueue_media();
					wp_enqueue_style( 'dashicons' );
				}
				wp_enqueue_style( 'refm-admin', REFM_URL . 'assets/refmora.css', array(), (string) filemtime( REFM_PATH . 'assets/refmora.css' ) );
				wp_enqueue_style( 'refm-panel', REFM_URL . 'assets/panel.css', array( 'refm-admin' ), (string) filemtime( REFM_PATH . 'assets/panel.css' ) );
				REFM_Design::enqueue( 'refm-panel' );
				wp_enqueue_script( 'refm-admin', REFM_URL . 'assets/admin.js', array(), (string) filemtime( REFM_PATH . 'assets/admin.js' ), true );
				wp_enqueue_script( 'refm-refer-links', REFM_URL . 'assets/refer-links.js', array(), (string) filemtime( REFM_PATH . 'assets/refer-links.js' ), true );
				wp_localize_script( 'refm-admin', 'refmAdmin', array(
					'unsaved'             => __( 'You have unsaved changes.', 'refmora' ),
					'saving'              => __( 'Saving your changes...', 'refmora' ),
					'clearing_trash'      => __( 'Clearing Trash...', 'refmora' ),
					'clear_trash_done'    => __( 'Trash cleared.', 'refmora' ),
					'clear_trash_partial' => __( 'Some items could not be deleted.', 'refmora' ),
					'clear_trash_error'   => __( 'Clearing could not finish. Some items may have been deleted. Refresh Trash before trying again.', 'refmora' ),
					/* translators: %d: number of records permanently deleted so far. */
					'trash_removed'       => __( 'Deleted: %d', 'refmora' ),
				) );
			}
		} );
		add_action( 'admin_post_refm_payout', array( __CLASS__, 'payout_action' ) );
		add_action( 'admin_post_refm_commission', array( __CLASS__, 'commission_action' ) );
		add_action( 'admin_post_refm_record', array( __CLASS__, 'record_action' ) );
		add_action( 'admin_post_refm_clear_trash', array( __CLASS__, 'clear_trash_action' ) );
		add_action( 'wp_ajax_refm_clear_trash', array( __CLASS__, 'clear_trash_ajax' ) );
		add_action( 'admin_post_refm_affiliate', array( __CLASS__, 'affiliate_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( REFM_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=refmora&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'refmora' ) . '</a>' );
			return $links;
		} );
	}
	public static function pending_affiliates_count() {
		$query = new WP_User_Query( array(
			'fields' => 'ID', 'number' => 1, 'count_total' => true, 'cache_results' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required enrollment/pending predicates; WordPress caches IDs and totals and invalidates them when user metadata changes.
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => '_refm_enrolled', 'value' => 'yes' ),
				array( 'key' => '_refm_approval', 'value' => 'pending' ),
			),
		) );
		return absint( $query->get_total() );
	}
	public static function pending_counts() {
		global $wpdb;
		$commissions = REFM_Store::table( 'commissions' );
		$payouts = REFM_Store::table( 'payouts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$counts = $wpdb->get_row( $wpdb->prepare( 'SELECT (SELECT COUNT(*) FROM %i WHERE status=%s) AS commissions, (SELECT COUNT(*) FROM %i WHERE status=%s) AS payouts', $commissions, 'pending', $payouts, 'pending' ), ARRAY_A );
		return array( 'affiliates' => self::pending_affiliates_count(), 'commissions' => absint( $counts['commissions'] ?? 0 ), 'payouts' => absint( $counts['payouts'] ?? 0 ) );
	}
	public static function pending_badge( $count ) {
		$count = absint( $count ); if ( ! $count ) { return ''; }
		/* translators: %s: formatted count of pending items. */
		$label = sprintf( _n( '%s pending item', '%s pending items', $count, 'refmora' ), number_format_i18n( $count ) );
		return '<span class="refm-nav-count" title="' . esc_attr( $label ) . '"><span aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
	}
	public static function menu() {
		$title = 'Refmora Affiliate';
		$count = current_user_can( 'manage_woocommerce' ) ? self::pending_affiliates_count() : 0;
		if ( $count ) {
		/* translators: %s: formatted count of pending affiliate applications. */
			$description = sprintf( _n( '%s pending affiliate application', '%s pending affiliate applications', $count, 'refmora' ), number_format_i18n( $count ) );
			$title .= ' <span class="update-plugins count-' . $count . '"><span class="plugin-count" aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $description ) . '</span></span>';
		}
		add_submenu_page( 'woocommerce', 'Refmora Affiliate', $title, 'manage_woocommerce', 'refmora', array( __CLASS__, 'render' ) );
	}
	public static function payout_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'refmora' ), 403 ); }
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) { wp_die( esc_html__( 'Use the settings form to make changes.', 'refmora' ), '', array( 'response' => 405 ) ); }
		$id = absint( isset( $_POST['payout_id'] ) && is_scalar( $_POST['payout_id'] ) ? wp_unslash( $_POST['payout_id'] ) : 0 );
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) { wp_die( esc_html__( 'Your session expired. Refresh and try again.', 'refmora' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'refm_payout_' . $id );
		$status = sanitize_key( ( isset( $_POST[ 'status' ] ) && is_scalar( $_POST[ 'status' ] ) ? wp_unslash( $_POST[ 'status' ] ) : '' ) );
		$note = sanitize_textarea_field( ( isset( $_POST[ 'note' ] ) && is_scalar( $_POST[ 'note' ] ) ? wp_unslash( $_POST[ 'note' ] ) : '' ) );
		$result = REFM_Payouts::transition( $id, $status, $note );
		$url = add_query_arg( array( 'page' => 'refmora', 'tab' => 'payouts', 'refm_message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Withdrawal updated.', 'refmora' ), 'refm_error' => is_wp_error( $result ) ? 1 : 0 ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url ); exit;
	}
	/** Process a nonce-protected manager decision, never a GET request. */
	public static function commission_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read the scalar ID solely to select its nonce; admin_request() verifies capability, POST and that nonce before any mutation.
		$id = absint( REFM_Security::value( $_POST, 'commission_id' ) );
		REFM_Security::admin_request( 'refm_commission_' . $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- admin_request() above verified POST, manage_woocommerce and this commission's nonce.
		$decision = sanitize_key( wp_unslash( REFM_Security::value( $_POST, 'decision' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- admin_request() above verified POST, manage_woocommerce and this commission's nonce.
		$version = sanitize_text_field( wp_unslash( REFM_Security::value( $_POST, 'review_version' ) ) );
		$result  = REFM_Commissions::review( $id, $decision, $version );
		$message = is_wp_error( $result ) ? $result->get_error_message() : ( 'confirmed' === $decision ? __( 'Commission confirmed. Earnings are now available for withdrawal.', 'refmora' ) : __( 'Commission rejected.', 'refmora' ) );
		$url     = add_query_arg(
			array(
				'page'         => 'refmora',
				'tab'          => 'commissions',
				'refm_error'   => is_wp_error( $result ) ? 1 : 0,
				'refm_message' => $message,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** Handle administrator record removal with a type-, ID- and operation-specific nonce. */
	public static function record_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read only scalar routing values to choose the nonce checked immediately below, before any mutation.
		$type = sanitize_key( REFM_Security::value( $_POST, 'record_type' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read only the scalar record ID for nonce selection.
		$id = absint( REFM_Security::value( $_POST, 'record_id' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read only the scalar operation for nonce selection.
		$operation = sanitize_key( REFM_Security::value( $_POST, 'operation' ) );
		REFM_Security::admin_request( 'refm_record_' . $type . '_' . $id . '_' . $operation );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- admin_request() verified POST, manage_woocommerce and the record-specific nonce above.
		$version = sanitize_text_field( wp_unslash( REFM_Security::value( $_POST, 'record_version' ) ) );
		if ( 'affiliates' === $type && 'delete' === $operation ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The record-specific nonce and POST capability guard have already passed.
			$confirmation = sanitize_text_field( wp_unslash( REFM_Security::value( $_POST, 'confirmation' ) ) );
			$result = REFM_Records::delete_affiliate( $id, $confirmation, $version );
		} else {
			$result = REFM_Records::change( $type, $id, $operation, $version );
		}
		$url = add_query_arg(
			array(
				'page'         => 'refmora',
				'tab'          => in_array( $type, array( 'affiliates', 'commissions', 'payouts' ), true ) ? $type : 'affiliates',
				'view'         => 'affiliates' !== $type && in_array( $operation, array( 'restore', 'delete' ), true ) ? 'trash' : '',
				'refm_error'   => is_wp_error( $result ) ? 1 : 0,
				'refm_message' => is_wp_error( $result ) ? $result->get_error_message() : $result,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** @return string Nonce-verified record type for a Clear All Trash request. */
	private static function clear_trash_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This scalar chooses the type-specific nonce verified immediately below, before clearing any record.
		$type = sanitize_key( REFM_Security::value( $_POST, 'record_type' ) );
		REFM_Security::admin_request( 'refm_clear_trash_' . $type );
		return $type;
	}

	/** Process one bounded batch so a large Trash does not require one long request. */
	public static function clear_trash_ajax() {
		$type = self::clear_trash_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- clear_trash_request() verified POST, capability and the type-specific nonce above.
		$cursor = absint( REFM_Security::value( $_POST, 'cursor', '0' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- clear_trash_request() verified POST, capability and the type-specific nonce above.
		$ceiling = REFM_Security::value( $_POST, 'ceiling' );
		$result  = REFM_Records::clear_trash( $type, $cursor, '' === $ceiling ? null : absint( $ceiling ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 'access' === $result->get_error_code() ? 403 : 400 );
		}
		wp_send_json_success( $result );
	}

	/** Clear ordinary-sized Trash without JavaScript, reporting any partial result. */
	public static function clear_trash_action() {
		$type    = self::clear_trash_request();
		$cursor  = 0;
		$ceiling = null;
		$deleted = 0;
		$skipped = 0;
		$done    = false;
		$error   = '';
		$started = microtime( true );
		do {
			$result = REFM_Records::clear_trash( $type, $cursor, $ceiling );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
				break;
			}
			$deleted += $result['deleted'];
			$skipped += $result['skipped'];
			$cursor   = $result['cursor'];
			$ceiling  = $result['ceiling'];
			$done     = $result['done'];
			if ( '' === $error && '' !== $result['first_error'] ) {
				$error = $result['first_error'];
			}
		} while ( ! $done && microtime( true ) - $started < 10 );
		/* translators: %d: number of permanently deleted records. */
		$message = sprintf( _n( '%d item permanently deleted.', '%d items permanently deleted.', $deleted, 'refmora' ), $deleted );
		if ( $skipped || ! $done || $error ) {
			$message .= ' ' . ( $error ?: __( 'Some items were not deleted. Resolve any related withdrawals and run Clear All Trash again.', 'refmora' ) );
		}
		$url = add_query_arg(
			array(
				'page'         => 'refmora',
				'tab'          => in_array( $type, array( 'commissions', 'payouts' ), true ) ? $type : 'commissions',
				'view'         => 'trash',
				'refm_error'   => $skipped || ! $done || $error ? 1 : 0,
				'refm_message' => $message,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** @return bool Whether an administrator requested the reversible Trash view. */
	public static function trash_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list routing; deletion and restoration require separate POST actions and nonces.
		return REFM_Records::allowed() && 'trash' === REFM_Security::value( $_GET, 'view' );
	}

	/**
	 * Render deletion or restoration for the exact record displayed.
	 *
	 * @param string     $type Record type.
	 * @param object|int $row  Financial row or affiliate user ID.
	 */
	public static function record_actions( $type, $row ) {
		if ( ! REFM_Records::allowed() ) {
			return;
		}
		$affiliate = 'affiliates' === $type;
		if ( ! $affiliate && ( 'processing' === $row->status || ( 'commissions' === $type && $row->payout_id ) ) ) {
			echo '<span class="refm-muted">' . esc_html__( 'Resolve withdrawal first', 'refmora' ) . '</span>';
			return;
		}
		$id         = $affiliate ? absint( $row ) : absint( $row->id );
		$operations = $affiliate ? array( 'delete' ) : ( empty( $row->trashed_at ) ? array( 'trash' ) : array( 'restore', 'delete' ) );
		$version    = $affiliate ? REFM_Records::affiliate_version( $id ) : REFM_Records::version( $row );
		if ( $affiliate ) {
			echo '<details class="refm-delete-record"><summary>' . esc_html__( 'Delete', 'refmora' ) . '</summary><p class="refm-muted">' . esc_html__( 'Permanently delete this affiliate profile, commissions, withdrawals, referred-customer associations and click history. Their WordPress account and WooCommerce orders remain. This cannot be undone.', 'refmora' ) . '</p>';
		} else {
			echo '<div class="refm-record-actions">';
		}
		foreach ( $operations as $operation ) {
			echo '<form class="refm-record-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="refm_record"><input type="hidden" name="record_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="record_id" value="' . $id . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="record_version" value="' . esc_attr( $version ) . '">';
			wp_nonce_field( 'refm_record_' . $type . '_' . $id . '_' . $operation );
			if ( $affiliate ) {
				echo '<label for="refm-delete-affiliate-' . $id . '">' . esc_html__( 'Type DELETE to confirm', 'refmora' ) . '</label><input id="refm-delete-affiliate-' . $id . '" name="confirmation" type="text" pattern="DELETE" maxlength="6" required autocomplete="off">';
			}
			$confirm = 'payouts' === $type ? __( 'Move this withdrawal to Trash? Pending or approved requests will be cancelled and reserved funds released. Restore will not reopen a cancelled request.', 'refmora' ) : __( 'Move this commission to Trash? Unpaid earnings will be removed until restored. Paid history and refund adjustments are preserved.', 'refmora' );
			if ( 'delete' === $operation && ! $affiliate ) {
				$confirm = __( 'Permanently delete this record? This cannot be undone. Removing commission history may change financial totals.', 'refmora' );
			}
			$delete_link  = ! $affiliate && in_array( $operation, array( 'trash', 'delete' ), true );
			$button_class = 'restore' === $operation ? 'refm-restore-link' : ( $delete_link ? 'refm-delete-link' : 'button refm-primary refm-danger' );
			echo '<button type="submit" class="' . esc_attr( $button_class ) . '"' . ( $delete_link ? ' data-refm-confirm="' . esc_attr( $confirm ) . '"' : '' ) . ( $delete_link && 'delete' === $operation ? ' aria-label="' . esc_attr__( 'Delete permanently', 'refmora' ) . '" title="' . esc_attr__( 'Delete permanently', 'refmora' ) . '"' : '' ) . '>';
			if ( $delete_link || 'restore' === $operation ) {
				echo wp_kses( REFM_UI::icon( 'restore' === $operation ? 'restore' : 'trash' ), REFM_UI::allowed_html() );
			}
			echo esc_html( $affiliate ? __( 'Delete permanently', 'refmora' ) : ( 'restore' === $operation ? __( 'Restore', 'refmora' ) : __( 'Delete', 'refmora' ) ) ) . '</button></form>';
		}
		if ( $affiliate ) {
			echo '</details>';
		} else {
			echo '</div>';
		}
	}

	/**
	 * Render record views with their full, unfiltered item totals.
	 *
	 * @param string $type  Financial record type.
	 * @param bool   $trash Whether Trash is selected.
	 */
	public static function record_views( $type, $trash ) {
		if ( ! REFM_Records::allowed() ) {
			return;
		}
		$counts = REFM_Records::counts( $type );
		$views  = array(
			''      => __( 'Records', 'refmora' ),
			'trash' => __( 'Trash', 'refmora' ),
		);
		echo '<div class="refm-record-view-bar">';
		echo '<nav class="refm-record-views" aria-label="' . esc_attr__( 'Record views', 'refmora' ) . '">';
		foreach ( $views as $view => $label ) {
			$selected = ( 'trash' === $view ) === (bool) $trash;
			$count    = $counts[ 'trash' === $view ? 'trash' : 'records' ];
			echo '<a class="refm-record-view' . ( $selected ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'refmora', 'tab' => $type, 'view' => $view ), admin_url( 'admin.php' ) ) ) . '"' . ( $selected ? ' aria-current="page"' : '' ) . '>';
			echo wp_kses( REFM_UI::icon( 'trash' === $view ? 'trash' : 'commissions' ), REFM_UI::allowed_html() );
			echo '<span>' . esc_html( $label ) . '</span> <span class="refm-record-view-count">' . esc_html( number_format_i18n( $count ) ) . '</span></a>';
		}
		echo '</nav>';
		if ( $trash ) {
			$return_url = add_query_arg( array( 'page' => 'refmora', 'tab' => $type, 'view' => 'trash' ), admin_url( 'admin.php' ) );
			$confirm = __( 'Permanently delete every item in this Trash, across all pages and filters? This cannot be undone and may change financial totals.', 'refmora' );
			echo '<form class="refm-clear-trash-form" data-refm-empty-trash data-refm-return-url="' . esc_url( $return_url ) . '" data-refm-confirm="' . esc_attr( $confirm ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="refm_clear_trash"><input type="hidden" name="record_type" value="' . esc_attr( $type ) . '">';
			wp_nonce_field( 'refm_clear_trash_' . $type );
			echo '<button type="submit" class="button refm-clear-trash"' . disabled( 0 === $counts['trash'], true, false ) . '>' . wp_kses( REFM_UI::icon( 'trash' ), REFM_UI::allowed_html() ) . '<span data-refm-clear-label>' . esc_html__( 'Clear All Trash', 'refmora' ) . '</span></button><span class="refm-clear-trash-status screen-reader-text" role="status" aria-live="polite" data-refm-clear-status></span></form>';
		}
		echo '</div>';
	}

	public static function affiliate_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'refmora' ), 403 ); }
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) { wp_die( esc_html__( 'Use the settings form to make changes.', 'refmora' ), '', array( 'response' => 405 ) ); }
		$id = absint( isset( $_POST['affiliate_id'] ) && is_scalar( $_POST['affiliate_id'] ) ? wp_unslash( $_POST['affiliate_id'] ) : 0 );
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) { wp_die( esc_html__( 'Your session expired. Refresh and try again.', 'refmora' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'refm_affiliate_' . $id );
		$operation = sanitize_key( ( isset( $_POST[ 'operation' ] ) && is_scalar( $_POST[ 'operation' ] ) ? wp_unslash( $_POST[ 'operation' ] ) : '' ) ); $result = true;
		if ( in_array( $operation, array( 'approved', 'rejected' ), true ) ) { $result = REFM_Affiliates::review( $id, $operation ); }
		elseif ( get_userdata( $id ) && in_array( $operation, array( 'block', 'unblock' ), true ) ) { $result = REFM_Affiliates::set_blocked( $id, 'block' === $operation ); }
		else { $result = new WP_Error( 'invalid', __( 'Invalid affiliate action.', 'refmora' ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'refmora', 'tab' => 'affiliates', 'refm_error' => is_wp_error( $result ) ? 1 : 0, 'refm_message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Affiliate account updated.', 'refmora' ) ), admin_url( 'admin.php' ) ) ); exit;
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$tabs = array( 'overview' => __( 'Overview', 'refmora' ), 'affiliates' => __( 'Affiliates', 'refmora' ), 'refer_links' => __( 'Refer Links', 'refmora' ), 'customers' => __( 'Customers', 'refmora' ), 'commissions' => __( 'Commissions', 'refmora' ), 'payouts' => __( 'Withdrawals', 'refmora' ), 'settings' => __( 'Settings', 'refmora' ), 'sharing' => __( 'Sharing', 'refmora' ), 'emails' => __( 'Emails', 'refmora' ), 'customization' => __( 'Customization', 'refmora' ) );
		$descriptions = array( 'overview' => __( 'A clear view of your partner program and recent activity.', 'refmora' ), 'affiliates' => __( 'Review applications and manage your affiliate community.', 'refmora' ), 'commissions' => __( 'Follow order earnings, adjustments and settled commissions.', 'refmora' ), 'payouts' => __( 'Review withdrawal requests and record your manual payments.', 'refmora' ), 'settings' => __( 'Set the rules for joining, commissions, referrals and withdrawals.', 'refmora' ), 'sharing' => __( 'Choose how partners share your products.', 'refmora' ), 'emails' => __( 'Keep affiliates informed with clear, personal notifications.', 'refmora' ), 'customization' => __( 'Make the affiliate experience feel like your brand.', 'refmora' ) );
		$tabs['tools'] = __( 'Tools', 'refmora' );
		if ( current_user_can( 'manage_options' ) ) { $tabs['license'] = class_exists( 'REFM_Pro_License', false ) ? __( 'License', 'refmora' ) : __( 'Premium', 'refmora' ); }
		$descriptions['refer_links'] = __( 'Browse referral URLs and their tracked clicks.', 'refmora' );
		$descriptions['customers'] = __( 'Customers registered through affiliate referral links.', 'refmora' );
		$descriptions['tools'] = __( 'Back up and restore settings, reset defaults, clear Refmora data and export your program records.', 'refmora' );
		$descriptions['license'] = __( 'Manage your license and receive updates from WP Methods.', 'refmora' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$tab = sanitize_key( ( isset( $_GET[ 'tab' ] ) && is_scalar( $_GET[ 'tab' ] ) ? wp_unslash( $_GET[ 'tab' ] ) : 'overview' ) );
		if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'overview'; }
		$active = REFM_Settings::program_enabled();
		$range = 'overview' === $tab ? REFM_Reports::requested_range() : array();
		echo '<div class="wrap refm refm-admin"><a class="screen-reader-text" href="#refm-main">' . esc_html__( 'Skip to panel content', 'refmora' ) . '</a><header class="refm-admin-header"><div class="refm-brand"><span class="refm-brand-icon" aria-hidden="true"><img src="' . esc_url( REFM_URL . 'assets/img/icon.png' ) . '" alt="" width="44" height="44"></span><div><span class="refm-eyebrow">WP METHODS</span><h1>Refmora<span class="refm-version">' . esc_html( REFM_VERSION ) . '</span> <span class="refm-tier-badge">' . esc_html( class_exists( 'REFM_Pro_License', false ) ? __( 'Pro', 'refmora' ) : __( 'Free', 'refmora' ) ) . '</span></h1></div></div><div class="refm-header-actions"><span class="refm-program-state ' . ( $active ? 'is-active' : '' ) . '"><span aria-hidden="true"></span>' . esc_html( $active ? __( 'Program active', 'refmora' ) : __( 'Program paused', 'refmora' ) ) . '</span><a class="button refm-primary" href="' . esc_url( wc_get_account_endpoint_url( 'refmora' ) ) . '">' . esc_html__( 'Affiliate Area', 'refmora' ) . wp_kses( REFM_UI::icon( 'arrow' ), REFM_UI::allowed_html() ) . '</a></div></header><div class="refm-admin-layout' . ( 'settings' === $tab ? ' refm-settings-layout' : '' ) . '"><aside class="refm-sidebar"><p class="refm-eyebrow">' . esc_html__( 'PROGRAM MANAGEMENT', 'refmora' ) . '</p><nav class="refm-tabs" aria-label="' . esc_attr__( 'Affiliate administration', 'refmora' ) . '">';
		$pending_counts = self::pending_counts();
		foreach ( $tabs as $key => $label ) { echo '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=refmora&tab=' . $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . wp_kses( REFM_UI::icon( $key ), REFM_UI::allowed_html() ) . '<span>' . esc_html( $label ) . '</span>' . wp_kses_post( self::pending_badge( $pending_counts[ $key ] ?? 0 ) ) . '</a>'; }
		echo '</nav><div class="refm-sidebar-note">' . wp_kses( REFM_UI::icon( 'shield' ), REFM_UI::allowed_html() ) . '<strong>' . esc_html__( 'Built for your store', 'refmora' ) . '</strong><p>' . esc_html__( 'Simple referrals. Clear earnings. Payouts under your control.', 'refmora' ) . '</p></div>';
		require REFM_PATH . 'templates/admin-resources.php';
		echo '</aside><main class="refm-main" id="refm-main"><div class="refm-heading' . ( 'overview' === $tab ? ' refm-overview-title' : '' ) . '"><span class="refm-eyebrow">' . esc_html__( 'YOUR PARTNER PROGRAM', 'refmora' ) . '</span><h2>' . esc_html( $tabs[ $tab ] ) . '</h2><p>' . esc_html( $descriptions[ $tab ] ) . '</p>';
		if ( 'overview' === $tab ) { REFM_Reports::controls( $range ); }
		echo '</div>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		if ( '1' === sanitize_text_field( ( isset( $_GET[ 'saved' ] ) && is_scalar( $_GET[ 'saved' ] ) ? wp_unslash( $_GET[ 'saved' ] ) : '' ) ) ) { REFM_UI::toast( __( 'Settings saved.', 'refmora' ) ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		if ( isset( $_GET['refm_message'] ) && is_scalar( $_GET['refm_message'] ) ) { REFM_UI::toast( sanitize_text_field( wp_unslash( $_GET['refm_message'] ) ), '1' === sanitize_text_field( ( isset( $_GET[ 'refm_error' ] ) && is_scalar( $_GET[ 'refm_error' ] ) ? wp_unslash( $_GET[ 'refm_error' ] ) : '' ) ) ); }
		if ( 'settings' === $tab ) { self::settings(); }
		elseif ( 'overview' === $tab ) { self::overview( $range ); }
		elseif ( 'affiliates' === $tab ) { self::affiliates(); }
		elseif ( 'refer_links' === $tab ) { REFM_Refer_Links::render( null, 'admin' ); }
		elseif ( 'customers' === $tab ) { self::customers(); }
		elseif ( 'sharing' === $tab ) { REFM_Sharing::render(); }
		elseif ( 'emails' === $tab ) { REFM_Emails::render(); }
		elseif ( 'customization' === $tab ) { REFM_Design::render(); }
		elseif ( 'license' === $tab ) { REFM_License::render(); }
		elseif ( 'tools' === $tab ) { REFM_Tools::render(); }
		else { self::records( $tab ); }
		REFM_License::activation_notice( true );
		echo '<p class="refm-footer">Refmora Affiliate &middot; <a href="https://wpmethods.com" target="_blank" rel="noopener noreferrer">WP Methods</a></p></main></div></div>';
	}
	private static function overview( $range ) {
		$currency = REFM_Settings::get( 'currency' );
		$stats = REFM_Reports::summary( $range );
		echo '<div class="refm-overview-summary"><div class="refm-overview-summary-cards"><div class="refm-metrics">';
		echo wp_kses( REFM_UI::metric( __( 'Pending commissions', 'refmora' ), REFM_Store::money( $stats['pending'], $currency ), 'clock' ), REFM_UI::allowed_html() );
		echo wp_kses( REFM_UI::metric( __( 'Available earnings', 'refmora' ), REFM_Store::money( $stats['available'], $currency ), 'wallet' ), REFM_UI::allowed_html() );
		echo wp_kses( REFM_UI::metric( __( 'Awaiting payout', 'refmora' ), REFM_Store::money( $stats['requested'], $currency ), 'send' ), REFM_UI::allowed_html() );
		echo wp_kses( REFM_UI::metric( __( 'Completed payouts', 'refmora' ), REFM_Store::money( $stats['paid'], $currency ), 'award' ), REFM_UI::allowed_html() );
		echo wp_kses( REFM_UI::metric( __( 'Referral clicks', 'refmora' ), number_format_i18n( $stats['clicks'] ), 'click', '', admin_url( 'admin.php?page=refmora&tab=refer_links' ) . '#refm-refer-links' ), REFM_UI::allowed_html() );
		echo wp_kses( REFM_UI::metric( __( 'Registered customers', 'refmora' ), number_format_i18n( $stats['customers'] ), 'user', '', admin_url( 'admin.php?page=refmora&tab=customers' ) . '#refm-customers' ), REFM_UI::allowed_html() );
		echo '</div></div></div><p class="refm-report-note">' . esc_html__( 'Commission summaries use creation dates and current balances, including refund adjustments. Awaiting payouts uses request dates. Latest lists below show the newest records overall.', 'refmora' ) . '</p>';
		/** Separate add-ons can render an overview report using the same validated date range. */
		do_action( 'refm_admin_overview_chart', $range, $currency );
		echo '<div class="refm-overview-referrals">';
		REFM_Refer_Links::render( null, 'overview' );
		REFM_Reports::activity_donut( $range, $stats['clicks'], REFM_Reports::converted( $range ) );
		echo '</div>';
		self::overview_lists();
		echo '<div class="refm-grid"><section class="refm-card"><h2>' . esc_html__( 'Program at a glance', 'refmora' ) . '</h2><dl class="refm-definition">';
		$rate = 'percent' === REFM_Settings::get( 'commission_type' ) ? esc_html( REFM_Settings::get( 'commission_rate' ) ) . '%' : wp_kses_post( REFM_Store::money( REFM_Settings::get( 'commission_rate' ) ) ) . ' ' . esc_html__( 'per unit', 'refmora' );
		$values = array( __( 'Program', 'refmora' ) => REFM_Settings::program_enabled() ? esc_html__( 'Active', 'refmora' ) : esc_html__( 'Paused', 'refmora' ), __( 'Default commission', 'refmora' ) => $rate, __( 'Cookie duration', 'refmora' ) => (int) REFM_Settings::get( 'cookie_days' ) . ' ' . esc_html__( 'days', 'refmora' ), __( 'Minimum withdrawal', 'refmora' ) => wp_kses_post( REFM_Store::money( REFM_Settings::get( 'minimum' ) ) ), __( 'Active payout methods', 'refmora' ) => count( REFM_Settings::methods() ) );
		foreach ( $values as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value ) . '</dd></div>'; }
		echo '</dl><a class="button refm-primary" href="' . esc_url( admin_url( 'admin.php?page=refmora&tab=settings' ) ) . '">' . esc_html__( 'Manage settings', 'refmora' ) . '</a></section><section class="refm-card"><h2>' . esc_html__( 'How it works', 'refmora' ) . '</h2><ol class="refm-steps"><li>' . esc_html__( 'Customers join from My Account → Affiliate Area.', 'refmora' ) . '</li><li>' . esc_html__( 'A referral link sets a signed cookie and attributes new orders.', 'refmora' ) . '</li><li>' . esc_html__( 'Completed orders release commissions for withdrawal.', 'refmora' ) . '</li><li>' . esc_html__( 'Approve a request, then send it through PayPal or Stripe. For manual methods, send it yourself and mark it paid.', 'refmora' ) . '</li></ol><p class="refm-muted">' . esc_html__( 'Commission excludes tax and shipping. Refunds adjust balances automatically. Automatic payouts require a configured provider and an explicit administrator Send payment action.', 'refmora' ) . '</p></section></div>';
	}
	public static function field( $key, $label, $help, $type = 'text', $options = array(), $attributes = '' ) {
		$value = REFM_Settings::get( $key );
		echo '<div class="refm-field"><label for="refm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		if ( 'select' === $type ) {
			echo '<select name="refm[' . esc_attr( $key ) . ']" id="refm-' . esc_attr( $key ) . '">';
			foreach ( $options as $id => $name ) { echo '<option value="' . esc_attr( $id ) . '"' . selected( $value, $id, false ) . '>' . esc_html( $name ) . '</option>'; }
			echo '</select>';
		} else { echo wp_kses( '<input type="' . esc_attr( $type ) . '" name="refm[' . esc_attr( $key ) . ']" id="refm-' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" ' . $attributes . '>', REFM_UI::allowed_html() ); }
		echo '<p class="refm-muted">' . esc_html( $help ) . '</p></div>';
	}
	/** Render accessible status choices using the same allowlist as settings validation. */
	public static function commission_statuses_field() {
		$selected = REFM_Settings::clean_commission_statuses( REFM_Settings::get( 'commission_statuses' ) );
		echo '<fieldset class="refm-commission-statuses" aria-describedby="refm-commission-statuses-help"><legend>' . esc_html__( 'Commission tracking order statuses', 'refmora' ) . '</legend><input type="hidden" name="refm[commission_statuses][]" value="completed"><div class="refm-status-options">';
		foreach ( REFM_Settings::commission_order_statuses() as $status => $label ) {
			echo '<label class="refm-toggle"><input type="checkbox" name="refm[commission_statuses][]" value="' . esc_attr( $status ) . '"' . checked( in_array( $status, $selected, true ), true, false ) . disabled( 'completed' === $status, true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</div><p id="refm-commission-statuses-help" class="refm-muted">' . esc_html__( 'Choose which order statuses show pending commissions. Completed is always included and follows the availability rule below. Unselected statuses do not create commissions; existing commissions are rejected if an order moves to an unselected status. Cancelled, Failed and Refunded always reject commissions. Changes take effect on the next order status change or commission sync; saving does not recalculate past orders.', 'refmora' ) . '</p></fieldset>';
	}

	private static function overview_lists() {
		global $wpdb;
		$table = REFM_Store::table( 'payouts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE trashed_at IS NULL ORDER BY id DESC LIMIT 5', $table ) );
		echo '<div class="refm-grid"><section class="refm-card" id="refm-overview-withdrawals"><div class="refm-overview-heading"><h2>' . esc_html__( 'Latest withdrawal requests', 'refmora' ) . '</h2><a class="refm-overview-link" href="' . esc_url( admin_url( 'admin.php?page=refmora&tab=payouts' ) ) . '">' . esc_html__( 'See more withdrawals', 'refmora' ) . '</a></div><div class="refm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'refmora' ) . '</th><th>' . esc_html__( 'Amount', 'refmora' ) . '</th><th>' . esc_html__( 'Payment method', 'refmora' ) . '</th><th>' . esc_html__( 'Status', 'refmora' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . wp_kses_post( self::identity( $row->affiliate_id ) ) . '</td><td>' . wp_kses_post( REFM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses( REFM_UI::payment_method( $row->method ), REFM_UI::allowed_html() ) . '</td><td>' . wp_kses_post( REFM_Store::status( $row->status ) ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="4" class="refm-empty">' . esc_html__( 'No withdrawal requests yet.', 'refmora' ) . '</td></tr>'; }
		echo '</tbody></table></div></section>';
		// phpcs:ignore WordPress.DB.SlowDBQuery -- Required enrolled-affiliate predicate; only five users are fetched, no total count, with native WordPress query caching.
		$users = get_users( array( 'count_total' => false, 'cache_results' => true, 'meta_key' => '_refm_enrolled', 'meta_value' => 'yes', 'number' => 5, 'orderby' => 'ID', 'order' => 'DESC' ) );
		echo '<section class="refm-card" id="refm-overview-affiliates"><div class="refm-overview-heading"><h2>' . esc_html__( 'Latest affiliates', 'refmora' ) . '</h2><a class="refm-overview-link" href="' . esc_url( admin_url( 'admin.php?page=refmora&tab=affiliates' ) ) . '">' . esc_html__( 'See more affiliates', 'refmora' ) . '</a></div><div class="refm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'refmora' ) . '</th><th>' . esc_html__( 'Account', 'refmora' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) { echo '<tr><td>' . wp_kses_post( self::identity( $user->ID ) ) . '</td><td>' . ( 'yes' === get_user_meta( $user->ID, '_refm_blocked', true ) ? wp_kses_post( REFM_Store::status( 'blocked' ) ) : wp_kses_post( REFM_Store::status( REFM_Affiliates::status( $user->ID ) ) ) ) . '</td></tr>'; }
		if ( ! $users ) { echo '<tr><td colspan="2" class="refm-empty">' . esc_html__( 'No affiliates yet.', 'refmora' ) . '</td></tr>'; }
		echo '</tbody></table></div></section></div>';
	}
	private static function settings() {
		echo '<form id="refm-program-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="refm-settings"><input type="hidden" name="action" value="refm_save_settings">';
		wp_nonce_field( 'refm_settings' );
		echo '<nav class="refm-section-nav" aria-label="' . esc_attr__( 'Settings sections', 'refmora' ) . '"><a href="#refm-program-settings">' . esc_html__( 'Enrollment', 'refmora' ) . '</a><a href="#refm-commissions-settings">' . esc_html__( 'Commissions', 'refmora' ) . '</a><a href="#refm-tracking-settings">' . esc_html__( 'Tracking', 'refmora' ) . '</a><a href="#refm-payouts-settings">' . esc_html__( 'Payouts', 'refmora' ) . '</a><a href="#refm-terms-settings">' . esc_html__( 'Terms & Conditions', 'refmora' ) . '</a></nav>';
		echo '<section class="refm-card" id="refm-program-settings"><h2>' . wp_kses( REFM_UI::icon( 'affiliates' ), REFM_UI::allowed_html() ) . esc_html__( 'Program & enrollment', 'refmora' ) . '</h2><label class="refm-toggle"><input type="checkbox" name="refm[enabled]" value="yes"' . checked( REFM_Settings::get( 'enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable affiliate program', 'refmora' ) . '</label><p class="refm-muted">' . esc_html__( 'Pausing stops new enrollments, referrals and withdrawals. Existing order commissions and history continue to update.', 'refmora' ) . '</p><div class="refm-form-grid">';
		self::field( 'approval_required', __( 'Affiliate account approval', 'refmora' ), __( 'New accounts join automatically by default. Enable review to approve or reject new applications from the Affiliates tab. Existing affiliates remain approved; pending applications still need review.', 'refmora' ), 'select', array( 'no' => __( 'Join automatically', 'refmora' ), 'yes' => __( 'Require administrator approval', 'refmora' ) ) );
		echo '</div><h3>' . esc_html__( 'Join Affiliate page', 'refmora' ) . '</h3><p class="refm-muted">' . esc_html__( 'Customize the invitation customers see in the Affiliate Area before joining.', 'refmora' ) . '</p><div class="refm-form-grid">';
		self::field( 'join_title', __( 'Join Affiliate title', 'refmora' ), __( 'Heading on the page before joining.', 'refmora' ), 'text', array(), 'maxlength="180" required' );
		self::field( 'join_button_text', __( 'Join button text', 'refmora' ), __( 'Label for the button that submits the affiliate application.', 'refmora' ), 'text', array(), 'maxlength="180" required' );
		echo '<div class="refm-field"><label for="refm-join-description">' . esc_html__( 'Description text', 'refmora' ) . '</label><textarea id="refm-join-description" name="refm[join_description]" rows="4" maxlength="3000" style="width:100%">' . esc_textarea( REFM_Settings::get( 'join_description' ) ) . '</textarea><p>' . esc_html__( 'Plain text with line breaks. Leave empty to hide the description.', 'refmora' ) . '</p></div>';
		self::field( 'join_show_rate', __( 'Default commission rate before joining', 'refmora' ), __( 'Choose whether customers see your default percentage or fixed amount on the Join Affiliate page. Product-specific rates may differ.', 'refmora' ), 'select', array( 'no' => __( 'Hide', 'refmora' ), 'yes' => __( 'Show', 'refmora' ) ) );
		echo '<div><label class="refm-toggle"><input type="checkbox" name="refm[join_url_enabled]" value="yes"' . checked( REFM_Settings::get( 'join_url_enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable Website / Promote URL field', 'refmora' ) . '</label><p class="refm-muted">' . esc_html__( 'Disabled by default. When enabled, applicants must provide a website or social profile URL.', 'refmora' ) . '</p></div><div><label class="refm-toggle"><input type="checkbox" name="refm[join_promotion_enabled]" value="yes"' . checked( REFM_Settings::get( 'join_promotion_enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable How will you promote us? field', 'refmora' ) . '</label><p class="refm-muted">' . esc_html__( 'Disabled by default. When enabled, applicants must describe their promotion plan. View answers in the Affiliates list.', 'refmora' ) . '</p></div>';
		echo '</div></section><section class="refm-card" id="refm-commissions-settings"><h2>' . wp_kses( REFM_UI::icon( 'commissions' ), REFM_UI::allowed_html() ) . esc_html__( 'Commission rules', 'refmora' ) . '</h2><p class="refm-muted">' . esc_html__( 'Set your default earnings. Commissions use the WooCommerce base currency; individual products can override these rates.', 'refmora' ) . '</p><div class="refm-form-grid">';
		self::field( 'commission_type', __( 'Commission type', 'refmora' ), __( 'Product settings override this default.', 'refmora' ), 'select', array( 'percent' => __( 'Percentage', 'refmora' ), 'fixed' => __( 'Fixed amount per unit', 'refmora' ) ) );
		self::field( 'commission_rate', __( 'Default commission', 'refmora' ), __( 'Percentage: 0–100. Fixed: amount per purchased unit, capped at the item total.', 'refmora' ), 'number', array(), 'min="0" max="1000000" step="0.000001" required' );
		self::commission_statuses_field();
		self::field( 'release_mode', __( 'Commission availability', 'refmora' ), __( 'By default, commissions become available when the order is Completed. Choose a waiting period, or Confirm from Commission List to keep pending commissions on hold until a manager confirms or rejects them. Manual confirmation can release a commission in any selected tracking status. Previously released commissions stay available; refunds and cancellations still adjust earnings.', 'refmora' ), 'select', array( 'completed' => __( 'Immediately after order completion (default)', 'refmora' ), 'delay' => __( 'After a waiting period', 'refmora' ), 'manual' => __( 'Confirm from Commission List', 'refmora' ) ) );
		echo '<div id="refm-release-days"' . ( 'delay' === REFM_Settings::get( 'release_mode' ) ? '' : ' hidden' ) . '>';
		self::field( 'release_days', __( 'Waiting period (days)', 'refmora' ), __( '1 to 365 days, counted from the order completion time. A saved waiting period is not changed by later settings updates.', 'refmora' ), 'number', array(), 'min="1" max="365" step="1" required' );
		echo '</div>';
		$base_currency = REFM_Settings::base_currency(); $currency_names = get_woocommerce_currencies();
		echo '<div class="refm-field refm-base-currency"><span class="refm-muted">' . esc_html__( 'WooCommerce base currency', 'refmora' ) . '</span><p><strong>' . esc_html( $base_currency . ' - ' . $currency_names[ $base_currency ] ) . '</strong></p><p class="refm-muted">' . esc_html__( 'New commissions, fixed per-unit rates, minimum withdrawal and dashboard totals use the base currency from WooCommerce Settings. The Pro add-on can convert FOX/CURCY orders in another currency using a saved exchange rate. Existing transactions retain their original currency.', 'refmora' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'WooCommerce Settings', 'refmora' ) . '</a></p></div>';
		if ( has_action( 'refm_exchange_settings' ) ) { do_action( 'refm_exchange_settings' ); }
		else { echo '<div class="refm-field refm-premium-card"><label>' . esc_html__( 'Exchange rate source', 'refmora' ) . ' ' . wp_kses_post( REFM_License::premium_badge() ) . '</label><select disabled aria-label="' . esc_attr__( 'Free currency mode', 'refmora' ) . '"><option>' . esc_html__( 'WooCommerce base currency only', 'refmora' ) . '</option></select><p class="refm-muted">' . esc_html__( 'Currency conversion is provided by the separate Pro add-on. Free earns commissions in the WooCommerce base currency.', 'refmora' ) . '</p>' . wp_kses_post( REFM_License::premium_link() ) . '</div>'; }
		echo '</div></section><section class="refm-card" id="refm-tracking-settings"><h2>' . wp_kses( REFM_UI::icon( 'clock' ), REFM_UI::allowed_html() ) . esc_html__( 'Referral tracking & repeat purchases', 'refmora' ) . '</h2><p class="refm-muted">' . esc_html__( 'Choose how long referrals last and whether returning customers can earn additional commissions.', 'refmora' ) . '</p><div class="refm-form-grid">';
		self::field( 'referral_parameter', __( 'Referral link parameter', 'refmora' ), __( 'Default: refm_ref. Use a name such as ref or partner. Letters, numbers, underscores and hyphens only, starting with a letter. Existing refm_ref links continue to work. Exclude both names from page caching.', 'refmora' ), 'text', array(), 'maxlength="60" pattern="[a-zA-Z][a-zA-Z0-9_-]{0,59}" required' );
		self::field( 'account_slug', __( 'Affiliate Area URL slug', 'refmora' ), __( 'Default: refmora. Example: partners creates /my-account/partners/. Use letters, numbers and hyphens, starting with a letter. Avoid existing WordPress and account endpoints. The default URL continues to work.', 'refmora' ), 'text', array(), 'maxlength="60" pattern="[a-zA-Z][a-zA-Z0-9-]{0,59}" required' );
		self::field( 'cookie_days', __( 'Cookie lifetime (days)', 'refmora' ), __( 'Between 1 and 365 days.', 'refmora' ), 'number', array(), 'min="1" max="365" step="1" required' );
		self::field( 'attribution', __( 'Referral attribution', 'refmora' ), __( 'First referral keeps the first valid cookie. Last referral replaces it.', 'refmora' ), 'select', array( 'first' => __( 'First referral', 'refmora' ), 'last' => __( 'Last referral', 'refmora' ) ) );
		self::field( 'purchase_policy', __( 'Repeat purchase commissions', 'refmora' ), __( 'Once: only one eligible commission-earning purchase per customer across all affiliates and products. Multiple: each eligible referred order earns commission. Customers are matched by account or billing email. Applies to new orders only.', 'refmora' ), 'select', array( 'multiple' => __( 'Every eligible referred purchase', 'refmora' ), 'once' => __( 'Once per customer', 'refmora' ) ) );
		echo '</div></section><section class="refm-card" id="refm-payouts-settings"><h2>' . wp_kses( REFM_UI::icon( 'payouts' ), REFM_UI::allowed_html() ) . esc_html__( 'Payout settings', 'refmora' ) . '</h2><div class="refm-form-grid">';
		self::field( 'minimum', __( 'Minimum withdrawal', 'refmora' ), __( 'Customers request their entire available balance, after refund adjustments.', 'refmora' ), 'number', array(), 'min="0.01" max="1000000" step="0.000001" required' );
		self::field( 'payout_mode', __( 'Enable payout', 'refmora' ), __( 'Manual methods are paid outside the plugin. Automatic providers send only after approval and Send payment. Pause new withdrawals whenever needed.', 'refmora' ), 'select', array( 'manual' => __( 'Enabled', 'refmora' ), 'disabled' => __( 'Withdrawals paused', 'refmora' ) ) );
		self::field( 'method_selection', __( 'Available methods', 'refmora' ), __( 'Single keeps only the first checked method active. Multiple lets customers choose among all checked methods.', 'refmora' ), 'select', array( 'single' => __( 'One active method', 'refmora' ), 'multiple' => __( 'Multiple active methods', 'refmora' ) ) );
		echo '</div>';
		echo '<div id="refm-manual-methods"><h3>' . esc_html__( 'Payout methods', 'refmora' ) . '</h3><p class="refm-muted">' . esc_html__( 'Add manual methods. Customers choose from active methods. Instructions explain the payment details needed.', 'refmora' ) . '</p><div id="refm-method-list">';
		foreach ( REFM_Settings::get( 'methods' ) as $index => $method ) { self::method_row( $index, $method ); }
		echo '</div><button type="button" class="button refm-primary" id="refm-add-method">' . esc_html__( '+ Add payout method', 'refmora' ) . '</button><template id="refm-method-template">';
		self::method_row( '__INDEX__', array( 'id' => '', 'name' => '', 'instructions' => '', 'active' => 'yes' ) );
		echo '</template></div>';
		 REFM_Gateways::fields(); echo '</section><section class="refm-card" id="refm-products-settings"><h2>' . wp_kses( REFM_UI::icon( 'link' ), REFM_UI::allowed_html() ) . esc_html__( 'Product commissions & tracking', 'refmora' ) . '</h2><p>' . esc_html__( 'Edit a product → Product data → General to set a percentage, fixed amount per unit, or disable commission for that product. Parent product settings cover all variations.', 'refmora' ) . '</p><p class="refm-muted">' . esc_html( str_replace( 'refm_ref', implode( ', ', REFM_Settings::referral_keys() ), __( 'Caching: exclude refm_ref query strings from full-page cache, and keep My Account, Cart and Checkout uncached. With LiteSpeed, add refm_ref under Cache → Excludes → Do Not Cache Query Strings; do not add it to Drop Query String. Hosting/CDN caches must also bypass referral URLs and logged-in users. Purge existing page caches after applying these rules. Guest product pages remain cacheable. Review the Refmora privacy policy text in WordPress Settings → Privacy and connect cookie consent through the refm_tracking_allowed filter when required.', 'refmora' ) ) ) . '</p></section>';
		echo '<section class="refm-card" id="refm-terms-settings"><h2>' . wp_kses( REFM_UI::icon( 'affiliates' ), REFM_UI::allowed_html() ) . esc_html__( 'Affiliate Terms & Conditions', 'refmora' ) . '</h2><p class="refm-muted">' . esc_html__( 'Customers see this content in the Affiliate Area before joining and must accept it. Leave it empty to join without a terms checkbox. Updates apply to new applications; existing affiliates keep their enrollment.', 'refmora' ) . '</p><label for="refm_terms_content">' . esc_html__( 'Terms & Conditions content', 'refmora' ) . '</label>';
		wp_editor( REFM_Settings::terms(), 'refm_terms_content', array( 'textarea_name' => 'refm[terms_content]', 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true, 'quicktags' => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ) ) );
		echo '</section>';
		echo '</form>';
		REFM_Currency::recovery_form();
		REFM_UI::footer( __( 'Save settings', 'refmora' ), 'refm-program-settings-form' );
	}
	public static function method_row( $index, $method, $removable = true ) {
		$prefix = 'refm[methods][' . $index . ']';
		echo '<div class="refm-method-row">';
		REFM_UI::payment_icon_field( $prefix . '[icon]', $method['icon'] ?? '', __( 'Payment icon', 'refmora' ) );
		echo '<div class="refm-method-content"><input type="hidden" name="' . esc_attr( $prefix . '[id]' ) . '" value="' . esc_attr( $method['id'] ) . '"><div class="refm-method-top"><label>' . esc_html__( 'Method name', 'refmora' ) . '<input type="text" name="' . esc_attr( $prefix . '[name]' ) . '" value="' . esc_attr( $method['name'] ) . '" maxlength="120" placeholder="' . esc_attr__( 'e.g. Bank transfer', 'refmora' ) . '"></label><label class="refm-toggle"><input type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="yes"' . checked( $method['active'], 'yes', false ) . '> ' . esc_html__( 'Active', 'refmora' ) . '</label>' . ( $removable ? '<button type="button" class="button refm-remove-method refm-primary">' . esc_html__( 'Remove', 'refmora' ) . '</button>' : '' ) . '</div><label>' . esc_html__( 'Instructions for customers', 'refmora' ) . '<textarea name="' . esc_attr( $prefix . '[instructions]' ) . '" rows="2" maxlength="2000">' . esc_textarea( $method['instructions'] ) . '</textarea></label></div></div>';
	}
	private static function identity( $id ) {
		$user = get_userdata( $id );
		return $user ? '<strong>' . esc_html( $user->display_name ) . '</strong><small class="refm-note">' . esc_html( $user->user_email ) . '</small>' : '#' . absint( $id );
	}
	private static function search_term() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		return mb_substr( sanitize_text_field( ( isset( $_GET[ 'q' ] ) && is_scalar( $_GET[ 'q' ] ) ? wp_unslash( $_GET[ 'q' ] ) : '' ) ), 0, 160 );
	}
	private static function list_statuses( $tab ) {
		return 'affiliates' === $tab ? array( 'pending', 'approved', 'rejected', 'blocked' ) : ( 'payouts' === $tab ? array( 'pending', 'approved', 'processing', 'completed', 'tested', 'failed', 'rejected' ) : array( 'pending', 'available', 'reserved', 'paid', 'rejected' ) );
	}
	private static function list_status( $tab ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$status = sanitize_key( ( isset( $_GET[ 'status' ] ) && is_scalar( $_GET[ 'status' ] ) ? wp_unslash( $_GET[ 'status' ] ) : '' ) );
		return in_array( $status, self::list_statuses( $tab ), true ) ? $status : '';
	}
	private static function filters( $tab ) {
		$search = self::search_term(); $status = self::list_status( $tab );
		$placeholder = 'affiliates' === $tab ? __( 'Name, email or user ID', 'refmora' ) : ( 'commissions' === $tab ? __( 'Affiliate, email, order or commission ID', 'refmora' ) : __( 'Affiliate, email or withdrawal ID', 'refmora' ) );
		echo '<form class="refm-list-filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="refmora"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><label class="screen-reader-text" for="refm-list-search">' . esc_html__( 'Search records', 'refmora' ) . '</label><input type="search" id="refm-list-search" name="q" value="' . esc_attr( $search ) . '" maxlength="160" placeholder="' . esc_attr( $placeholder ) . '"><label class="screen-reader-text" for="refm-status-filter">' . esc_html__( 'Filter status', 'refmora' ) . '</label><select id="refm-status-filter" name="status"><option value="">' . esc_html__( 'All statuses', 'refmora' ) . '</option>';
		foreach ( self::list_statuses( $tab ) as $option ) { echo '<option value="' . esc_attr( $option ) . '"' . selected( $status, $option, false ) . '>' . esc_html( 'blocked' === $option ? __( 'Blocked', 'refmora' ) : wp_strip_all_tags( REFM_Store::status( $option ) ) ) . '</option>'; }
		echo '</select><button type="submit" class="button refm-primary">' . esc_html__( 'Search', 'refmora' ) . '</button>';
		if ( '' !== $search || '' !== $status ) { echo '<a class="refm-filter-reset" href="' . esc_url( add_query_arg( array( 'page' => 'refmora', 'tab' => $tab ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Reset', 'refmora' ) . '</a>'; }
		if ( in_array( $tab, array( 'commissions', 'payouts' ), true ) && self::trash_view() ) {
			echo '<input type="hidden" name="view" value="trash">';
		}
		echo '</form>';
	}
	/** Show only referral-attributed registrations to authorized store managers. */
	private static function customers() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		global $wpdb;
		$search = self::search_term();
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page navigation; manager capability is checked before querying.
		$page = REFM_Security::page( $_GET, 'paged' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh pagination over the custom registration-attribution table, with prepared search terms.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i AS c LEFT JOIN %i AS u ON u.ID=c.customer_id WHERE (%s=%s OR u.display_name LIKE %s OR u.user_email LIKE %s OR CAST(c.customer_id AS CHAR)=%s OR CAST(c.affiliate_id AS CHAR)=%s)', REFM_Store::table( 'customers' ), $wpdb->users, $search, '', $like, $like, $search, $search ) );
		$page = min( $page, max( 1, (int) ceil( $count / self::PAGE_SIZE ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fetch a bounded page of referral customers; affiliate names are primed together below.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.customer_id,c.affiliate_id,c.created_at FROM %i AS c LEFT JOIN %i AS u ON u.ID=c.customer_id WHERE (%s=%s OR u.display_name LIKE %s OR u.user_email LIKE %s OR CAST(c.customer_id AS CHAR)=%s OR CAST(c.affiliate_id AS CHAR)=%s) ORDER BY c.created_at DESC,c.customer_id DESC LIMIT %d OFFSET %d', REFM_Store::table( 'customers' ), $wpdb->users, $search, '', $like, $like, $search, $search, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ) );
		if ( $rows ) {
			cache_users( array_unique( array_map( 'absint', array_merge( array_column( $rows, 'customer_id' ), array_column( $rows, 'affiliate_id' ) ) ) ) );
		}
		echo '<section class="refm-card" id="refm-customers"><div class="refm-card-toolbar"><h2>' . esc_html__( 'Registered customers', 'refmora' ) . '</h2><form class="refm-list-filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="refmora"><input type="hidden" name="tab" value="customers"><label class="screen-reader-text" for="refm-customer-search">' . esc_html__( 'Search customer or affiliate ID', 'refmora' ) . '</label><input type="search" id="refm-customer-search" name="q" maxlength="160" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search customer or affiliate ID', 'refmora' ) . '"><button class="button refm-primary" type="submit">' . esc_html__( 'Search', 'refmora' ) . '</button></form></div><div class="refm-table-scroll"><table><thead><tr><th scope="col">' . esc_html__( 'SL', 'refmora' ) . '</th><th scope="col">' . esc_html__( 'Customer', 'refmora' ) . '</th><th scope="col">' . esc_html__( 'Email', 'refmora' ) . '</th><th scope="col">' . esc_html__( 'Affiliate', 'refmora' ) . '</th><th scope="col">' . esc_html__( 'Registered', 'refmora' ) . '</th></tr></thead><tbody>';
		$serial = ( $page - 1 ) * self::PAGE_SIZE;
		foreach ( $rows as $row ) {
			$customer = get_userdata( $row->customer_id );
			$affiliate = get_userdata( $row->affiliate_id );
			echo '<tr><td>' . esc_html( number_format_i18n( ++$serial ) ) . '</td><td>' . esc_html( $customer ? $customer->display_name : __( 'Deleted customer', 'refmora' ) ) . '</td><td>' . esc_html( $customer ? $customer->user_email : '' ) . '</td><td>' . esc_html( $affiliate ? $affiliate->display_name : __( 'Deleted affiliate', 'refmora' ) ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ) . '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="5" class="refm-empty">' . esc_html__( 'No registered customers found.', 'refmora' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		self::pagination( $count, $page, 'customers' );
		echo '</section>';
	}

	private static function affiliates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = REFM_Security::page_number( absint( ( isset( $_GET[ 'paged' ] ) && is_scalar( $_GET[ 'paged' ] ) ? wp_unslash( $_GET[ 'paged' ] ) : '1' ) ) );
		// phpcs:ignore WordPress.DB.SlowDBQuery -- Required enrollment predicate; results are paginated at ten users and WordPress caches query IDs/totals.
		$args = array( 'cache_results' => true, 'meta_key' => '_refm_enrolled', 'meta_value' => 'yes', 'number' => self::PAGE_SIZE, 'offset' => ( $page - 1 ) * self::PAGE_SIZE, 'orderby' => 'ID', 'order' => 'DESC' );
		$search = self::search_term(); $status = self::list_status( 'affiliates' );
		if ( '' !== $search ) { $args['search'] = '*' . ltrim( $search, '#' ) . '*'; $args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'ID' ); }
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required account-status predicate for this paginated, cached user query.
		if ( 'blocked' === $status ) { $args['meta_query'] = array( array( 'key' => '_refm_blocked', 'value' => 'yes' ) ); }
		elseif ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Preserve legacy approval and blocked-account semantics; native user-query caches are invalidated by metadata updates.
			$args['meta_query'] = array( 'relation' => 'AND', array( 'relation' => 'OR', array( 'key' => '_refm_blocked', 'compare' => 'NOT EXISTS' ), array( 'key' => '_refm_blocked', 'value' => 'yes', 'compare' => '!=' ) ) );
			$args['meta_query'][] = 'approved' === $status ? array( 'relation' => 'OR', array( 'key' => '_refm_approval', 'compare' => 'NOT EXISTS' ), array( 'key' => '_refm_approval', 'value' => array( 'pending', 'rejected' ), 'compare' => 'NOT IN' ) ) : array( 'key' => '_refm_approval', 'value' => $status );
		}
		$query = new WP_User_Query( $args );
		$count = (int) $query->get_total();
		$last = max( 1, (int) ceil( $count / self::PAGE_SIZE ) );
		if ( $page > $last ) {
			$page = $last; $args['offset'] = ( $page - 1 ) * self::PAGE_SIZE; $args['count_total'] = false;
			$query = new WP_User_Query( $args );
		}
		$users = $query->get_results();
		$currency = REFM_Settings::get( 'currency' );
		$earnings = array();
		if ( $users ) {
			global $wpdb;
			$ids = array_map( 'absint', wp_list_pluck( $users, 'ID' ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$args = array_merge( array( REFM_Store::table( 'commissions' ), $currency ), $ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- One page-scoped aggregate; only generated numeric placeholders are interpolated and every ID/currency is prepared.
			$earnings = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id,COALESCE(SUM(amount),0) AS earned FROM %i WHERE currency=%s AND affiliate_id IN ($placeholders) AND status IN ('available','reserved','paid') GROUP BY affiliate_id", $args ), OBJECT_K );
		}
		$serial = ( $page - 1 ) * self::PAGE_SIZE;
		echo '<section class="refm-card"><div class="refm-card-toolbar"><h2>' . esc_html__( 'Affiliates', 'refmora' ) . '</h2>'; self::filters( 'affiliates' ); echo '</div><div class="refm-table-scroll"><table><thead><tr><th scope="col">' . esc_html__( 'SL', 'refmora' ) . '</th><th>' . esc_html__( 'Affiliate', 'refmora' ) . '</th><th>' . esc_html__( 'Available balance', 'refmora' ) . '</th><th scope="col" title="' . esc_attr__( 'Released commissions after refund adjustments; excludes pending and rejected commissions.', 'refmora' ) . '">' . esc_html__( 'Total earned', 'refmora' ) . '</th><th>' . esc_html__( 'Clicks', 'refmora' ) . '</th><th>' . esc_html__( 'Customers', 'refmora' ) . '</th><th>' . esc_html__( 'Account', 'refmora' ) . '</th><th>' . esc_html__( 'Action', 'refmora' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) {
			$blocked = 'yes' === get_user_meta( $user->ID, '_refm_blocked', true );
			$stats = REFM_Store::referral_stats( $user->ID );
			$approval = REFM_Affiliates::status( $user->ID );
			echo '<tr><td>' . esc_html( number_format_i18n( ++$serial ) ) . '</td><td>' . wp_kses_post( self::identity( $user->ID ) ) . '</td><td>' . wp_kses_post( REFM_Store::money( REFM_Store::balance( $user->ID, REFM_Settings::get( 'currency' ) ) ) ) . '</td><td>' . wp_kses_post( REFM_Store::money( $earnings[ $user->ID ]->earned ?? 0, $currency ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['clicks'] ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['customers'] ) ) . '</td><td>' . ( $blocked ? wp_kses_post( REFM_Store::status( 'blocked' ) ) : wp_kses_post( REFM_Store::status( $approval ) ) ) . '</td><td>';
			self::application_details( $user->ID );
			$actions = 'pending' === $approval ? array( 'approved' => __( 'Approve', 'refmora' ), 'rejected' => __( 'Reject', 'refmora' ) ) : ( 'rejected' === $approval ? array( 'approved' => __( 'Approve', 'refmora' ) ) : array( $blocked ? 'unblock' : 'block' => $blocked ? __( 'Unblock', 'refmora' ) : __( 'Block', 'refmora' ) ) );
			echo '<form class="refm-actions refm-affiliate-actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="refm_affiliate"><input type="hidden" name="affiliate_id" value="' . absint( $user->ID ) . '">'; wp_nonce_field( 'refm_affiliate_' . $user->ID );
			foreach ( $actions as $operation => $label ) {
				$icon = 'approved' === $operation ? 'check-circle' : ( 'rejected' === $operation ? 'close' : ( 'block' === $operation ? 'lock' : 'shield' ) );

				echo '<button type="submit" class="button refm-primary' . ( 'rejected' === $operation ? ' refm-danger' : '' ) . '" name="operation" value="' . esc_attr( $operation ) . '" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . wp_kses( REFM_UI::icon( $icon ), REFM_UI::allowed_html() ) . ( 'rejected' === $operation ? '' : esc_html( $label ) ) . '</button>';
			}
			echo '</form>';
			self::record_actions( 'affiliates', $user->ID );
			echo '</td></tr>';
		}
		if ( ! $query->get_results() ) { echo '<tr><td colspan="8" class="refm-empty">' . esc_html( '' !== $search || '' !== $status ? __( 'No affiliates match your search or filters.', 'refmora' ) : __( 'Customers who join the program will appear here.', 'refmora' ) ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $count, esc_html( $page ), 'affiliates', $status ); echo '</section>';
	}
	private static function application_details( $id ) {
		$application = get_user_meta( $id, '_refm_application', true );
		if ( ! is_array( $application ) || ( empty( $application['website'] ) && empty( $application['promotion'] ) ) ) { return; }
		echo '<details class="refm-payment"><summary>' . esc_html__( 'Application details', 'refmora' ) . '</summary>';
		$url = esc_url( REFM_Security::value( $application, 'website' ), array( 'http', 'https' ) );
		if ( $url ) { echo '<p><strong>' . esc_html__( 'Website / Promote URL', 'refmora' ) . '</strong><br><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( REFM_Security::value( $application, 'website' ) ) . '</a></p>'; }
		if ( ! empty( $application['promotion'] ) ) { echo '<p><strong>' . esc_html__( 'Promotion plan', 'refmora' ) . '</strong><br>' . nl2br( esc_html( REFM_Security::value( $application, 'promotion' ) ) ) . '</p>'; }
		echo '</details>';
	}
	/**
	 * Render actions only for unsettled commissions awaiting a manager decision.
	 *
	 * @param object        $row   Commission record.
	 * @param WC_Order|null $order Current WooCommerce order.
	 */
	public static function commission_actions( $row, $order ) {
		if ( REFM_Commissions::can_review( $row, $order ) ) {
			echo '<form class="refm-commission-actions refm-actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="refm_commission"><input type="hidden" name="commission_id" value="' . absint( $row->id ) . '"><input type="hidden" name="review_version" value="' . esc_attr( REFM_Commissions::review_version( $row ) ) . '">';
			wp_nonce_field( 'refm_commission_' . $row->id );
			echo '<button type="submit" class="button refm-primary" name="decision" value="confirmed">' . esc_html__( 'Confirm', 'refmora' ) . '</button><button type="submit" class="button refm-primary refm-danger" name="decision" value="rejected">' . esc_html__( 'Reject', 'refmora' ) . '</button></form>';
			return;
		}
		$decision = $row->review_status ?? '';
		if ( in_array( $decision, array( 'confirmed', 'rejected' ), true ) ) {
			echo '<span class="refm-muted">' . esc_html( 'confirmed' === $decision ? __( 'Confirmed', 'refmora' ) : __( 'Rejected', 'refmora' ) ) . '</span>';
		}
	}

	private static function records( $type ) {
		global $wpdb;
		$manual_review = 'commissions' === $type && 'manual' === REFM_Settings::get( 'release_mode' );
		$record_actions = REFM_Records::allowed();
		$action_column = $manual_review || $record_actions;
		$trash = (int) self::trash_view();
		$table = REFM_Store::table( $type );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = REFM_Security::page_number( absint( ( isset( $_GET[ 'paged' ] ) && is_scalar( $_GET[ 'paged' ] ) ? wp_unslash( $_GET[ 'paged' ] ) : '1' ) ) );
		$status = self::list_status( $type ); $search = self::search_term();
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$number = ltrim( $search, '#' );
		$numeric = ctype_digit( $number ) && strlen( $number ) <= 18;
		$number = $numeric ? (int) $number : 0;
		$order_column = 'commissions' === $type ? 'order_id' : 'id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i r LEFT JOIN %i u ON u.ID=r.affiliate_id WHERE (%s=\'\' OR r.status=%s) AND ((%d=1 AND r.trashed_at IS NOT NULL) OR (%d=0 AND r.trashed_at IS NULL)) AND (%s=\'\' OR u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR (%d=1 AND (r.id=%d OR r.affiliate_id=%d OR r.%i=%d)))',
			$table, $wpdb->users, $status, $status, $trash, $trash, $search, $like, $like, $like, (int) $numeric, $number, $number, $order_column, $number
		) );
		$page = min( $page, max( 1, (int) ceil( $count / self::PAGE_SIZE ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT r.* FROM %i r LEFT JOIN %i u ON u.ID=r.affiliate_id WHERE (%s=\'\' OR r.status=%s) AND ((%d=1 AND r.trashed_at IS NOT NULL) OR (%d=0 AND r.trashed_at IS NULL)) AND (%s=\'\' OR u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR (%d=1 AND (r.id=%d OR r.affiliate_id=%d OR r.%i=%d))) ORDER BY r.id DESC LIMIT %d OFFSET %d',
			$table, $wpdb->users, $status, $status, $trash, $trash, $search, $like, $like, $like, (int) $numeric, $number, $number, $order_column, $number, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE
		) );
		echo '<section class="refm-card"><div class="refm-card-toolbar"><h2>' . esc_html( 'payouts' === $type ? __( 'Withdrawal requests', 'refmora' ) : __( 'Commission ledger', 'refmora' ) ) . '</h2>'; self::filters( $type ); echo '</div>';
		if ( $record_actions ) {
			self::record_views( $type, $trash );
			if ( $trash ) {
				echo '<p class="refm-muted">' . esc_html__( 'Restore returns history to Records using the current rules. Delete permanently removes the record and cannot be undone. Cancelled withdrawals stay cancelled when restored.', 'refmora' ) . '</p>';
			}
		}
		if ( 'payouts' === $type ) { echo '<p class="refm-muted">' . esc_html__( 'Approve each request first. For manual methods, send money outside the plugin and choose Mark paid. For PayPal or Stripe, choose Send payment; completion requires provider confirmation. Sandbox/Test does not settle real earnings. Notes are visible to the affiliate.', 'refmora' ) . '</p>'; }
		echo '<div class="refm-table-scroll"><table' . ( 'payouts' === $type ? ' class="refm-withdrawal-table"' : '' ) . '><thead><tr><th>#</th><th>' . esc_html( 'payouts' === $type ? __( 'Affiliate', 'refmora' ) : __( 'Affiliate', 'refmora' ) ) . '</th><th>' . esc_html__( 'Amount', 'refmora' ) . '</th><th>' . esc_html__( 'Status', 'refmora' ) . '</th><th>' . esc_html__( 'Date', 'refmora' ) . '</th><th>' . esc_html( 'payouts' === $type ? __( 'Payment & action', 'refmora' ) : __( 'Order / paid', 'refmora' ) ) . '</th>';
		if ( $action_column ) {
			echo '<th scope="col">' . esc_html__( 'Action', 'refmora' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . absint( $row->id ) . '</td><td' . ( 'payouts' === $type ? ' class="refm-withdrawal-customer"' : '' ) . '>' . wp_kses_post( self::identity( $row->affiliate_id ) );
			echo '</td><td>' . wp_kses_post( REFM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( REFM_Store::status( $row->status ) ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ) . '</td><td' . ( 'payouts' === $type ? ' class="refm-withdrawal-review"' : '' ) . '>';
			if ( 'commissions' === $type ) {
				$order = wc_get_order( $row->order_id );
				if ( 'repeat' === $row->eligibility ) { echo '<small class="refm-note">' . esc_html__( 'Repeat purchase excluded', 'refmora' ) . '</small>'; }
				if ( $order ) { echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . absint( $row->order_id ) . '</a>'; }
				else { echo '#' . absint( $row->order_id ); }
				echo '<small class="refm-note">' . esc_html__( 'Settled:', 'refmora' ) . ' ' . wp_kses_post( REFM_Store::money( $row->paid_amount, $row->currency ) ) . '</small>';
			} else {
				echo wp_kses( REFM_UI::payment_method( $row->method ), REFM_UI::allowed_html() );
				echo '<details class="refm-payment-details"><summary>' . esc_html__( 'Payment details', 'refmora' ) . '</summary><div class="refm-payout-details">' . nl2br( esc_html( $row->details ) );
				$gateway_job = REFM_Gateways::job( $row->id ); REFM_Gateways::summary( $row->id );
				echo '</div>';
				if ( in_array( $row->status, array( 'pending', 'approved', 'processing' ), true ) ) {
					echo '<form class="refm-payout-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="refm_payout"><input type="hidden" name="payout_id" value="' . absint( $row->id ) . '">';
					wp_nonce_field( 'refm_payout_' . $row->id );
					echo '<label class="refm-admin-note-label" for="refm-note-' . absint( $row->id ) . '">' . wp_kses( REFM_UI::icon( 'note' ), REFM_UI::allowed_html() ) . esc_html__( 'Admin note', 'refmora' ) . '</label><p class="refm-review-help">' . esc_html__( 'Visible to the affiliate. Add a note or payment reference.', 'refmora' ) . '</p><textarea id="refm-note-' . absint( $row->id ) . '" name="note" rows="2" maxlength="2000" placeholder="' . esc_attr__( 'Note / payment reference', 'refmora' ) . '">' . esc_textarea( $row->note ) . '</textarea><div class="refm-actions">';
					if ( 'processing' === $row->status ) { echo '<button class="button refm-primary" name="status" value="check">' . wp_kses( REFM_UI::icon( 'view' ), REFM_UI::allowed_html() ) . esc_html__( 'Check payment status', 'refmora' ) . '</button>'; }
					elseif ( 'pending' === $row->status ) { echo '<button class="button button-primary refm-primary" name="status" value="approved">' . wp_kses( REFM_UI::icon( 'check' ), REFM_UI::allowed_html() ) . esc_html__( 'Approve', 'refmora' ) . '</button>'; }
					elseif ( $gateway_job ) { echo '<button class="button refm-primary" name="status" value="processing" data-refm-confirm="' . esc_attr__( 'Send this approved payout through the configured provider? Sandbox/Test sends no real money.', 'refmora' ) . '">' . wp_kses( REFM_UI::icon( 'send' ), REFM_UI::allowed_html() ) . esc_html( 'live' === $gateway_job['mode'] ? __( 'Send payment', 'refmora' ) : __( 'Run Sandbox payout', 'refmora' ) ) . '</button>'; }
					else { echo '<button class="button button-primary refm-primary" name="status" value="completed" data-refm-confirm="' . esc_attr__( 'Confirm that you have already sent this payment. This will settle the reserved commissions.', 'refmora' ) . '">' . wp_kses( REFM_UI::icon( 'wallet' ), REFM_UI::allowed_html() ) . esc_html__( 'Mark paid', 'refmora' ) . '</button>'; }
					if ( 'processing' !== $row->status ) { echo '<button type="submit" class="button refm-primary refm-danger" name="status" value="rejected">' . wp_kses( REFM_UI::icon( 'close' ), REFM_UI::allowed_html() ) . esc_html__( 'Reject', 'refmora' ) . '</button>'; } echo '</div></form>';
				} else { echo '<div class="refm-admin-note-readonly"><span class="refm-admin-note-label">' . wp_kses( REFM_UI::icon( 'note' ), REFM_UI::allowed_html() ) . esc_html__( 'Admin note', 'refmora' ) . '</span><p>' . ( $row->note ? nl2br( esc_html( $row->note ) ) : esc_html__( 'No admin note.', 'refmora' ) ) . '</p></div>'; }
				echo '</details>';
			}
			echo '</td>';
			if ( $action_column ) {
				echo '<td>';
				if ( $manual_review && ! $trash ) {
					self::commission_actions( $row, $order );
				}
				if ( $record_actions ) {
					self::record_actions( $type, $row );
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="' . ( $action_column ? '7' : '6' ) . '" class="refm-empty">' . esc_html__( 'No records found.', 'refmora' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $count, esc_html( $page ), $type, $status ); echo '</section>';
	}
	private static function pagination( $count, $page, $tab, $status = '' ) {
		$total = (int) ceil( $count / self::PAGE_SIZE );
		if ( $total <= 1 ) { return; }
		$page = min( max( 1, $page ), $total );
		$base = add_query_arg( array( 'page' => 'refmora', 'tab' => $tab, 'view' => self::trash_view() ? 'trash' : '', 'status' => $status, 'q' => self::search_term(), 'paged' => 999999999 ), admin_url( 'admin.php' ) );
		$clean_url = function ( $url ) { return remove_query_arg( array( 'saved', 'refm_message', 'refm_error' ), $url ); };
		add_filter( 'get_pagenum_link', $clean_url );
		try { $links = paginate_links( array( 'base' => str_replace( '999999999', '%#%', $base ), 'format' => '', 'current' => $page, 'total' => $total, 'type' => 'array', 'add_args' => false, 'mid_size' => 1, 'end_size' => 1, 'prev_text' => __( 'Previous', 'refmora' ), 'next_text' => __( 'Next', 'refmora' ) ) ); } finally { remove_filter( 'get_pagenum_link', $clean_url ); }
		/* translators: %1$d: current page, %2$d: total pages. */
		echo '<nav class="refm-pagination refm-admin-pagination" aria-label="' . esc_attr__( 'List pages', 'refmora' ) . '"><span class="refm-page-summary">' . sprintf( esc_html__( 'Page %1$d of %2$d', 'refmora' ), esc_html( $page ), esc_html( $total ) ) . '</span><div class="refm-page-links">';
		foreach ( (array) $links as $link ) {
			$link = preg_replace( '/(<a\s[^>]*class=")([^"]*)(")/', '$1$2 refm-primary$3', $link );
			echo wp_kses_post( $link );
		}
		echo '</div></nav>';
	}
}
