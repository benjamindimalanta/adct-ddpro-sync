<?php
/**
 * WP-CLI commands: wp ddpro <subcommand>
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class ADCT_DDPro_CLI {

	/**
	 * Fetch the feed, or fail loudly.
	 *
	 * @return array{listings:array<int,array<string,mixed>>,locations:array<int,array<string,mixed>>}
	 */
	private function feed(): array {
		$feed = ( new ADCT_DDPro_Feed_Client() )->fetch();

		if ( is_wp_error( $feed ) ) {
			WP_CLI::error( $feed->get_error_message() );
		}

		return $feed;
	}

	/**
	 * Run a sync.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro sync --dry-run
	 *     wp ddpro sync
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$dry     = isset( $assoc_args['dry-run'] );
		$summary = ( new ADCT_DDPro_Importer() )->run( $dry );

		WP_CLI::line( $summary['message'] );

		if ( $summary['errors'] > 0 ) {
			WP_CLI::warning( 'Completed with errors — check the log.' );
		} else {
			WP_CLI::success( $dry ? 'Dry run complete. Nothing was written.' : 'Sync complete.' );
		}
	}

	/**
	 * Download queued photos.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro photos
	 */
	public function photos(): void {
		$r = ( new ADCT_DDPro_Importer() )->drain_photo_queue();
		WP_CLI::success( sprintf( 'Imported %d image(s) across %d product(s).', $r['done'], $r['products'] ) );
	}

	/**
	 * Inspect the raw feed: what it contains and whether it looks channel-filtered.
	 *
	 * Use this to answer "does this feed only contain cars ticked for WordPress?".
	 * Run it, then tick a car for Dubizzle ONLY in DD Pro, wait for the feed to
	 * refresh, and run it again. If the car appears here, the feed is NOT
	 * filtered by channel and every Dubizzle listing will reach the website.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro feed-check
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function feed_check( array $args, array $assoc_args ): void {
		$feed   = $this->feed();
		$mapper = new ADCT_DDPro_Mapper( $feed['locations'] );

		$rows          = array();
		$channel_keys  = array();

		foreach ( $feed['listings'] as $l ) {
			$rows[] = array(
				'dd_id'  => (string) ( $l['dd_id'] ?? '' ),
				'car'    => trim( sprintf( '%s %s %s', $l['year'] ?? '', $l['brand']['name'] ?? '', $mapper->model_name( $l ) ) ),
				'status' => (string) ( $l['status']['code'] ?? '' ),
				'price'  => (string) ( $l['price'] ?? '' ),
				'vin'    => (string) ( $l['vin'] ?? '' ),
				'photos' => (string) count( $mapper->photo_urls( $l ) ),
			);

			// Look for anything that could express a publishing channel.
			foreach ( array_keys( $l ) as $k ) {
				if ( preg_match( '/channel|publish|export|portal|dubizzle|website|site|target|visib/i', (string) $k ) ) {
					$channel_keys[ (string) $k ] = true;
				}
			}
		}

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'dd_id', 'car', 'status', 'price', 'vin', 'photos' ) );

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Listings in feed : %d', count( $feed['listings'] ) ) );

		$statuses = array_unique( array_column( $rows, 'status' ) );
		WP_CLI::line( 'Status codes seen: ' . implode( ', ', $statuses ) );

		WP_CLI::line( '' );
		if ( $channel_keys ) {
			WP_CLI::warning( 'Possible channel/publishing fields present: ' . implode( ', ', array_keys( $channel_keys ) ) );
		} else {
			WP_CLI::line( 'No channel / publish-target field exists anywhere in the payload.' );
			WP_CLI::line( 'The feed therefore cannot tell you which cars were ticked for WordPress' );
			WP_CLI::line( 'versus Dubizzle. If filtering happens, it happens on DD Pro\'s server.' );
			WP_CLI::line( '' );
			WP_CLI::line( 'TO TEST: tick one car for Dubizzle ONLY in DD Pro, then re-run this' );
			WP_CLI::line( 'command. If that car shows up above, the feed is not channel-filtered.' );
		}
	}

	/**
	 * Write VINs onto existing products, so VIN becomes the shared key with DD Pro.
	 *
	 * Expects a CSV with a header row containing a product identifier column
	 * (`post_id`, `wp_id`, or `sku`/`stock_ref`) and a `vin` column. Column order
	 * does not matter. Rows whose VIN is not 17 characters are rejected.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to the CSV.
	 *
	 * [--dry-run]
	 * : Validate and report without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro backfill-vin --file=vins.csv --dry-run
	 *     wp ddpro backfill-vin --file=vins.csv
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function backfill_vin( array $args, array $assoc_args ): void {
		$path = (string) ( $assoc_args['file'] ?? '' );
		$dry  = isset( $assoc_args['dry-run'] );

		if ( '' === $path || ! is_readable( $path ) ) {
			WP_CLI::error( 'Cannot read --file. Give a path to a readable CSV.' );
		}

		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			WP_CLI::error( 'Could not open the file.' );
		}

		$header = fgetcsv( $fh );
		if ( ! is_array( $header ) ) {
			fclose( $fh );
			WP_CLI::error( 'The file has no header row.' );
		}

		$map = array();
		foreach ( $header as $i => $h ) {
			$map[ strtolower( trim( (string) $h ) ) ] = $i;
		}

		$id_col = null;
		foreach ( array( 'post_id', 'wp_id', 'id' ) as $c ) {
			if ( isset( $map[ $c ] ) ) {
				$id_col = $map[ $c ];
				break;
			}
		}
		$sku_col = $map['sku'] ?? $map['stock_ref'] ?? null;
		$vin_col = $map['vin'] ?? null;

		if ( null === $vin_col ) {
			fclose( $fh );
			WP_CLI::error( 'No "vin" column found in the header.' );
		}
		if ( null === $id_col && null === $sku_col ) {
			fclose( $fh );
			WP_CLI::error( 'Need a "post_id" (or "wp_id") column, or a "sku"/"stock_ref" column, to identify each product.' );
		}

		$written = 0;
		$skipped = 0;
		$bad     = 0;
		$line    = 1;

		while ( false !== ( $row = fgetcsv( $fh ) ) ) {
			++$line;
			if ( ! is_array( $row ) || array() === array_filter( $row, static fn( $v ): bool => '' !== trim( (string) $v ) ) ) {
				continue;
			}

			$vin = strtoupper( trim( (string) ( $row[ $vin_col ] ?? '' ) ) );

			if ( 17 !== strlen( $vin ) ) {
				++$bad;
				WP_CLI::line( sprintf( '  line %d: REJECTED — VIN "%s" is %d chars, expected 17', $line, $vin, strlen( $vin ) ) );
				continue;
			}
			if ( preg_match( '/[IOQ]/', $vin ) ) {
				++$bad;
				WP_CLI::line( sprintf( '  line %d: REJECTED — VIN "%s" contains I, O or Q (not valid in a VIN)', $line, $vin ) );
				continue;
			}

			// Resolve the product.
			$pid = 0;
			if ( null !== $id_col ) {
				$pid = (int) ( $row[ $id_col ] ?? 0 );
			}
			if ( $pid <= 0 && null !== $sku_col ) {
				$sku = trim( (string) ( $row[ $sku_col ] ?? '' ) );
				if ( '' !== $sku ) {
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$pid = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT pm.post_id FROM {$wpdb->postmeta} pm
							 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
							 WHERE pm.meta_key='_sku' AND pm.meta_value=%s AND p.post_type='product' LIMIT 1",
							$sku
						)
					);
				}
			}

			if ( $pid <= 0 || 'product' !== get_post_type( $pid ) ) {
				++$bad;
				WP_CLI::line( sprintf( '  line %d: REJECTED — could not resolve a product', $line ) );
				continue;
			}

			$current = strtoupper( trim( (string) get_post_meta( $pid, '_sku', true ) ) );

			if ( $current === $vin ) {
				++$skipped;
				continue;
			}

			// Refuse to give two products the same VIN.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$clash = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key='_sku' AND UPPER(pm.meta_value)=%s AND pm.post_id<>%d
					   AND p.post_type='product' LIMIT 1",
					$vin,
					$pid
				)
			);
			if ( $clash > 0 ) {
				++$bad;
				WP_CLI::line( sprintf( '  line %d: REJECTED — VIN %s already on product #%d', $line, $vin, $clash ) );
				continue;
			}

			WP_CLI::line(
				sprintf(
					'  %s #%d  %s -> %s   %s',
					$dry ? 'WOULD SET' : 'SET',
					$pid,
					'' === $current ? '(no sku)' : $current,
					$vin,
					mb_substr( (string) get_the_title( $pid ), 0, 40 )
				)
			);

			if ( ! $dry ) {
				update_post_meta( $pid, '_sku', $vin );
			}
			++$written;
		}

		fclose( $fh );

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '%s: %d to write, %d already correct, %d rejected.', $dry ? 'Dry run' : 'Done', $written, $skipped, $bad ) );

		if ( $dry ) {
			WP_CLI::line( 'Re-run without --dry-run to apply.' );
		} else {
			WP_CLI::success( 'VINs written. Now run `wp ddpro sync --dry-run` — matching cars will be adopted, not duplicated.' );
		}
	}

	/**
	 * Record the current feed state as a baseline for comparison.
	 *
	 * Run this BEFORE changing anything in DD Pro, then run `wp ddpro diff`
	 * afterwards to see exactly what DD Pro changed and what WordPress will do
	 * about it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro snapshot
	 */
	public function snapshot(): void {
		$feed  = $this->feed();
		$state = array();

		foreach ( $feed['listings'] as $l ) {
			$dd = (string) ( $l['dd_id'] ?? '' );
			if ( '' === $dd ) {
				continue;
			}
			$state[ $dd ] = array(
				'status'  => (string) ( $l['status']['code'] ?? '' ),
				'name'    => (string) ( $l['status']['name'] ?? '' ),
				'price'   => (string) ( $l['price'] ?? '' ),
				'mileage' => (string) ( $l['mileage_km'] ?? '' ),
				'label'   => trim( sprintf( '%s %s', $l['year'] ?? '', $l['brand']['name'] ?? '' ) ),
			);
		}

		update_option( 'adct_ddpro_snapshot', array( 'at' => time(), 'state' => $state ), false );

		WP_CLI::success( sprintf( 'Baseline saved: %d listing(s).', count( $state ) ) );
		foreach ( $state as $dd => $s ) {
			WP_CLI::line( sprintf( '  %-16s %-22s status=%-8s price=%s', $dd, $s['label'], $s['status'], $s['price'] ) );
		}
		WP_CLI::line( '' );
		WP_CLI::line( 'Now make your change in DD Pro, then run:  wp ddpro diff' );
	}

	/**
	 * Compare the live feed against the saved baseline.
	 *
	 * Reports what DD Pro changed and what a sync would do to each product —
	 * without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro diff
	 */
	public function diff(): void {
		$saved = get_option( 'adct_ddpro_snapshot' );

		if ( ! is_array( $saved ) || empty( $saved['state'] ) ) {
			WP_CLI::error( 'No baseline saved. Run `wp ddpro snapshot` first.' );
		}

		$before = (array) $saved['state'];
		$feed   = $this->feed();
		$mapper = new ADCT_DDPro_Mapper( $feed['locations'] );

		$after = array();
		foreach ( $feed['listings'] as $l ) {
			$dd = (string) ( $l['dd_id'] ?? '' );
			if ( '' !== $dd ) {
				$after[ $dd ] = $l;
			}
		}

		WP_CLI::line( sprintf( 'Baseline taken %s ago: %d listing(s).', human_time_diff( (int) $saved['at'], time() ), count( $before ) ) );
		WP_CLI::line( sprintf( 'Feed right now              : %d listing(s).', count( $after ) ) );
		WP_CLI::line( '' );

		$changes = 0;

		// Gone from the feed.
		foreach ( $before as $dd => $b ) {
			if ( isset( $after[ $dd ] ) ) {
				continue;
			}
			++$changes;
			$pid = $this->product_for( $dd );
			WP_CLI::line( sprintf( 'REMOVED FROM FEED  %s  %s', $dd, $b['label'] ) );
			WP_CLI::line( sprintf( '   was: status=%s price=%s', $b['status'], $b['price'] ) );
			WP_CLI::line( '   -> WordPress will: ' . $this->missing_action( $pid ) );
			WP_CLI::line( '' );
		}

		// New in the feed.
		foreach ( $after as $dd => $l ) {
			if ( isset( $before[ $dd ] ) ) {
				continue;
			}
			++$changes;
			WP_CLI::line( sprintf( 'NEW IN FEED        %s  %s', $dd, trim( sprintf( '%s %s', $l['year'] ?? '', $l['brand']['name'] ?? '' ) ) ) );
			WP_CLI::line( sprintf( '   status=%s price=%s', $l['status']['code'] ?? '?', $l['price'] ?? '?' ) );

			/*
			 * "New in the feed" does not mean new to WordPress. A car that was
			 * un-exported and then re-exported returns here, but we may still
			 * hold it by dd_id (link preserved) or by VIN. Report what will
			 * really happen rather than assuming a create.
			 */
			$pid = $this->product_for( $dd );

			if ( $pid <= 0 ) {
				$vin = strtoupper( trim( (string) ( $l['vin'] ?? '' ) ) );
				if ( 17 === strlen( $vin ) ) {
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$pid = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT pm.post_id FROM {$wpdb->postmeta} pm
							 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
							 WHERE pm.meta_key='_sku' AND UPPER(pm.meta_value)=%s
							   AND p.post_type='product'
							   AND p.post_status IN ('publish','draft','pending','private','future')
							 LIMIT 1",
							$vin
						)
					);
					if ( $pid > 0 ) {
						WP_CLI::line( sprintf( '   -> WordPress will: ADOPT existing product #%d by VIN (no duplicate)', $pid ) );
					}
				}
				if ( $pid <= 0 ) {
					WP_CLI::line( '   -> WordPress will: create a new DRAFT product' );
				}
			} else {
				$stock = (string) get_post_meta( $pid, '_stock_status', true );
				if ( 'outofstock' === $stock && $mapper->is_available( $l ) ) {
					WP_CLI::line( sprintf( '   -> WordPress will: put existing #%d BACK IN STOCK (still linked, no duplicate)', $pid ) );
				} else {
					WP_CLI::line( sprintf( '   -> WordPress will: update existing #%d (still linked, no duplicate)', $pid ) );
				}
			}
			WP_CLI::line( '' );
		}

		// Changed in place.
		foreach ( $after as $dd => $l ) {
			if ( ! isset( $before[ $dd ] ) ) {
				continue;
			}
			$b     = $before[ $dd ];
			$notes = array();

			$new_status = (string) ( $l['status']['code'] ?? '' );
			if ( $new_status !== $b['status'] ) {
				$notes[] = sprintf(
					'STATUS "%s" -> "%s" (%s)',
					$b['status'],
					$new_status,
					(string) ( $l['status']['name'] ?? '?' )
				);
			}
			if ( (string) ( $l['price'] ?? '' ) !== $b['price'] ) {
				$notes[] = sprintf( 'price %s -> %s', $b['price'], (string) ( $l['price'] ?? '' ) );
			}
			if ( (string) ( $l['mileage_km'] ?? '' ) !== $b['mileage'] ) {
				$notes[] = sprintf( 'mileage %s -> %s', $b['mileage'], (string) ( $l['mileage_km'] ?? '' ) );
			}

			if ( ! $notes ) {
				continue;
			}

			++$changes;
			$pid = $this->product_for( $dd );

			WP_CLI::line( sprintf( 'CHANGED            %s  %s', $dd, $b['label'] ) );
			foreach ( $notes as $n ) {
				WP_CLI::line( '   ' . $n );
			}

			$avail   = $mapper->is_available( $l );
			$unknown = $mapper->status_is_unknown( $l );

			WP_CLI::line( sprintf( '   interpreted as: %s%s', $avail ? 'STILL FOR SALE' : 'NOT AVAILABLE', $unknown ? '  [code not recognised]' : '' ) );

			if ( $pid > 0 ) {
				$stock = (string) get_post_meta( $pid, '_stock_status', true );
				if ( ! $avail && 'outofstock' !== $stock ) {
					WP_CLI::line( sprintf( '   -> WordPress will: mark #%d OUT OF STOCK (page stays published)', $pid ) );
				} elseif ( $avail && 'outofstock' === $stock ) {
					WP_CLI::line( sprintf( '   -> WordPress will: put #%d BACK IN STOCK', $pid ) );
				} else {
					WP_CLI::line( sprintf( '   -> WordPress will: update #%d (availability unchanged: %s)', $pid, $stock ) );
				}
				if ( $unknown ) {
					WP_CLI::warning( sprintf( 'Status "%s" is not in UNAVAILABLE_CODES. If it means sold, add it to includes/class-mapper.php.', $new_status ) );
				}
			} else {
				WP_CLI::line( '   -> no linked product on this site yet' );
			}
			WP_CLI::line( '' );
		}

		if ( 0 === $changes ) {
			WP_CLI::success( 'No change since the baseline. DD Pro has not updated the feed yet — it may be cached; wait a minute and try again.' );
		} else {
			WP_CLI::line( sprintf( '%d change(s) detected. Nothing was written.', $changes ) );
			WP_CLI::line( 'Apply them with:  wp ddpro sync' );
		}
	}

	/**
	 * Product id linked to a dd_id, or 0.
	 */
	private function product_for( string $dd_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",
				ADCT_DDPRO_META_ID,
				$dd_id
			)
		);
	}

	/**
	 * Describe what the sold policy will do to a product that left the feed.
	 */
	private function missing_action( int $pid ): string {
		if ( $pid <= 0 ) {
			return 'nothing — no linked product on this site';
		}

		$policy = (string) ADCT_DDPro_Settings::get( 'sold_policy' );

		if ( 'ignore' === $policy ) {
			return sprintf( 'nothing (sold_policy = ignore) — #%d left as-is', $pid );
		}
		if ( 'draft' === $policy ) {
			return sprintf( 'move #%d to DRAFT (its URL will 404)', $pid );
		}
		return sprintf( 'mark #%d OUT OF STOCK (page stays published, keeps its rankings)', $pid );
	}

	/**
	 * Suggest links between feed listings and products that already exist.
	 *
	 * Nothing is written. Review the output, then run `wp ddpro link`.
	 *
	 * ## OPTIONS
	 *
	 * [--min-score=<n>]
	 * : Only show candidates at or above this score.
	 * ---
	 * default: 40
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro reconcile
	 *     wp ddpro reconcile --min-score=60 --format=csv > review.csv
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function reconcile( array $args, array $assoc_args ): void {
		$min    = (int) ( $assoc_args['min-score'] ?? 40 );
		$format = $assoc_args['format'] ?? 'table';

		$feed   = $this->feed();
		$mapper = new ADCT_DDPro_Mapper( $feed['locations'] );
		$recon  = new ADCT_DDPro_Reconciler();

		$rows      = array();
		$no_match  = array();

		foreach ( $feed['listings'] as $l ) {
			$dd_id = (string) ( $l['dd_id'] ?? '' );
			$label = trim( sprintf( '%s %s %s', $l['year'] ?? '', $l['brand']['name'] ?? '', $mapper->model_name( $l ) ) );

			$cands = array_filter(
				$recon->candidates( $l, $mapper ),
				static fn( array $c ): bool => $c['score'] >= $min
			);

			if ( ! $cands ) {
				$no_match[] = sprintf( '%s  %s', $dd_id, $label );
				continue;
			}

			foreach ( $cands as $c ) {
				$rows[] = array(
					'dd_id'      => $dd_id,
					'feed_car'   => $label,
					'feed_price' => (string) ( $mapper->price( $l ) ?? '' ),
					'post_id'    => (string) $c['post_id'],
					'existing'   => mb_substr( $c['title'], 0, 52 ),
					'ex_price'   => 0.0 === $c['price'] ? '' : (string) (int) $c['price'],
					'score'      => (string) $c['score'],
					'confidence' => ADCT_DDPro_Reconciler::band( (int) $c['score'] ),
					'why'        => $c['reasons'],
				);
			}
		}

		if ( $rows ) {
			WP_CLI\Utils\format_items(
				$format,
				$rows,
				array( 'dd_id', 'feed_car', 'feed_price', 'post_id', 'existing', 'ex_price', 'score', 'confidence', 'why' )
			);
		} else {
			WP_CLI::line( 'No candidate matches at or above score ' . $min . '.' );
		}

		if ( $no_match && 'table' === $format ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( 'Listings with no candidate (these are genuinely new cars — let the sync create them): %d', count( $no_match ) ) );
			foreach ( $no_match as $n ) {
				WP_CLI::line( '  ' . $n );
			}
		}

		if ( 'table' === $format ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Nothing has been changed. To link a confirmed pair:' );
			WP_CLI::line( '  wp ddpro link --dd-id=<dd_id> --post-id=<post_id>' );
			WP_CLI::line( '' );
			WP_CLI::line( 'Only link when you have opened the product and confirmed it is the same' );
			WP_CLI::line( 'physical car. A wrong link syncs the wrong price onto a ranking page.' );
		}
	}

	/**
	 * Link a feed listing to an existing product.
	 *
	 * ## OPTIONS
	 *
	 * --dd-id=<dd_id>
	 * : The DD Pro listing id.
	 *
	 * --post-id=<post_id>
	 * : The existing product ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro link --dd-id=161392-CHCZT --post-id=15234
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function link( array $args, array $assoc_args ): void {
		$dd_id   = (string) ( $assoc_args['dd-id'] ?? '' );
		$post_id = (int) ( $assoc_args['post-id'] ?? 0 );

		if ( '' === $dd_id || $post_id <= 0 ) {
			WP_CLI::error( 'Both --dd-id and --post-id are required.' );
		}

		$result = ( new ADCT_DDPro_Reconciler() )->link( $dd_id, $post_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'Linked #%d ("%s") to %s. The next sync will refresh its price, mileage and photos only.',
				$post_id,
				get_the_title( $post_id ),
				$dd_id
			)
		);
	}

	/**
	 * Remove a feed link from a product.
	 *
	 * ## OPTIONS
	 *
	 * --post-id=<post_id>
	 * : The product ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro unlink --post-id=15234
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function unlink( array $args, array $assoc_args ): void {
		$post_id = (int) ( $assoc_args['post-id'] ?? 0 );

		if ( $post_id <= 0 ) {
			WP_CLI::error( '--post-id is required.' );
		}

		( new ADCT_DDPro_Reconciler() )->unlink( $post_id );
		WP_CLI::success( sprintf( 'Unlinked #%d.', $post_id ) );
	}

	/**
	 * Show which products are currently managed by the feed.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ddpro status
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s ORDER BY post_id", ADCT_DDPRO_META_ID )
		);

		$rows = array();

		foreach ( $ids as $pid ) {
			$pid   = (int) $pid;
			$queue = get_post_meta( $pid, ADCT_DDPRO_META_QUEUE, true );
			$map   = get_post_meta( $pid, ADCT_DDPRO_META_PHOTOS, true );

			$rows[] = array(
				'post_id'      => (string) $pid,
				'dd_id'        => (string) get_post_meta( $pid, ADCT_DDPRO_META_ID, true ),
				'status'       => (string) get_post_status( $pid ),
				'stock'        => (string) get_post_meta( $pid, '_stock_status', true ),
				'price'        => (string) get_post_meta( $pid, '_regular_price', true ),
				'photos_done'  => (string) ( is_array( $map ) ? count( $map ) : 0 ),
				'photos_queued' => (string) ( is_array( $queue ) ? count( $queue ) : 0 ),
				'title'        => mb_substr( (string) get_the_title( $pid ), 0, 48 ),
			);
		}

		if ( ! $rows ) {
			WP_CLI::line( 'No products are linked to the DD Pro feed yet.' );
			return;
		}

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'post_id', 'dd_id', 'status', 'stock', 'price', 'photos_done', 'photos_queued', 'title' )
		);
	}
}

WP_CLI::add_command( 'ddpro', 'ADCT_DDPro_CLI' );

/*
 * WP-CLI derives subcommand names from method names verbatim, so `feed_check`
 * would only be reachable as `wp ddpro feed_check`. Register the hyphenated
 * form explicitly, which is what the docs and README tell people to type.
 */
