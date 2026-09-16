<?php
/**
 * Admin screen: settings, manual sync, dry run, log.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Admin {

	private const CAP   = 'manage_woocommerce';
	private const NONCE = 'adct_ddpro_action';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_adct_ddpro_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_adct_ddpro_sync', array( $this, 'handle_sync' ) );
		add_action( 'admin_post_adct_ddpro_apply_matches', array( $this, 'handle_apply_matches' ) );
		add_action( 'admin_post_adct_ddpro_cleanup_photos', array( $this, 'handle_cleanup_photos' ) );
	}

	/**
	 * One-time cleanup: undo feed photos that were imported onto products which
	 * already had their own gallery (the pre-existing cars linked by VIN, spec
	 * match, or the verified-matches tool). It ONLY removes attachments this
	 * plugin itself downloaded from the feed — recorded in the per-product photo
	 * map — never a product's original photography, and never the featured image.
	 * It also clears any pending photo queue so nothing more downloads.
	 *
	 * Products the feed CREATED are left untouched: their gallery is feed photos
	 * by definition.
	 */
	public function handle_cleanup_photos(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'adct-ddpro' ) );
		}
		check_admin_referer( self::NONCE );

		$apply = isset( $_POST['apply'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s)",
				ADCT_DDPRO_META_PHOTOS,
				ADCT_DDPRO_META_QUEUE
			)
		);

		$report = array(
			'apply'          => $apply,
			'products'       => 0,
			'photos_removed' => 0,
			'queued_cleared' => 0,
			'rows'           => array(),
		);

		foreach ( (array) $ids as $pid ) {
			$pid = (int) $pid;

			// Feed-created products keep their (feed) gallery.
			if ( '1' === (string) get_post_meta( $pid, ADCT_DDPRO_META_CREATED, true ) ) {
				continue;
			}

			$map = get_post_meta( $pid, ADCT_DDPRO_META_PHOTOS, true );
			$map = is_array( $map ) ? $map : array();

			$queue = get_post_meta( $pid, ADCT_DDPRO_META_QUEUE, true );
			$queue = is_array( $queue ) ? $queue : array();

			$thumb   = (int) get_post_thumbnail_id( $pid );
			$gallery = array_values( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) ) ) );

			// Attachment IDs this plugin imported from the feed.
			$feed_atts = array_values( array_filter( array_map( 'intval', array_values( $map ) ), static fn( int $a ): bool => $a > 0 ) );

			// Remove only feed images that are actually in the gallery, never the featured image.
			$remove = array_values( array_diff( array_intersect( $gallery, $feed_atts ), array( $thumb ) ) );

			$qn = count( $queue );
			if ( ! $remove && 0 === $qn ) {
				continue;
			}

			++$report['products'];
			$report['photos_removed'] += count( $remove );
			$report['queued_cleared'] += $qn;
			$report['rows'][]          = array(
				'id'     => $pid,
				'title'  => get_the_title( $pid ),
				'remove' => count( $remove ),
				'queued' => $qn,
			);

			if ( $apply ) {
				if ( $remove ) {
					update_post_meta( $pid, '_product_image_gallery', implode( ',', array_values( array_diff( $gallery, $remove ) ) ) );
					foreach ( $remove as $att ) {
						wp_delete_attachment( (int) $att, true );
					}
					// Mark those feed URLs as seen (value 0) so a later sync never re-imports them.
					foreach ( $map as $k => $v ) {
						if ( in_array( (int) $v, $remove, true ) ) {
							$map[ $k ] = 0;
						}
					}
					update_post_meta( $pid, ADCT_DDPRO_META_PHOTOS, $map );
				}
				if ( $qn ) {
					delete_post_meta( $pid, ADCT_DDPRO_META_QUEUE );
				}
				ADCT_DDPro_Logger::change(
					sprintf( 'Photo cleanup: removed %d feed image(s) from the gallery and cleared %d queued download(s) on #%d ("%s").', count( $remove ), $qn, $pid, get_the_title( $pid ) ),
					(string) get_post_meta( $pid, ADCT_DDPRO_META_ID, true ),
					$pid
				);
			}
		}

		set_transient( 'adct_ddpro_cleanup_report', $report, 600 );
		$this->redirect( array( 'adct_msg' => $apply ? 'cleanup_applied' : 'cleanup_preview' ) );
	}

	/**
	 * Load the bundled verified-matches map (staging's confirmed dd_id/GTIN per
	 * product ID), or an empty list if the file is absent.
	 *
	 * @return array<int,array{id:int,dd_id:string,gtin:string}>
	 */
	private function verified_matches(): array {
		$file = ADCT_DDPRO_DIR . 'includes/verified-matches.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * One-time migration: stamp the verified DD Pro ID + GTIN onto EXISTING
	 * products, matched by the product ID that is stable between the staging
	 * mirror and live. Preview writes nothing; apply writes only the two ID
	 * fields and only where they differ. It NEVER creates a product, never
	 * touches status, content, price or SEO, and skips any product that already
	 * carries a DIFFERENT DD Pro ID (a conflict for a human to resolve).
	 */
	public function handle_apply_matches(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'adct-ddpro' ) );
		}
		check_admin_referer( self::NONCE );

		$apply   = isset( $_POST['apply'] );
		$matches = $this->verified_matches();

		$report = array(
			'apply'    => $apply,
			'total'    => count( $matches ),
			'to_set'   => 0,
			'already'  => 0,
			'conflict' => 0,
			'missing'  => 0,
			'rows'     => array(),
		);

		foreach ( $matches as $m ) {
			$id   = (int) ( $m['id'] ?? 0 );
			$dd   = (string) ( $m['dd_id'] ?? '' );
			$gtin = (string) ( $m['gtin'] ?? '' );

			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post || 'product' !== $post->post_type ) {
				++$report['missing'];
				$report['rows'][] = array( 'id' => $id, 'dd_id' => $dd, 'state' => 'missing', 'title' => '' );
				continue;
			}

			$cur_dd   = (string) get_post_meta( $id, ADCT_DDPRO_META_ID, true );
			$cur_gtin = (string) get_post_meta( $id, ADCT_DDPRO_META_GTIN, true );

			if ( '' !== $cur_dd && $cur_dd !== $dd ) {
				++$report['conflict'];
				$report['rows'][] = array( 'id' => $id, 'dd_id' => $dd, 'state' => 'conflict', 'title' => get_the_title( $id ), 'cur' => $cur_dd );
				continue;
			}

			if ( $cur_dd === $dd && $cur_gtin === $gtin ) {
				++$report['already'];
				continue;
			}

			++$report['to_set'];
			$report['rows'][] = array( 'id' => $id, 'dd_id' => $dd, 'gtin' => $gtin, 'state' => 'set', 'title' => get_the_title( $id ) );

			if ( $apply ) {
				update_post_meta( $id, ADCT_DDPRO_META_ID, $dd );
				update_post_meta( $id, ADCT_DDPRO_META_GTIN, $gtin );
				ADCT_DDPro_Logger::change(
					sprintf( 'Applied verified match: set DD Pro ID %s and GTIN %s on #%d ("%s").', $dd, $gtin, $id, get_the_title( $id ) ),
					$dd,
					$id
				);
			}
		}

		set_transient( 'adct_ddpro_matches_report', $report, 600 );
		$this->redirect( array( 'adct_msg' => $apply ? 'matches_applied' : 'matches_preview' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'DD Pro Sync', 'adct-ddpro' ),
			__( 'DD Pro Sync', 'adct-ddpro' ),
			self::CAP,
			ADCT_DDPRO_SLUG,
			array( $this, 'render' )
		);
	}

	private function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . ADCT_DDPRO_SLUG ) ) );
		exit;
	}

	public function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'adct-ddpro' ) );
		}
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		ADCT_DDPro_Settings::save( wp_unslash( $_POST ) );

		$this->redirect( array( 'adct_msg' => 'saved' ) );
	}

	public function handle_sync(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'adct-ddpro' ) );
		}
		check_admin_referer( self::NONCE );

		$dry = isset( $_POST['dry_run'] );

		ADCT_DDPro_Logger::init();
		$summary = ( new ADCT_DDPro_Importer() )->run( $dry );

		set_transient( 'adct_ddpro_last_summary', $summary, 300 );

		$this->redirect( array( 'adct_msg' => $dry ? 'dryrun' : 'synced' ) );
	}

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$s          = ADCT_DDPro_Settings::all();
		$from_const = ADCT_DDPro_Settings::feed_url_is_constant();
		$summary    = get_transient( 'adct_ddpro_last_summary' );
		$last_run   = get_option( 'adct_ddpro_last_run' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['adct_msg'] ) ? sanitize_key( (string) $_GET['adct_msg'] ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DD Pro Sync', 'adct-ddpro' ); ?></h1>

			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'adct-ddpro' ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $summary ) && in_array( $msg, array( 'synced', 'dryrun' ), true ) ) : ?>
				<div class="notice <?php echo $summary['errors'] > 0 ? 'notice-warning' : 'notice-success'; ?>">
					<p><strong><?php echo esc_html( (string) $summary['message'] ); ?></strong></p>
					<?php if ( 'dryrun' === $msg ) : ?>
						<p><em><?php esc_html_e( 'Dry run — nothing was written to the database.', 'adct-ddpro' ); ?></em></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p><strong><?php esc_html_e( 'What this plugin will and will not overwrite', 'adct-ddpro' ); ?></strong></p>
				<p>
					<?php esc_html_e( 'On a NEW car the feed fills in everything and the product is created as a draft so you can write the title and description before publishing.', 'adct-ddpro' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'On an EXISTING car the feed may only change: price, mileage, warranty, stock status, and newly added photos. It will never touch the product title, description, excerpt, or any Yoast SEO field.', 'adct-ddpro' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Run a sync', 'adct-ddpro' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="adct_ddpro_sync">
				<p>
					<button type="submit" name="dry_run" value="1" class="button button-secondary">
						<?php esc_html_e( 'Dry run (preview only)', 'adct-ddpro' ); ?>
					</button>
					<button type="submit" class="button button-primary"
						onclick="return confirm('<?php echo esc_js( __( 'Run a real sync now? New cars will be created as drafts.', 'adct-ddpro' ) ); ?>');">
						<?php esc_html_e( 'Sync now', 'adct-ddpro' ); ?>
					</button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Always dry-run first. It reports exactly what would change without writing anything.', 'adct-ddpro' ); ?>
				</p>
			</form>

			<?php if ( is_array( $last_run ) && ! empty( $last_run['at'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: human time diff. */
						esc_html__( 'Last real sync: %s ago.', 'adct-ddpro' ),
						esc_html( human_time_diff( (int) $last_run['at'], time() ) )
					);
					?>
					<?php
					$next = wp_next_scheduled( ADCT_DDPRO_CRON_SYNC );
					if ( $next ) {
						printf(
							/* translators: %s: human time diff. */
							' ' . esc_html__( 'Next scheduled run in %s.', 'adct-ddpro' ),
							esc_html( human_time_diff( time(), (int) $next ) )
						);
					}
					?>
				</p>
			<?php endif; ?>

			<hr>

			<?php $this->render_matches_panel( $msg ); ?>

			<hr>

			<?php $this->render_cleanup_panel( $msg ); ?>

			<hr>

			<h2><?php esc_html_e( 'Settings', 'adct-ddpro' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="adct_ddpro_save">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable scheduled sync', 'adct-ddpro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( (bool) $s['enabled'] ); ?>>
								<?php esc_html_e( 'Let WP-Cron run the sync every 3 minutes (and download photos every 5)', 'adct-ddpro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leave this off while testing. Manual sync and dry run work regardless.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="feed_url"><?php esc_html_e( 'Feed URL', 'adct-ddpro' ); ?></label></th>
						<td>
							<?php if ( $from_const ) : ?>
								<p><code><?php echo esc_html( $this->mask( ADCT_DDPro_Settings::feed_url() ) ); ?></code></p>
								<p class="description">
									<?php esc_html_e( 'Set via the ADCT_DDPRO_FEED_URL constant in wp-config.php. This is the recommended setup — the token stays out of the database.', 'adct-ddpro' ); ?>
								</p>
							<?php else : ?>
								<input type="url" id="feed_url" name="feed_url" class="large-text code"
									value="<?php echo esc_attr( (string) $s['feed_url'] ); ?>"
									placeholder="https://services.deal-drive.com/feeds/ddexport/....json">
								<p class="description">
									<?php esc_html_e( 'This URL grants read access to your full stock list including VINs. Prefer defining ADCT_DDPRO_FEED_URL in wp-config.php instead of storing it here.', 'adct-ddpro' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="new_post_status"><?php esc_html_e( 'Status for new cars', 'adct-ddpro' ); ?></label></th>
						<td>
							<select id="new_post_status" name="new_post_status">
								<?php foreach ( array( 'draft' => __( 'Draft (recommended)', 'adct-ddpro' ), 'pending' => __( 'Pending review', 'adct-ddpro' ), 'publish' => __( 'Publish immediately', 'adct-ddpro' ) ) as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $s['new_post_status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Publishing immediately puts a car live with a generated title and no meta description. Keep this on Draft.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Gate publishing until complete', 'adct-ddpro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="gate_publish" value="1" <?php checked( (bool) $s['gate_publish'] ); ?>>
								<?php esc_html_e( 'Only auto-publish once a car has its photos, price and specs', 'adct-ddpro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Applies only when "Status for new cars" is Publish immediately. With this on, each car is created as a draft and goes live by itself the moment its gallery has downloaded and it has a price plus make, year and mileage — so cars appear one at a time, fully formed, never with empty image boxes. Turn it off to publish instantly (images fill in afterwards).', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="sold_policy"><?php esc_html_e( 'When a car leaves the feed', 'adct-ddpro' ); ?></label></th>
						<td>
							<select id="sold_policy" name="sold_policy">
								<option value="outofstock" <?php selected( (string) $s['sold_policy'], 'outofstock' ); ?>><?php esc_html_e( 'Mark out of stock, keep the page live (recommended)', 'adct-ddpro' ); ?></option>
								<option value="draft" <?php selected( (string) $s['sold_policy'], 'draft' ); ?>><?php esc_html_e( 'Move to draft', 'adct-ddpro' ); ?></option>
								<option value="ignore" <?php selected( (string) $s['sold_policy'], 'ignore' ); ?>><?php esc_html_e( 'Do nothing', 'adct-ddpro' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Keeping the page live preserves its rankings and inbound links. Moving to draft turns a ranking page into a 404.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Photos', 'adct-ddpro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="attach_photos" value="1" <?php checked( (bool) $s['attach_photos'] ); ?>>
								<?php esc_html_e( 'Download feed photos into the media library', 'adct-ddpro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Roughly 25 photos per car at ~190 KB each. Downloaded in batches every 5 minutes.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="cars_per_run"><?php esc_html_e( 'Cars per sync run', 'adct-ddpro' ); ?></label></th>
						<td>
							<input type="number" id="cars_per_run" name="cars_per_run" min="1" max="200" value="<?php echo esc_attr( (string) $s['cars_per_run'] ); ?>" class="small-text">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="photos_per_run"><?php esc_html_e( 'Photos per batch', 'adct-ddpro' ); ?></label></th>
						<td>
							<input type="number" id="photos_per_run" name="photos_per_run" min="1" max="200" value="<?php echo esc_attr( (string) $s['photos_per_run'] ); ?>" class="small-text">
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Sold / available tags', 'adct-ddpro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="manage_tags" value="1" <?php checked( (bool) $s['manage_tags'] ); ?>>
								<?php esc_html_e( 'Let the feed manage product tags', 'adct-ddpro' ); ?>
							</label>
							<p>
								<label><?php esc_html_e( 'Sold tag:', 'adct-ddpro' ); ?>
									<input type="text" name="sold_tag" value="<?php echo esc_attr( (string) $s['sold_tag'] ); ?>" class="regular-text">
								</label>
							</p>
							<p>
								<label><?php esc_html_e( 'Available tag:', 'adct-ddpro' ); ?>
									<input type="text" name="available_tag" value="<?php echo esc_attr( (string) $s['available_tag'] ); ?>" class="regular-text">
								</label>
							</p>
							<p class="description">
								<?php esc_html_e( 'When a car leaves the feed or is marked sold, its existing tags are saved and replaced by the sold tag ALONE. If it comes back, the saved tags are restored. Names must match your existing terms exactly.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="adopt_fill"><?php esc_html_e( 'When adopting an existing car by VIN', 'adct-ddpro' ); ?></label></th>
						<td>
							<select id="adopt_fill" name="adopt_fill">
								<option value="empty" <?php selected( (string) $s['adopt_fill'], 'empty' ); ?>><?php esc_html_e( 'Fill only empty spec fields (recommended)', 'adct-ddpro' ); ?></option>
								<option value="overwrite" <?php selected( (string) $s['adopt_fill'], 'overwrite' ); ?>><?php esc_html_e( 'Overwrite specs — DD Pro wins', 'adct-ddpro' ); ?></option>
								<option value="none" <?php selected( (string) $s['adopt_fill'], 'none' ); ?>><?php esc_html_e( 'Do not touch specs', 'adct-ddpro' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Applies once, on the run that links a car to DD Pro. "Overwrite" also replaces the showroom address wording with DD Pro\'s version. Titles, descriptions and SEO fields are never touched in any mode.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Condition wording', 'adct-ddpro' ); ?></th>
						<td>
							<p>
								<label><?php esc_html_e( 'Used cars:', 'adct-ddpro' ); ?>
									<input type="text" name="condition_used" value="<?php echo esc_attr( (string) $s['condition_used'] ); ?>" class="regular-text">
								</label>
							</p>
							<p>
								<label><?php esc_html_e( 'New cars:', 'adct-ddpro' ); ?>
									<input type="text" name="condition_new" value="<?php echo esc_attr( (string) $s['condition_new'] ); ?>" class="regular-text">
								</label>
							</p>
							<p class="description">
								<?php esc_html_e( 'Must match the existing catalogue exactly. The live site uses "Pre-Owned" on 188 cars and "Pre - Owned" on 152 — pick one and standardise.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="default_location"><?php esc_html_e( 'Fallback location', 'adct-ddpro' ); ?></label></th>
						<td>
							<input type="text" id="default_location" name="default_location" value="<?php echo esc_attr( (string) $s['default_location'] ); ?>" class="regular-text">
						</td>
					</tr>

					<tr>
						<th scope="row" colspan="2" style="padding-bottom:0;">
							<h2 style="margin:0;"><?php esc_html_e( 'Fields filled on every imported car', 'adct-ddpro' ); ?></h2>
							<p class="description" style="font-weight:normal;">
								<?php esc_html_e( 'These make a feed-imported car match a hand-made one. Written at creation only; you can edit any of them per car afterwards.', 'adct-ddpro' ); ?>
							</p>
						</th>
					</tr>

					<tr>
						<th scope="row"><label for="default_category"><?php esc_html_e( 'Product category', 'adct-ddpro' ); ?></label></th>
						<td>
							<input type="text" id="default_category" name="default_category" value="<?php echo esc_attr( (string) ( $s['default_category'] ?? '' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Must match an existing product category name exactly (e.g. "Luxury Cars"). Leave blank to assign none.', 'adct-ddpro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Sales agents', 'adct-ddpro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="assign_agents" value="1" <?php checked( (bool) ( $s['assign_agents'] ?? false ) ); ?>>
								<?php esc_html_e( 'Assign all published sales agents (Faisal first) to each imported car', 'adct-ddpro' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Review fuzzy matches', 'adct-ddpro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="review_fuzzy" value="1" <?php checked( (bool) ( $s['review_fuzzy'] ?? false ) ); ?>>
								<?php esc_html_e( 'Hold a car for review when it has no VIN link but looks like an existing product', 'adct-ddpro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Prevents duplicating a page you already rank for. Held cars appear under WooCommerce → DD Pro Review, where you link them (adopting, SEO intact) or confirm they are new. A VIN match is never held — it is adopted automatically.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Keep title in sync', 'adct-ddpro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sync_title" value="1" <?php checked( (bool) ( $s['sync_title'] ?? false ) ); ?>>
								<?php esc_html_e( 'Update the product TITLE from DD Pro\'s headline on every change (new and existing cars)', 'adct-ddpro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The title comes from the first line of the DD Pro listing description. The page URL (slug) is never changed. Turn this OFF if you hand-optimise titles for SEO and do not want them reverted to the feed wording.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="title_max_length"><?php esc_html_e( 'Use DD Pro title as-is up to', 'adct-ddpro' ); ?></label></th>
						<td>
							<input type="number" id="title_max_length" name="title_max_length" min="0" max="300" value="<?php echo esc_attr( (string) ( $s['title_max_length'] ?? 100 ) ); ?>" class="small-text"> <?php esc_html_e( 'characters', 'adct-ddpro' ); ?>
							<p class="description">
								<?php esc_html_e( 'If DD Pro\'s first-line headline is this length or shorter, it is used as the title exactly as written. Longer headlines are reduced to the template (identity | Specs | Warranty | Service | engine, or import-spec fallbacks). Set 0 to always reduce to the template.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Keep short description in sync', 'adct-ddpro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sync_short_desc" value="1" <?php checked( (bool) ( $s['sync_short_desc'] ?? false ) ); ?>>
								<?php esc_html_e( 'Update the SHORT description from DD Pro on every change (new and existing cars)', 'adct-ddpro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The long description (your hand-written SEO copy) and all Yoast SEO fields are never touched.', 'adct-ddpro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="default_video_url"><?php esc_html_e( 'Official video URL', 'adct-ddpro' ); ?></label></th>
						<td>
							<input type="url" id="default_video_url" name="default_video_url" value="<?php echo esc_attr( (string) ( $s['default_video_url'] ?? '' ) ); ?>" class="large-text code">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="contact_link"><?php esc_html_e( 'Contact link URL', 'adct-ddpro' ); ?></label></th>
						<td>
							<input type="url" id="contact_link" name="contact_link" value="<?php echo esc_attr( (string) ( $s['contact_link'] ?? '' ) ); ?>" class="large-text code">
							<p class="description"><?php esc_html_e( 'Fills the car contact "link url" field (e.g. the Google Maps profile).', 'adct-ddpro' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Change history', 'adct-ddpro' ); ?></h2>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$view    = ( isset( $_GET['adct_view'] ) && 'all' === sanitize_key( (string) $_GET['adct_view'] ) ) ? 'all' : 'changes';
			$rows    = 'all' === $view ? ADCT_DDPro_Logger::recent( 200 ) : ADCT_DDPro_Logger::recent( 400, 'change' );
			$changes = ADCT_DDPro_Logger::change_count();
			$base    = admin_url( 'admin.php?page=' . ADCT_DDPRO_SLUG );
			?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of recorded changes. */
					esc_html__( '%s changes recorded. This is a permanent audit trail — every edit the feed makes is logged with its old and new value, and the log cannot be cleared.', 'adct-ddpro' ),
					'<strong>' . esc_html( number_format_i18n( $changes ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'adct_view', 'changes', $base ) ); ?>" class="button <?php echo 'changes' === $view ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Changes only', 'adct-ddpro' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'adct_view', 'all', $base ) ); ?>" class="button <?php echo 'all' === $view ? 'button-primary' : ''; ?>"><?php esc_html_e( 'All activity', 'adct-ddpro' ); ?></a>
			</p>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'No changes recorded yet.', 'adct-ddpro' ); ?></p>
			<?php else : ?>
				<div style="max-height:520px;overflow:auto;border:1px solid #dcdcde;border-radius:4px;">
				<table class="widefat striped" style="border:0;">
					<thead>
						<tr>
							<th style="width:145px;"><?php esc_html_e( 'When', 'adct-ddpro' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Product', 'adct-ddpro' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'DD Pro ID', 'adct-ddpro' ); ?></th>
							<th><?php esc_html_e( 'What changed', 'adct-ddpro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $rows as $r ) :
						$colors  = array( 'error' => '#b32d2e', 'warn' => '#996800', 'change' => '#1f7a1f', 'info' => '#787c82' );
						$c       = $colors[ $r->level ] ?? '#787c82';
						// Make field changes read clearly: "old" -> "new" becomes "old → new".
						$message = str_replace( ' -> ', ' → ', (string) $r->message );
						?>
						<tr>
							<td style="white-space:nowrap;"><?php echo esc_html( (string) $r->logged_at ); ?></td>
							<td>
								<?php if ( (int) $r->post_id > 0 ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( (int) $r->post_id ) ?? '' ); ?>">#<?php echo (int) $r->post_id; ?></a>
								<?php else : ?>
									<span style="color:#a7aaad;">—</span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( (string) $r->dd_id ); ?></code></td>
							<td style="border-left:3px solid <?php echo esc_attr( $c ); ?>;padding-left:8px;"><?php echo esc_html( $message ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The one-time "apply verified matches" panel: preview and apply buttons plus
	 * the last run's report.
	 *
	 * @param string $msg Current admin message key.
	 */
	private function render_matches_panel( string $msg ): void {
		$available = count( $this->verified_matches() );
		$report    = get_transient( 'adct_ddpro_matches_report' );
		?>
		<h2><?php esc_html_e( 'One-time: apply verified DD Pro matches', 'adct-ddpro' ); ?></h2>
		<div class="notice notice-info inline">
			<p>
				<?php
				printf(
					/* translators: %d: number of verified matches bundled. */
					esc_html__( 'This applies %d cross-checked DD Pro ID + GTIN links (built and verified on the staging mirror) to your EXISTING products, matched by product ID. It only ever fills the DD Pro ID and GTIN fields, never creates a product, and never touches status, price, content or SEO. Products that already carry a different DD Pro ID are skipped and reported. Preview first — it writes nothing.', 'adct-ddpro' ),
					(int) $available
				);
				?>
			</p>
		</div>

		<?php if ( $available > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="adct_ddpro_apply_matches">
				<p>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Preview matches (writes nothing)', 'adct-ddpro' ); ?>
					</button>
					<button type="submit" name="apply" value="1" class="button button-primary"
						onclick="return confirm('<?php echo esc_js( __( 'Apply the verified DD Pro ID + GTIN to your existing products now? Only these two fields are written.', 'adct-ddpro' ) ); ?>');">
						<?php esc_html_e( 'Apply matches', 'adct-ddpro' ); ?>
					</button>
				</p>
			</form>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No bundled matches found (includes/verified-matches.json is missing).', 'adct-ddpro' ); ?></em></p>
		<?php endif; ?>

		<?php
		if ( is_array( $report ) && in_array( $msg, array( 'matches_preview', 'matches_applied' ), true ) ) :
			$applied = ! empty( $report['apply'] );
			?>
			<div class="notice <?php echo $report['conflict'] > 0 ? 'notice-warning' : 'notice-success'; ?>">
				<p><strong>
					<?php
					printf(
						/* translators: 1: applied/preview verb, 2: to-set, 3: already, 4: conflict, 5: missing, 6: total. */
						esc_html__( '%1$s: %2$d to set, %3$d already correct, %4$d conflicts (skipped), %5$d missing on this site, of %6$d verified matches.', 'adct-ddpro' ),
						$applied ? esc_html__( 'Applied', 'adct-ddpro' ) : esc_html__( 'Preview', 'adct-ddpro' ),
						(int) $report['to_set'],
						(int) $report['already'],
						(int) $report['conflict'],
						(int) $report['missing'],
						(int) $report['total']
					);
					?>
				</strong></p>
				<?php if ( ! $applied && $report['to_set'] > 0 ) : ?>
					<p><em><?php esc_html_e( 'Nothing has been written yet. Review the list, then click "Apply matches".', 'adct-ddpro' ); ?></em></p>
				<?php endif; ?>
			</div>

			<?php
			$show = array_filter(
				(array) $report['rows'],
				static function ( $r ) {
					return in_array( ( $r['state'] ?? '' ), array( 'set', 'conflict', 'missing' ), true );
				}
			);
			?>
			<?php if ( $show ) : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th style="width:70px;"><?php esc_html_e( 'Product', 'adct-ddpro' ); ?></th>
							<th><?php esc_html_e( 'Title', 'adct-ddpro' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'DD Pro ID', 'adct-ddpro' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'GTIN', 'adct-ddpro' ); ?></th>
							<th style="width:160px;"><?php esc_html_e( 'Status', 'adct-ddpro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $show as $r ) :
						$state = (string) ( $r['state'] ?? '' );
						$label = 'set' === $state
							? ( $applied ? esc_html__( 'set', 'adct-ddpro' ) : esc_html__( 'will set', 'adct-ddpro' ) )
							: ( 'conflict' === $state
								? esc_html__( 'skipped — different ID: ', 'adct-ddpro' ) . esc_html( (string) ( $r['cur'] ?? '' ) )
								: esc_html__( 'not on this site — skipped', 'adct-ddpro' ) );
						$color = 'set' === $state ? '#1f7a1f' : ( 'conflict' === $state ? '#996800' : '#b32d2e' );
						?>
						<tr>
							<td>
								<?php if ( 'missing' !== $state ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( (int) $r['id'] ) ?? '' ); ?>">#<?php echo (int) $r['id']; ?></a>
								<?php else : ?>
									#<?php echo (int) $r['id']; ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( $r['title'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $r['dd_id'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $r['gtin'] ?? '' ) ); ?></td>
							<td style="color:<?php echo esc_attr( $color ); ?>;"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * The one-time photo-cleanup panel: preview and apply, plus the last report.
	 *
	 * @param string $msg Current admin message key.
	 */
	private function render_cleanup_panel( string $msg ): void {
		$report = get_transient( 'adct_ddpro_cleanup_report' );
		?>
		<h2><?php esc_html_e( 'One-time: remove feed photos from your existing cars', 'adct-ddpro' ); ?></h2>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'Undo feed photos that were imported onto cars which already had their own gallery (the pre-existing cars linked to DD Pro). It removes ONLY images this plugin downloaded from the feed — never your original photography, and never the featured/main image — and clears any pending photo downloads. Cars that DD Pro itself created are left untouched. Preview first — it writes nothing.', 'adct-ddpro' ); ?>
			</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="adct_ddpro_cleanup_photos">
			<p>
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Preview cleanup (writes nothing)', 'adct-ddpro' ); ?>
				</button>
				<button type="submit" name="apply" value="1" class="button button-primary"
					onclick="return confirm('<?php echo esc_js( __( 'Remove the feed-imported photos from your existing cars and clear pending downloads? Your original photos and featured images are kept.', 'adct-ddpro' ) ); ?>');">
					<?php esc_html_e( 'Remove feed photos', 'adct-ddpro' ); ?>
				</button>
			</p>
		</form>

		<?php
		if ( is_array( $report ) && in_array( $msg, array( 'cleanup_preview', 'cleanup_applied' ), true ) ) :
			$applied = ! empty( $report['apply'] );
			?>
			<div class="notice notice-success">
				<p><strong>
					<?php
					printf(
						/* translators: 1: verb, 2: photos, 3: queued, 4: products. */
						esc_html__( '%1$s: %2$d feed photo(s) to remove and %3$d pending download(s) to clear, across %4$d product(s).', 'adct-ddpro' ),
						$applied ? esc_html__( 'Done', 'adct-ddpro' ) : esc_html__( 'Preview', 'adct-ddpro' ),
						(int) $report['photos_removed'],
						(int) $report['queued_cleared'],
						(int) $report['products']
					);
					?>
				</strong></p>
				<?php if ( ! $applied && $report['products'] > 0 ) : ?>
					<p><em><?php esc_html_e( 'Nothing has been written yet. Click "Remove feed photos" to apply.', 'adct-ddpro' ); ?></em></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $report['rows'] ) ) : ?>
				<table class="widefat striped" style="max-width:820px;">
					<thead>
						<tr>
							<th style="width:70px;"><?php esc_html_e( 'Product', 'adct-ddpro' ); ?></th>
							<th><?php esc_html_e( 'Title', 'adct-ddpro' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Feed photos to remove', 'adct-ddpro' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Queued to clear', 'adct-ddpro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( (array) $report['rows'] as $r ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( (int) $r['id'] ) ?? '' ); ?>">#<?php echo (int) $r['id']; ?></a></td>
							<td><?php echo esc_html( (string) ( $r['title'] ?? '' ) ); ?></td>
							<td><?php echo (int) $r['remove']; ?></td>
							<td><?php echo (int) $r['queued']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Hide most of the feed token when displaying the URL.
	 */
	private function mask( string $url ): string {
		if ( strlen( $url ) < 40 ) {
			return $url;
		}
		return substr( $url, 0, 48 ) . '…' . substr( $url, -12 );
	}
}
