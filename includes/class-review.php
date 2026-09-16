<?php
/**
 * Human-in-the-loop review queue for fuzzy (no-VIN) matches.
 *
 * Held cars are grouped by confidence and can be bulk-approved. Candidates are
 * re-checked live so a product already linked to another feed car is never
 * offered again.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Review {

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'adct_ddpro_review';
	public const  SLUG  = 'adct-ddpro-review';

	private const OPTION_QUEUE = 'adct_ddpro_review_queue';
	private const OPTION_FORCE = 'adct_ddpro_force_create';

	/** Fuzzy score at or above which a car is held rather than created. */
	public const HOLD_THRESHOLD = 60;

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_adct_ddpro_review_bulk', array( $this, 'handle_bulk' ) );
	}

	// -----------------------------------------------------------------
	// Queue storage (used by the importer)
	// -----------------------------------------------------------------

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function queue(): array {
		$q = get_option( self::OPTION_QUEUE, array() );
		return is_array( $q ) ? $q : array();
	}

	public static function count(): int {
		return count( self::queue() );
	}

	/**
	 * @param string                         $dd_id      Feed id.
	 * @param array<string,mixed>            $summary    Feed-side summary.
	 * @param array<int,array<string,mixed>> $candidates Scored candidates.
	 */
	public static function hold( string $dd_id, array $summary, array $candidates ): void {
		$q           = self::queue();
		$q[ $dd_id ] = array(
			'summary'    => $summary,
			'candidates' => array_slice( $candidates, 0, 5 ),
			'held_at'    => time(),
		);
		update_option( self::OPTION_QUEUE, $q, false );
	}

	public static function remove( string $dd_id ): void {
		$q = self::queue();
		if ( isset( $q[ $dd_id ] ) ) {
			unset( $q[ $dd_id ] );
			update_option( self::OPTION_QUEUE, $q, false );
		}
	}

	public static function is_forced( string $dd_id ): bool {
		$f = get_option( self::OPTION_FORCE, array() );
		return is_array( $f ) && isset( $f[ $dd_id ] );
	}

	public static function clear_forced( string $dd_id ): void {
		$f = get_option( self::OPTION_FORCE, array() );
		if ( is_array( $f ) && isset( $f[ $dd_id ] ) ) {
			unset( $f[ $dd_id ] );
			update_option( self::OPTION_FORCE, $f, false );
		}
	}

	private static function force( string $dd_id ): void {
		$f           = get_option( self::OPTION_FORCE, array() );
		$f           = is_array( $f ) ? $f : array();
		$f[ $dd_id ] = 1;
		update_option( self::OPTION_FORCE, $f, false );
	}

	// -----------------------------------------------------------------
	// Admin page
	// -----------------------------------------------------------------

	public function menu(): void {
		$n     = self::count();
		$badge = $n > 0 ? ' <span class="update-plugins count-' . (int) $n . '"><span class="update-count">' . (int) $n . '</span></span>' : '';

		add_submenu_page(
			'woocommerce',
			__( 'DD Pro Review', 'adct-ddpro' ),
			__( 'DD Pro Review', 'adct-ddpro' ) . $badge,
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	private function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Bulk action on the checked cars: link, create-as-new, or skip.
	 * For "link", each car goes to its chosen candidate if that product is still
	 * free, otherwise to the first still-free candidate; if none are free the car
	 * is left in the queue and counted as blocked.
	 */
	public function handle_bulk(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'adct-ddpro' ) );
		}
		check_admin_referer( self::NONCE );

		$do  = isset( $_POST['do'] ) ? sanitize_key( (string) $_POST['do'] ) : '';
		$dds = isset( $_POST['dd_ids'] ) && is_array( $_POST['dd_ids'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['dd_ids'] ) )
			: array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$pick  = isset( $_POST['pick'] ) && is_array( $_POST['pick'] ) ? wp_unslash( $_POST['pick'] ) : array();
		$queue = self::queue();

		$done    = 0;
		$blocked = 0;

		foreach ( $dds as $dd ) {
			if ( 'link' === $do ) {
				$target = 0;
				$pref   = isset( $pick[ $dd ] ) ? (int) $pick[ $dd ] : 0;
				if ( $pref > 0 && $this->is_free( $pref ) ) {
					$target = $pref;
				} else {
					foreach ( (array) ( $queue[ $dd ]['candidates'] ?? array() ) as $c ) {
						$p = (int) ( $c['post_id'] ?? 0 );
						if ( $this->is_free( $p ) ) {
							$target = $p;
							break;
						}
					}
				}
				if ( $target <= 0 ) {
					++$blocked;
					continue;
				}
				$vin    = strtoupper( trim( (string) ( $queue[ $dd ]['summary']['vin'] ?? '' ) ) );
				$result = ( new ADCT_DDPro_Reconciler() )->link( $dd, $target );
				if ( is_wp_error( $result ) ) {
					++$blocked;
					continue;
				}
				if ( 17 === strlen( $vin ) && '' === trim( (string) get_post_meta( $target, '_sku', true ) ) ) {
					update_post_meta( $target, '_sku', $vin );
				}
				self::remove( $dd );
				++$done;
			} elseif ( 'new' === $do ) {
				self::force( $dd );
				self::remove( $dd );
				++$done;
			} elseif ( 'skip' === $do ) {
				self::remove( $dd );
				++$done;
			}
		}

		$msg = 'link' === $do ? 'bulklinked' : ( 'new' === $do ? 'bulknew' : 'bulkskip' );
		$this->redirect( array( 'adct_rev' => $msg, 'n' => $done, 'b' => $blocked ) );
	}

	// -----------------------------------------------------------------
	// Comparison helpers
	// -----------------------------------------------------------------

	private static function digits( string $v ): float {
		return (float) preg_replace( '/[^\d.]/', '', $v );
	}

	/**
	 * @param array<string,mixed> $s Feed summary.
	 * @param array<string,mixed> $c Candidate.
	 * @return array{match:array<int,string>,diffs:array<int,string>}
	 */
	private function match_note( array $s, array $c ): array {
		$reasons = (string) ( $c['reasons'] ?? '' );

		$match = array( 'year', 'make' );
		if ( str_contains( $reasons, 'model' ) ) {
			$match[] = 'model';
		}

		$diffs = array();

		$fp = self::digits( (string) ( $s['price'] ?? '' ) );
		$cp = (float) ( $c['price'] ?? 0 );
		if ( $fp > 0 && $cp > 0 ) {
			if ( abs( $fp - $cp ) / max( $fp, $cp ) <= 0.001 ) {
				$match[] = 'price';
			} else {
				$diffs[] = sprintf( 'price — feed AED %s vs site AED %s', number_format( $fp ), number_format( $cp ) );
			}
		}

		$fk = self::digits( (string) ( $s['mileage'] ?? '' ) );
		$ck = (float) ( $c['mileage'] ?? 0 );
		if ( $fk > 0 && $ck > 0 ) {
			if ( abs( $fk - $ck ) / max( $fk, $ck ) <= 0.02 ) {
				$match[] = 'mileage';
			} else {
				$diffs[] = sprintf( 'mileage — feed %s km vs site %s km', number_format( $fk ), number_format( $ck ) );
			}
		}

		$fc = strtolower( trim( (string) ( $s['color'] ?? '' ) ) );
		$cc = strtolower( trim( (string) ( $c['color'] ?? '' ) ) );
		if ( '' !== $fc && '' !== $cc ) {
			if ( $fc === $cc || str_contains( $cc, $fc ) || str_contains( $fc, $cc ) ) {
				$match[] = 'colour';
			} else {
				$diffs[] = sprintf( 'colour — feed %s vs site %s', (string) $s['color'], (string) $c['color'] );
			}
		}

		return array( 'match' => $match, 'diffs' => $diffs );
	}

	/**
	 * @param array{match:array<int,string>,diffs:array<int,string>} $note From match_note().
	 */
	private function note_text( array $note ): string {
		$out = '';
		if ( ! empty( $note['match'] ) ) {
			$out .= sprintf( __( 'Matches on %s.', 'adct-ddpro' ), implode( ', ', $note['match'] ) );
		}
		if ( ! empty( $note['diffs'] ) ) {
			$out .= ' ' . sprintf( __( 'Differs on %s.', 'adct-ddpro' ), implode( '; ', $note['diffs'] ) );
		}
		return trim( $out );
	}

	/** A product is free to link only if it is not already tied to a feed car. */
	private function is_free( int $pid ): bool {
		return $pid > 0 && '' === trim( (string) get_post_meta( $pid, ADCT_DDPRO_META_ID, true ) );
	}

	/**
	 * Stored candidates that are still available (not already linked elsewhere).
	 *
	 * @param array<string,mixed> $item Queue entry.
	 * @return array<int,array<string,mixed>>
	 */
	private function free_candidates( array $item ): array {
		$out = array();
		foreach ( (array) ( $item['candidates'] ?? array() ) as $c ) {
			if ( $this->is_free( (int) ( $c['post_id'] ?? 0 ) ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	// -----------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$queue = self::queue();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['adct_rev'] ) ? sanitize_key( (string) $_GET['adct_rev'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$num = isset( $_GET['n'] ) ? (int) $_GET['n'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$blk = isset( $_GET['b'] ) ? (int) $_GET['b'] : 0;

		$groups = array( 'match' => array(), 'differ' => array(), 'maybe' => array(), 'taken' => array() );
		foreach ( $queue as $dd => $item ) {
			$free = $this->free_candidates( $item );
			if ( ! $free ) {
				$groups['taken'][ $dd ] = array( 'item' => $item );
				continue;
			}
			$best  = (array) $free[0];
			$note  = $this->match_note( (array) ( $item['summary'] ?? array() ), $best );
			$score = (int) ( $best['score'] ?? 0 );
			$g     = ( $score >= 85 && ! $note['diffs'] ) ? 'match' : ( $score >= 85 ? 'differ' : 'maybe' );
			$groups[ $g ][ $dd ] = array( 'item' => $item, 'best' => $best, 'note' => $note, 'free' => $free );
		}

		$meta = array(
			'match'  => array( '&#10003;', __( 'Same car — everything matches', 'adct-ddpro' ), '#1a7f37' ),
			'differ' => array( '&#9888;', __( 'Same car, but some details differ', 'adct-ddpro' ), '#bd8600' ),
			'maybe'  => array( '&#9888;', __( 'Possibly the same car — check before linking', 'adct-ddpro' ), '#8a6d00' ),
			'taken'  => array( '&#9888;', __( 'No free match left — likely a duplicate feed listing', 'adct-ddpro' ), '#b32d2e' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DD Pro — Review matches', 'adct-ddpro' ); ?></h1>

			<?php
			$notices = array(
				'bulklinked' => sprintf( _n( '%d car linked — SEO kept.', '%d cars linked — SEO kept.', $num, 'adct-ddpro' ), $num ),
				'bulknew'    => sprintf( _n( '%d car marked as new.', '%d cars marked as new.', $num, 'adct-ddpro' ), $num ),
				'bulkskip'   => sprintf( _n( '%d car skipped.', '%d cars skipped.', $num, 'adct-ddpro' ), $num ),
			);
			if ( isset( $notices[ $msg ] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p>
					<?php echo esc_html( $notices[ $msg ] ); ?>
					<?php if ( 'bulklinked' === $msg && $blk > 0 ) : ?>
						<?php echo ' '; printf( esc_html( _n( '%d could not be linked (its match was already taken) — see the red group.', '%d could not be linked (their matches were already taken) — see the red group.', $blk, 'adct-ddpro' ) ), (int) $blk ); ?>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<p class="description" style="max-width:900px;font-size:14px;">
				<?php esc_html_e( 'These feed cars have no VIN link yet but look like products already on your site. Tick the ones you accept, then use the bulk buttons. The green group is safe to approve in one go; the amber groups differ on something, so check them first.', 'adct-ddpro' ); ?>
			</p>

			<?php if ( ! $queue ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Nothing waiting for review.', 'adct-ddpro' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=adct_ddpro_review_bulk' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>

				<div style="position:sticky;top:32px;z-index:5;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:10px 14px;margin:8px 0 16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;max-width:1000px;">
					<strong style="margin-right:6px;"><?php esc_html_e( 'With selected:', 'adct-ddpro' ); ?></strong>
					<button type="submit" name="do" value="link" class="button button-primary"><?php esc_html_e( 'Link (adopt, keep SEO)', 'adct-ddpro' ); ?></button>
					<button type="submit" name="do" value="new" class="button"><?php esc_html_e( 'Create as new', 'adct-ddpro' ); ?></button>
					<button type="submit" name="do" value="skip" class="button button-link" style="color:#888;"><?php esc_html_e( 'Skip', 'adct-ddpro' ); ?></button>
				</div>

				<?php foreach ( array( 'match', 'differ', 'maybe', 'taken' ) as $g ) :
					$rows = $groups[ $g ];
					if ( ! $rows ) {
						continue;
					}
					$m = $meta[ $g ];
					?>
					<h2 style="color:<?php echo esc_attr( $m[2] ); ?>;margin:22px 0 6px;">
						<span style="font-size:20px;"><?php echo wp_kses_post( $m[0] ); ?></span>
						<?php echo esc_html( $m[1] ); ?>
						<span style="color:#666;font-weight:normal;">(<?php echo count( $rows ); ?>)</span>
					</h2>
					<label style="display:inline-block;margin:0 0 6px;font-size:13px;cursor:pointer;">
						<input type="checkbox" class="ddpro-selall" data-grp="<?php echo esc_attr( $g ); ?>">
						<?php esc_html_e( 'Select all in this group', 'adct-ddpro' ); ?>
					</label>
					<table class="widefat striped" style="border-left:4px solid <?php echo esc_attr( $m[2] ); ?>;max-width:1000px;">
						<tbody>
						<?php foreach ( $rows as $dd => $r ) :
							$s        = (array) ( $r['item']['summary'] ?? array() );
							$is_taken = ( 'taken' === $g );
							$best     = $is_taken ? array() : (array) ( $r['best'] ?? array() );
							$free     = $is_taken ? array() : (array) ( $r['free'] ?? array() );
							$others   = array_slice( $free, 1 );
							$note     = $is_taken ? array( 'match' => array(), 'diffs' => array() ) : (array) ( $r['note'] ?? array() );
							$feed     = trim( sprintf( '%s%s%s',
								'' !== (string) ( $s['mileage'] ?? '' ) ? $s['mileage'] . ' km' : '',
								'' !== (string) ( $s['price'] ?? '' ) ? ' · AED ' . $s['price'] : '',
								'' !== (string) ( $s['color'] ?? '' ) ? ' · ' . $s['color'] : ''
							) );
							?>
							<tr>
								<td style="width:32px;vertical-align:top;padding-top:12px;">
									<input type="checkbox" name="dd_ids[]" value="<?php echo esc_attr( $dd ); ?>" class="ddpro-cb-<?php echo esc_attr( $g ); ?>">
								</td>
								<td>
									<div style="font-weight:600;"><?php echo esc_html( (string) ( $s['label'] ?? $dd ) ); ?></div>
									<div style="color:#666;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Feed', 'adct-ddpro' ); ?>: <?php echo esc_html( $feed ); ?> · <?php echo esc_html( $dd ); ?></div>
									<?php if ( $is_taken ) :
										$tid = (int) ( $r['item']['candidates'][0]['post_id'] ?? 0 );
										?>
										<div style="color:#b32d2e;font-size:12px;">
											<?php
											printf(
												/* translators: 1: product id, 2: dd_id it belongs to. */
												esc_html__( 'Its match #%1$d is already linked to another feed car (%2$s) — usually a duplicate listing on DD Pro. Use "Create as new" if it is genuinely different, or "Skip".', 'adct-ddpro' ),
												$tid,
												esc_html( (string) get_post_meta( $tid, ADCT_DDPRO_META_ID, true ) )
											);
											?>
										</div>
									<?php elseif ( $best ) : ?>
										<label style="display:block;cursor:pointer;">
											<input type="radio" name="pick[<?php echo esc_attr( $dd ); ?>]" value="<?php echo esc_attr( (string) ( $best['post_id'] ?? 0 ) ); ?>" checked>
											&#8594; <a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) ( $best['post_id'] ?? 0 ) . '&action=edit' ) ); ?>" target="_blank">#<?php echo (int) ( $best['post_id'] ?? 0 ); ?> <?php echo esc_html( mb_substr( (string) ( $best['title'] ?? '' ), 0, 56 ) ); ?></a>
											<span style="color:#555;font-size:12px;"> — <?php echo esc_html( $this->note_text( $note ) ); ?></span>
										</label>
										<?php if ( $others ) : ?>
											<details style="margin-top:3px;">
												<summary style="cursor:pointer;color:#2271b1;font-size:12px;"><?php echo esc_html( sprintf( _n( '%d other match', '%d other matches', count( $others ), 'adct-ddpro' ), count( $others ) ) ); ?></summary>
												<?php foreach ( $others as $c ) : $onote = $this->match_note( $s, $c ); ?>
													<label style="display:block;padding:4px 0 4px 16px;cursor:pointer;font-size:12px;">
														<input type="radio" name="pick[<?php echo esc_attr( $dd ); ?>]" value="<?php echo esc_attr( (string) ( $c['post_id'] ?? 0 ) ); ?>">
														#<?php echo (int) ( $c['post_id'] ?? 0 ); ?> <?php echo esc_html( mb_substr( (string) ( $c['title'] ?? '' ), 0, 52 ) ); ?>
														<span style="color:#888;"> — <?php echo esc_html( $this->note_text( $onote ) ); ?></span>
													</label>
												<?php endforeach; ?>
											</details>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

				<div style="border:1px solid #c3c4c7;border-radius:6px;padding:10px 14px;margin:16px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;max-width:1000px;">
					<strong style="margin-right:6px;"><?php esc_html_e( 'With selected:', 'adct-ddpro' ); ?></strong>
					<button type="submit" name="do" value="link" class="button button-primary"><?php esc_html_e( 'Link (adopt, keep SEO)', 'adct-ddpro' ); ?></button>
					<button type="submit" name="do" value="new" class="button"><?php esc_html_e( 'Create as new', 'adct-ddpro' ); ?></button>
					<button type="submit" name="do" value="skip" class="button button-link" style="color:#888;"><?php esc_html_e( 'Skip', 'adct-ddpro' ); ?></button>
				</div>
			</form>

			<script>
			( function () {
				document.querySelectorAll( '.ddpro-selall' ).forEach( function ( sa ) {
					sa.addEventListener( 'change', function () {
						document.querySelectorAll( '.ddpro-cb-' + sa.dataset.grp ).forEach( function ( cb ) { cb.checked = sa.checked; } );
					} );
				} );
			} )();
			</script>
		</div>
		<?php
	}
}