WP_CLI::add_command(
	'ddpro feed-check',
	static function ( array $args, array $assoc_args ): void {
		( new ADCT_DDPro_CLI() )->feed_check( $args, $assoc_args );
	},
	array(
		'shortdesc' => 'Inspect the raw feed and test whether it is filtered by publishing channel.',
		'synopsis'  => array(
			array(
				'type'     => 'assoc',
				'name'     => 'format',
				'optional' => true,
				'default'  => 'table',
				'options'  => array( 'table', 'csv', 'json' ),
			),
		),
	)
);

WP_CLI::add_command(
	'ddpro backfill-vin',
	static function ( array $args, array $assoc_args ): void {
		( new ADCT_DDPro_CLI() )->backfill_vin( $args, $assoc_args );
	},
	array(
		'shortdesc' => 'Write VINs onto existing products from a CSV so VIN becomes the shared key with DD Pro.',
		'synopsis'  => array(
			array(
				'type'     => 'assoc',
				'name'     => 'file',
				'optional' => false,
			),
			array(
				'type'     => 'flag',
				'name'     => 'dry-run',
				'optional' => true,
			),
		),
	)
);

/*
 * `wp ddpro audit` — VIN-based consistency check between WordPress and DD Pro.
 * Registered separately so the hyphen-free method name does not collide with
 * WP-CLI's verbatim method-to-subcommand mapping.
 */
