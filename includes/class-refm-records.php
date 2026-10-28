<?php
/**
 * Administrator record removal and reversible financial history trash.
 *
 * @package Refmora
 */

defined( 'ABSPATH' ) || exit;

/** Keep record removal serialized with balance changes and program resets. */
final class REFM_Records {
	/** @return bool Whether the current user may remove program records. */
	public static function allowed() {
		return current_user_can( 'manage_woocommerce' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Bind an affiliate deletion form to its current enrollment lifetime.
	 *
	 * @param int $id Affiliate user ID.
	 * @return string Enrollment version.
	 */
	public static function affiliate_version( $id ) {
		return hash( 'sha256', absint( $id ) . ':' . REFM_Data::affiliate_epoch( $id ) );
	}

	/**
	 * Bind a trash/restore form to the displayed record and financial values.
	 *
	 * @param object $row Financial record.
	 * @return string Financial record version.
	 */
	public static function version( $row ) {
		return hash( 'sha256', implode( '|', array( $row->id, $row->affiliate_id, $row->amount, $row->currency, $row->status, $row->updated_at, $row->paid_amount ?? '', $row->payout_id ?? '', $row->trashed_at ?? '' ) ) );
	}

	/**
	 * Count active and trashed records independently of table filters and pagination.
	 *
	 * @param string $type Financial record type.
	 * @return array Active and trashed totals.
	 */
	public static function counts( $type ) {
		$counts = array(
			'records' => 0,
			'trash'   => 0,
		);
		if ( ! self::allowed() || ! in_array( $type, array( 'commissions', 'payouts' ), true ) ) {
			return $counts;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh, unfiltered totals for the two administrator record-view badges; the table name comes from a fixed allowlist.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(trashed_at IS NULL),0) AS records, COALESCE(SUM(trashed_at IS NOT NULL),0) AS trash FROM %i', REFM_Store::table( $type ) ), ARRAY_A );
		if ( is_array( $row ) ) {
			$counts['records'] = max( 0, (int) $row['records'] );
			$counts['trash']   = max( 0, (int) $row['trash'] );
		}
		return $counts;
	}

	/**
	 * Clear a bounded page of Trash through the same checks as individual deletion.
	 *
	 * @param string   $type    Financial record type.
	 * @param int      $cursor  Last scanned record ID.
	 * @param int|null $ceiling Highest record ID when clearing began.
	 * @return array|WP_Error Batch progress or validation error.
	 */
	public static function clear_trash( $type, $cursor = 0, $ceiling = null ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'access', __( 'Only administrators can clear Trash.', 'refmora' ) );
		}
		if ( ! in_array( $type, array( 'commissions', 'payouts' ), true ) ) {
			return new WP_Error( 'invalid', __( 'Invalid record type.', 'refmora' ) );
		}
		global $wpdb;
		$table  = REFM_Store::table( $type );
		$cursor = absint( $cursor );
		if ( null === $ceiling ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bound this clearing pass to existing IDs, independent of the current search, filters or pagination.
			$ceiling = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM %i WHERE trashed_at IS NOT NULL', $table ) );
			if ( '' !== $wpdb->last_error ) {
				return new WP_Error( 'storage', __( 'Trash could not be read. Refresh the page and try again.', 'refmora' ) );
			}
		}
		$ceiling = absint( $ceiling );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Keyset pagination keeps memory bounded; each candidate is reloaded and version-checked under its own financial locks before deletion.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE trashed_at IS NOT NULL AND id>%d AND id<=%d ORDER BY id ASC LIMIT 25', $table, $cursor, $ceiling ) );
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'storage', __( 'Trash could not be read. Refresh the page and try again.', 'refmora' ) );
		}
		$progress = array(
			'cursor'      => $cursor,
			'ceiling'     => $ceiling,
			'deleted'     => 0,
			'skipped'     => 0,
			'first_error' => '',
			'done'        => false,
		);
		$started  = microtime( true );
		foreach ( $rows as $row ) {
			$result             = self::change( $type, $row->id, 'delete', self::version( $row ) );
			$progress['cursor'] = (int) $row->id;
			if ( is_wp_error( $result ) ) {
				++$progress['skipped'];
				if ( '' === $progress['first_error'] ) {
					$progress['first_error'] = $result->get_error_message();
				}
			} else {
				++$progress['deleted'];
			}
			if ( microtime( true ) - $started >= 8 ) {
				break;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Failed or reserved rows below the cursor stay in Trash; they do not create an endless retry loop.
		$remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE trashed_at IS NOT NULL AND id>%d AND id<=%d ORDER BY id ASC LIMIT 1', $table, $progress['cursor'], $ceiling ) );
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'storage', __( 'Some items may have been removed. Refresh Trash before trying again.', 'refmora' ) );
		}
		$progress['done'] = null === $remaining;
		return $progress;
	}

	/**
	 * Permanently erase one affiliate's plugin data while keeping the WordPress user.
	 *
	 * @param int    $id           Affiliate user ID.
	 * @param string $confirmation Typed DELETE confirmation.
	 * @param string $version      Enrollment version shown in the form.
	 * @return string|WP_Error Completion notice or error.
	 */
	public static function delete_affiliate( $id, $confirmation, $version ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'access', __( 'Only administrators can delete Refmora records.', 'refmora' ) );
		}
		$id = absint( $id );
		if ( ! $id || 'DELETE' !== $confirmation || ! is_string( $version ) ) {
			return new WP_Error( 'confirm', __( 'Type DELETE to confirm permanently removing this affiliate and all their Refmora history.', 'refmora' ) );
		}
		global $wpdb;
		$result = REFM_Store::locked(
			$id,
			static function () use ( $wpdb, $id, $version ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh enrollment check under the affiliate lock; never delete a WordPress user or customer record.
				$enrolled = $wpdb->get_var( $wpdb->prepare( 'SELECT umeta_id FROM %i WHERE user_id=%d AND meta_key=%s AND meta_value=%s LIMIT 1', $wpdb->usermeta, $id, '_refm_enrolled', 'yes' ) );
				if ( ! $enrolled || ! hash_equals( self::affiliate_version( $id ), $version ) ) {
					return new WP_Error( 'changed', __( 'This affiliate changed or was already deleted. Refresh the list.', 'refmora' ) );
				}
				$payouts = REFM_Store::table( 'payouts' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The same affiliate lock protects provider processing, so an in-flight payment cannot lose its reconciliation records.
				$processing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE affiliate_id=%d AND status=%s LIMIT 1', $payouts, $id, 'processing' ) );
				REFM_Store::must( '' === $wpdb->last_error );
				if ( $processing ) {
					return new WP_Error( 'processing', __( 'This affiliate has a processing payout. Reconcile it before deleting the affiliate.', 'refmora' ) );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A minimal revocation marker makes old order attribution and signed cookies unusable, including after re-enrollment.
				REFM_Store::must( $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=CAST(option_value AS UNSIGNED)+1', $wpdb->options, 'refm_affiliate_epoch_' . $id, '1', 'no' ) ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove only payment-job options belonging to the payout rows being erased; provider credentials and other affiliates remain untouched.
				REFM_Store::must( $wpdb->query( $wpdb->prepare( 'DELETE o FROM %i AS o INNER JOIN %i AS p ON o.option_name=CONCAT(%s,p.id) WHERE p.affiliate_id=%d', $wpdb->options, $payouts, 'refm_gateway_job_', $id ) ) );
				foreach ( array( 'commissions', 'payouts', 'customers', 'link_daily', 'link_totals' ) as $name ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixed allowlist of plugin tables; erase only rows owned by this affiliate, including trashed history.
					REFM_Store::must( $wpdb->delete( REFM_Store::table( $name ), array( 'affiliate_id' => $id ), array( '%d' ) ) );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Delete only Refmora-owned profile metadata atomically with its ledger; WordPress roles, login, addresses and customer data are preserved.
				REFM_Store::must( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id=%d AND meta_key LIKE %s', $wpdb->usermeta, $id, $wpdb->esc_like( '_refm_' ) . '%' ) ) );
				return __( 'Affiliate and all their Refmora history permanently deleted. The WordPress customer account and WooCommerce orders were preserved.', 'refmora' );
			}
		);
		wp_cache_delete( $id, 'user_meta' );
		wp_cache_delete( 'refm_affiliate_epoch_' . $id, 'options' );
		wp_cache_set_users_last_changed();
		return $result;
	}

	/**
	 * Trash, restore or permanently delete one financial record.
	 *
	 * @param string $type      Commissions or payouts.
	 * @param int    $id        Record ID.
	 * @param string $operation Trash, restore or delete.
	 * @param string $version   Version shown in the form.
	 * @return string|WP_Error Completion notice or error.
	 */
	public static function change( $type, $id, $operation, $version ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'access', __( 'Only administrators can delete or restore Refmora records.', 'refmora' ) );
		}
		if ( ! in_array( $type, array( 'commissions', 'payouts' ), true ) || ! in_array( $operation, array( 'trash', 'restore', 'delete' ), true ) || ! is_string( $version ) ) {
			return new WP_Error( 'invalid', __( 'Invalid record action.', 'refmora' ) );
		}
		global $wpdb;
		$table = REFM_Store::table( $type );
		$id    = absint( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Resolve the owning affiliate before acquiring its normal financial lock; reload and compare the record inside the lock.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $id ) );
		if ( ! $row ) {
			return new WP_Error( 'missing', __( 'Record not found. Refresh the list.', 'refmora' ) );
		}
		$affiliate = (int) $row->affiliate_id;
		$locks     = array();
		if ( 'commissions' === $type ) {
			if ( ! empty( $row->customer_hash ) ) {
				$locks[] = 'customer-email:' . $row->customer_hash;
			}
			if ( ! empty( $row->customer_id ) ) {
				$locks[] = 'customer-account:' . $row->customer_id;
			}
		}
		$result = REFM_Store::locked(
			$affiliate,
			static function () use ( $wpdb, $table, $type, $id, $affiliate, $operation, $version ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh state under the balance lock prevents stale forms or a parallel payout from changing the meaning of this action.
				$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $id ) );
				if ( ! $current || (int) $current->affiliate_id !== $affiliate || ! hash_equals( self::version( $current ), $version ) || ( 'trash' === $operation ) === ! empty( $current->trashed_at ) ) {
					return new WP_Error( 'changed', __( 'This record changed. Refresh the list and try again.', 'refmora' ) );
				}
				if ( 'processing' === $current->status || ( 'commissions' === $type && $current->payout_id ) ) {
					return new WP_Error( 'reserved', __( 'This record is involved in a payout. Resolve or cancel the withdrawal before moving it to Trash.', 'refmora' ) );
				}
				if ( 'payouts' === $type && in_array( $operation, array( 'trash', 'delete' ), true ) ) {
					if ( in_array( $current->status, array( 'pending', 'approved' ), true ) ) {
						if ( 'delete' === $operation ) {
							return new WP_Error( 'active', __( 'Cancel this withdrawal before permanently deleting its history.', 'refmora' ) );
						}
						$released = REFM_Payouts::release( $id, __( 'Withdrawal cancelled by an administrator and moved to Trash.', 'refmora' ) );
						if ( is_wp_error( $released ) ) {
							return $released;
						}
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A closed withdrawal must not leave reserved commissions behind when its history is hidden.
					$reserved = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE payout_id=%d LIMIT 1', REFM_Store::table( 'commissions' ), $id ) );
					REFM_Store::must( '' === $wpdb->last_error );
					if ( $reserved ) {
						return new WP_Error( 'reserved', __( 'Reserved commissions still reference this withdrawal. Reconcile it before moving it to Trash.', 'refmora' ) );
					}
				}
				if ( 'delete' === $operation ) {
					if ( 'commissions' === $type ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Store only an order-ID revocation marker in the same transaction as erasure, so retries and refund hooks cannot recreate this commission.
						REFM_Store::must( $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=%s', $wpdb->options, 'refm_deleted_commission_' . absint( $current->order_id ), '1', 'no', '1' ) ) );
					} else {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove only this closed withdrawal's local provider job; no provider API or payment is invoked.
						REFM_Store::must( $wpdb->delete( $wpdb->options, array( 'option_name' => 'refm_gateway_job_' . $id ), array( '%s' ) ) );
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A current version and Trash membership were verified above under the affiliate's financial lock.
					REFM_Store::must( 1 === $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ) );
					return true;
				}
				$data = array(
					'trashed_at' => 'trash' === $operation ? current_time( 'mysql', true ) : null,
					'trashed_by' => 'trash' === $operation ? get_current_user_id() : 0,
					'updated_at' => current_time( 'mysql', true ),
				);
				if ( 'commissions' === $type && 'trash' === $operation && (float) $current->paid_amount <= 0 ) {
					$data['amount'] = 0;
					$data['status'] = 'rejected';
					if ( 'claimed' === $current->eligibility ) {
						$data['eligibility'] = 'eligible';
					}
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Preserve the durable order-linked row so refunds and scheduled callbacks cannot recreate a trashed unpaid commission.
				REFM_Store::must( $wpdb->update( $table, $data, array( 'id' => $id ) ) );
				return true;
			},
			$locks
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'delete' === $operation ) {
			if ( 'payouts' === $type ) {
				wp_cache_delete( 'refm_gateway_job_' . $id, 'options' );
			}
			return __( 'Record permanently deleted.', 'refmora' );
		}
		if ( 'restore' === $operation && 'commissions' === $type ) {
			$synced = REFM_Commissions::sync( $row->order_id );
			if ( is_wp_error( $synced ) ) {
				return __( 'Record restored. Commission recalculation is pending; check its order before making a payout.', 'refmora' );
			}
		}
		if ( 'trash' === $operation ) {
			return __( 'Record moved to Trash.', 'refmora' );
		}
		return 'commissions' === $type ? __( 'Commission history restored using the current order rules.', 'refmora' ) : __( 'Withdrawal history restored. Cancelled withdrawals remain cancelled.', 'refmora' );
	}
}
