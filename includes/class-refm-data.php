<?php
/**
 * Administrator-only erasure of Refmora program records.
 *
 * @package Refmora
 */

defined( 'ABSPATH' ) || exit;

/** Clear plugin records without changing WordPress users or WooCommerce orders. */
final class REFM_Data {
	const GENERATION = 'refm_data_generation';

	/** A separate mutex prevents new program mutations during an explicit clear. */
	public static function lock_name() {
		global $wpdb;
		return 'refm_clear_' . md5( $wpdb->prefix );
	}

	/** Ensure the generation row exists for short locking reads at commit. */
	public static function init() {
		add_option( self::GENERATION, 0, '', false );
	}

	/** Return a fresh generation and the current maintenance-lock owner. */
	public static function state() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uncached cross-request admission check; a reset must invalidate in-flight mutations even with persistent object caching.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE((SELECT option_value FROM %i WHERE option_name=%s),0) AS generation, IS_USED_LOCK(%s) AS clearing', $wpdb->options, self::GENERATION, self::lock_name() ), ARRAY_A );
	}

	/** A current locking read detects resets committed after this transaction began. */
	public static function generation_at_commit() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lock only the reset generation row briefly at commit; affiliate transactions otherwise retain their independent locks.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, self::GENERATION ) );
		REFM_Store::must( null !== $value );
		return (int) $value;
	}

	/** Salt old referral cookies out after a clear, without changing WordPress salts. */
	public static function cookie_key() {
		$generation = (int) get_option( self::GENERATION, 0 );
		return $generation > 0 ? hash_hmac( 'sha256', 'refm-generation:' . $generation, wp_salt( 'auth' ) ) : wp_salt( 'auth' );
	}

	/**
	 * Ignore saved attribution from a cleared program while leaving order metadata intact.
	 *
	 * @param WC_Order $order WooCommerce order whose attribution is being synchronized.
	 * @return bool Whether the attribution belongs to the current program.
	 */
	public static function order_is_current( $order ) {
		$state = self::state();
		return is_array( $state ) && empty( $state['clearing'] ) && (int) $order->get_meta( '_refm_generation' ) === (int) $state['generation'];
	}

	/**
	 * Erase program data only after an administrator types the explicit confirmation.
	 *
	 * The POST handler verifies its action nonce before calling this method.
	 * No user, order, order-item or product record is deleted or updated.
	 *
	 * @param string $confirmation Typed confirmation.
	 * @return string|WP_Error Completion message or an error, with transactional rollback.
	 */
	public static function clear( $confirmation ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'access', __( 'Only administrators can clear Refmora data.', 'refmora' ) );
		}
		if ( 'CLEAR DATA' !== $confirmation ) {
			return new WP_Error( 'confirm', __( 'Type CLEAR DATA to confirm deleting Refmora program records.', 'refmora' ) );
		}
		global $wpdb;
		$held      = false;
		$committed = false;
		$user_ids  = array();
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize only explicit destructive clears; normal affiliate mutations check this mutex without acquiring it.
			$held = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
			if ( ! $held ) {
				return new WP_Error( 'busy', __( 'Refmora data is already being cleared. Please try again shortly.', 'refmora' ) ); }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- DELETE preserves schemas and monotonically increasing IDs; the transaction prevents partial record erasure.
			REFM_Store::must( $wpdb->query( 'START TRANSACTION' ) );
			$generation = self::generation_at_commit();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Never erase the reconciliation records of payments already queued or being sent.
			$processing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE status=%s LIMIT 1 FOR UPDATE', REFM_Store::table( 'payouts' ), 'processing' ) );
			REFM_Store::must( '' === $wpdb->last_error );
			if ( $processing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the transaction without changing any program records when a provider payment is in progress.
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'processing', __( 'A payout is processing. Reconcile it before clearing Refmora data.', 'refmora' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Advancing this plugin-only marker invalidates old order attribution and signed referral cookies without editing WooCommerce records.
			REFM_Store::must( 1 === $wpdb->update( $wpdb->options, array( 'option_value' => (string) ( $generation + 1 ) ), array( 'option_name' => self::GENERATION ) ) );
			foreach ( array( 'link_daily', 'link_totals', 'links', 'customers', 'commissions', 'payouts' ) as $name ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixed allowlist of this site's six Refmora tables; no WordPress or WooCommerce tables are targeted.
				REFM_Store::must( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', REFM_Store::table( $name ) ) ) );
			}
			$cursor = 0;
			$like   = $wpdb->esc_like( '_refm_' ) . '%';
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded batches remove only Refmora-owned profile metadata, preserving users, roles, addresses and all WooCommerce customer data.
				$batch = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE user_id>%d AND meta_key LIKE %s ORDER BY user_id LIMIT 500', $wpdb->usermeta, $cursor, $like ) );
				REFM_Store::must( '' === $wpdb->last_error );
				foreach ( $batch as $user_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Delete only this plugin's exact escaped metadata namespace; cache invalidation follows commit or rollback.
					REFM_Store::must( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id=%d AND meta_key LIKE %s', $wpdb->usermeta, $user_id, $like ) ) );
					$user_ids[] = (int) $user_id;
					$cursor     = (int) $user_id;
				}
				$batch_count = count( $batch );
			} while ( 500 === $batch_count );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove local payment job history only; provider configuration, encrypted credentials and license settings remain unchanged.
			REFM_Store::must( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name=%s', $wpdb->options, $wpdb->esc_like( 'refm_gateway_job_' ) . '%', 'refm_promo_demo_v1' ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- All program record erasure commits together; no external payment or email is initiated.
			REFM_Store::must( $wpdb->query( 'COMMIT' ) );
			$committed = true;
			self::cancel_jobs();
			return __( 'Refmora data cleared. Affiliate enrollments, referral links, clicks, commissions and withdrawal history were removed. WooCommerce customers and orders, plugin settings and license were preserved.', 'refmora' );
		} catch ( Throwable $error ) {
			if ( ! $committed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restore all program tables and metadata when any write fails.
				$wpdb->query( 'ROLLBACK' );
			}
			return new WP_Error( 'clear', __( 'Refmora data could not be cleared completely. Please try again.', 'refmora' ) );
		} finally {
			wp_cache_delete( self::GENERATION, 'options' );
			wp_cache_delete( 'refm_promo_demo_v1', 'options' );
			foreach ( $user_ids as $user_id ) {
				wp_cache_delete( $user_id, 'user_meta' ); }
			wp_cache_set_users_last_changed();
			if ( $held ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the maintenance mutex even when a database operation fails.
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
			}
		}
	}

	/** Cancel only Refmora history jobs; leave WooCommerce and license cron intact. */
	private static function cancel_jobs() {
		foreach ( array( 'refm_release_order', REFM_Gateways::HOOK ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, null, 'refmora' ); }
			wp_unschedule_hook( $hook );
		}
	}
}