WP_CLI::add_command(
	'ddpro audit',
	static function ( array $args, array $assoc_args ): void {
		$feed = ( new ADCT_DDPro_Feed_Client() )->fetch();

		if ( is_wp_error( $feed ) ) {
			WP_CLI::error( $feed->get_error_message() );
		}

		$result = ( new ADCT_DDPro_Auditor() )->run( $feed );
		$rows   = $result['rows'];
		$format = $assoc_args['format'] ?? 'table';
		$only   = (string) ( $assoc_args['only'] ?? '' );

		if ( 'table' === $format ) {
			WP_CLI::line( sprintf( 'Feed listings: %d    Products checked: %d', count( $feed['listings'] ), $result['products'] ) );
			WP_CLI::line( '' );
			foreach ( ADCT_DDPro_Auditor::BUCKETS as $k => $desc ) {
				WP_CLI::line( sprintf( '  %-10s %5d   %s', $k, $result['counts'][ $k ], $desc ) );
			}
			WP_CLI::line( '' );
		}

		if ( '' !== $only ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $r ) use ( $only ): bool {
						return $r['bucket'] === $only;
					}
				)
			);
		}

		if ( ! $rows ) {
			WP_CLI::line( 'Nothing to list.' );
		} else {
			WP_CLI\Utils\format_items( $format, $rows, array( 'bucket', 'post_id', 'status', 'vin', 'dd_id', 'car', 'note' ) );
		}

		if ( 'table' === $format && $result['counts']['no-vin'] > 0 ) {
			WP_CLI::line( '' );
			WP_CLI::warning(
				sprintf(
					'%d product(s) have no VIN. They cannot be matched, and each one WILL be duplicated if DD Pro also holds that car. Fix with: wp ddpro backfill-vin --file=vins.csv',
					$result['counts']['no-vin']
				)
			);
		}
	},
	array(
		'shortdesc' => 'Audit every product against the DD Pro feed using VIN as the key. Changes nothing.',
		'synopsis'  => array(
			array(
				'type'     => 'assoc',
				'name'     => 'only',
				'optional' => true,
				'options'  => array( 'ok', 'drift', 'adoptable', 'gone', 'feed-only', 'no-vin' ),
			),
			array(
				'type'     => 'assoc',
				'name'     => 'format',
				'optional' => true,
				'default'  => 'table',
				'options'  => array( 'table', 'csv', 'json' ),
			),
		),
	)
);

/*
 * `wp ddpro seo-todo` — feed-imported cars that still need human SEO work.
 *
 * With auto-publish on, cars go live with a generated title and no Yoast
 * fields. This lists exactly which ones need attention, so the gap is a
 * worklist rather than something you discover from a traffic drop.
 */
WP_CLI::add_command(
	'ddpro seo-todo',
	static function ( array $args, array $assoc_args ): void {
		global $wpdb;

		$rows = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				 ORDER BY p.ID DESC",
				ADCT_DDPRO_META_ID
			)
		);

		foreach ( $ids as $id ) {
			$id       = (int) $id;
			$metadesc = trim( (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true ) );
			$focuskw  = trim( (string) get_post_meta( $id, '_yoast_wpseo_focuskw', true ) );
			$excerpt  = trim( (string) get_post( $id )->post_excerpt );
			$body     = trim( wp_strip_all_tags( (string) get_post( $id )->post_content ) );

			$missing = array();
			if ( '' === $metadesc ) {
				$missing[] = 'meta description';
			}
			if ( '' === $focuskw ) {
				$missing[] = 'focus keyword';
			}
			if ( '' === $excerpt ) {
				$missing[] = 'excerpt';
			}
			if ( mb_strlen( $body ) < 300 ) {
				$missing[] = sprintf( 'thin body (%d chars)', mb_strlen( $body ) );
			}

			if ( ! $missing ) {
				continue;
			}

			$rows[] = array(
				'post_id' => (string) $id,
				'car'     => mb_substr( (string) get_the_title( $id ), 0, 46 ),
				'missing' => implode( ', ', $missing ),
				'edit'    => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			);
		}

		if ( ! $rows ) {
			WP_CLI::success( 'Every feed-imported car has a meta description, focus keyword, excerpt and real body copy.' );
			return;
		}

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'post_id', 'car', 'missing', 'edit' )
		);

		WP_CLI::line( '' );
		WP_CLI::warning(
			sprintf(
				'%d published car(s) imported from DD Pro still need SEO work. Auto-publish is ON, so new cars will keep appearing here.',
				count( $rows )
			)
		);
	},
	array(
		'shortdesc' => 'List feed-imported published cars missing Yoast fields or body copy.',
		'synopsis'  => array(
			array(
				'type'     => 'assoc',
				'name'     => 'format',
				'optional' => true,
				'default'  => 'table',
				'options'  => array( 'table', 'csv', 'json' ),
			),
		),
	)
);
