<?php
/**
 * Import / sync engine.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Importer {

	/**
	 * The ONLY ACF fields the feed is allowed to overwrite on an existing
	 * product. Everything else is written once at creation and then belongs to
	 * whoever edits the product in WordPress.
	 *
	 * Specs are excluded on purpose: a 2021 Patrol does not become a 2022
	 * Patrol, so re-writing them on every run only risks clobbering a manual
	 * correction. Price and mileage genuinely change and are the reason to sync.
	 *
	 * @var array<int,string>
	 */
	public const SYNC_ON_UPDATE = array( 'mileage', 'warranty' );

	/**
	 * Post fields this plugin must never touch on an existing product.
	 * Enforced by never including them in wp_update_post() payloads.
	 *
	 * post_title and post_excerpt are NOT here: by owner choice the feed keeps the
	 * title and the short description in sync (see the sync_title / sync_short_desc
	 * settings and update_product). The LONG description (post_content) and the URL
	 * slug (post_name) stay untouchable — the long copy is hand-written SEO, and
	 * changing a live slug would break its URL and rankings.
	 *
	 * @var array<int,string>
	 */
	public const NEVER_UPDATE = array( 'post_content', 'post_name' );

	/** create_product() return value meaning "deliberately skipped as a duplicate". */
	public const SKIP_DUPLICATE = -1;

	private ADCT_DDPro_Feed_Client $client;

	/**
	 * Products adopted during this run, keyed by post ID.
	 *
	 * Adoption is the one moment the two systems are being reconciled, so the
	 * spec fields get a fuller write than on a routine update. After that the
	 * conservative SYNC_ON_UPDATE whitelist applies forever.
	 *
	 * @var array<int,bool>
	 */
	private array $just_adopted = array();

	/**
	 * dd_id => post_id, built once per run.
	 *
	 * @var array<string,int>|null
	 */
	private ?array $dd_index = null;

	/**
	 * VIN => list of post_ids, built once per run.
	 *
	 * @var array<string,array<int,int>>|null
	 */
	private ?array $vin_index = null;

	/**
	 * post_id => stored content hash, built once per run.
	 *
	 * @var array<int,string>
	 */
	private array $hash_index = array();

	/**
	 * All published sales-agent IDs (as strings, Faisal first), built once per run.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $agents_cache = null;

	public function __construct() {
		$this->client = new ADCT_DDPro_Feed_Client();
	}

	/**
	 * Run a sync.
	 *
	 * @param bool $dry_run When true, nothing is written; the planned actions are logged.
	 * @return array<string,mixed> Summary.
	 */
	public function run( bool $dry_run = false ): array {
		$summary = array(
			'ok'         => false,
			'dry_run'    => $dry_run,
			'listings'   => 0,
			'created'    => 0,
			'updated'    => 0,
			'unchanged'  => 0,
			'adopted'    => 0,
			'auto_matched' => 0,
			'duplicates' => 0,
			'held'       => 0,
			'sold'       => 0,
			'skipped'    => 0,
			'errors'     => 0,
			'photos_q'   => 0,
			'message'    => '',
		);

		$feed = $this->client->fetch();
		if ( is_wp_error( $feed ) ) {
			$summary['errors']  = 1;
			$summary['message'] = $feed->get_error_message();
			ADCT_DDPro_Logger::error( 'Feed fetch failed: ' . $feed->get_error_message() );
			return $summary;
		}

		/*
		 * Build the dd_id and VIN lookups once, in two queries, instead of
		 * querying per listing. Without this a 200-car feed cost ~800 queries
		 * and 85 seconds; the per-listing lookups dominated the whole run.
		 */
		$this->build_indexes();

		$listings   = $feed['listings'];
		$mapper     = new ADCT_DDPro_Mapper( $feed['locations'] );
		$reconciler = new ADCT_DDPro_Reconciler();
		$per_run    = (int) ADCT_DDPro_Settings::get( 'cars_per_run' );
		$seen_ids   = array();

		$summary['listings'] = count( $listings );
		ADCT_DDPro_Logger::info(
			sprintf(
				'%s started: %d listings in feed.',
				$dry_run ? 'Dry run' : 'Sync',
				count( $listings )
			)
		);

		$processed = 0;

		foreach ( $listings as $listing ) {
			$dd_id = isset( $listing['dd_id'] ) ? (string) $listing['dd_id'] : '';

			if ( '' === $dd_id ) {
				++$summary['skipped'];
				ADCT_DDPro_Logger::warn( 'Listing skipped: no dd_id present.' );
				continue;
			}

			$seen_ids[] = $dd_id;

			if ( $processed >= $per_run ) {
				++$summary['skipped'];
				continue;
			}

			$existing = $this->find_by_dd_id( $dd_id );

			/*
			 * Not known by dd_id — but we may already hold this exact car under
			 * its VIN (a product created by hand, or a listing DD Pro re-issued
			 * with a new dd_id). Adopt it rather than creating a duplicate, so
			 * that from now on it syncs like any other managed car.
			 */
			if ( null === $existing ) {
				$adopted = $this->adopt_by_vin( $listing, $dd_id, $dry_run );
				if ( $adopted > 0 ) {
					$existing = $adopted;
					++$summary['adopted'];
				}
			}

			/*
			 * No VIN link, but brand+year+price+mileage+colour all match exactly one
			 * existing product — strong enough to link without a VIN. Ambiguous ties
			 * (two products matching) fall through to review instead.
			 */
			if ( null === $existing ) {
				$auto = $this->auto_match_by_specs( $listing, $dd_id, $mapper, $reconciler, $dry_run );
				if ( $auto > 0 ) {
					$existing = $auto;
					++$summary['auto_matched'];
				}
			}

			try {
				if ( null === $existing ) {
					/*
					 * Fuzzy no-VIN hold: this car has no VIN link, but it may be a
					 * product already on the site. Creating it would duplicate a page
					 * we might already rank for, so park it for human review instead.
					 */
					if ( $this->should_hold_for_review( $listing, $dd_id, $mapper, $reconciler, $dry_run ) ) {
						++$summary['held'];
						continue;
					}

					$result = $this->create_product( $listing, $mapper, $dry_run );

					if ( self::SKIP_DUPLICATE === $result ) {
						// Deliberately not created — a duplicate, not a failure.
						++$summary['duplicates'];
					} elseif ( $result > 0 || $dry_run ) {
						++$summary['created'];
						++$processed;
					} else {
						++$summary['errors'];
					}
				} else {
					/*
					 * Matched car (by dd_id, VIN adoption, or spec auto-match): always
					 * check whether the feed now carries a VIN and, if so, make it the
					 * SKU. Done here — ahead of the unchanged short-circuit — so a VIN
					 * added on the DD Pro side is picked up even on a run where nothing
					 * else about the listing changed.
					 */
					$vin_note = $this->stamp_vin( $existing, $listing, $dry_run );
					if ( '' !== $vin_note ) {
						ADCT_DDPro_Logger::change( sprintf( '%s #%d: %s', $dry_run ? 'WOULD UPDATE' : 'Updated', $existing, $vin_note ), $dd_id, $existing );
					}

					$hash_now = $mapper->hash( $listing );
					$hash_old = (string) get_post_meta( $existing, ADCT_DDPRO_META_HASH, true );

					/*
					 * The hash short-circuit is a performance optimisation, but it
					 * must never mask a product whose availability has drifted from
					 * the feed. That happens whenever a car leaves the feed and
					 * comes back unchanged: it was marked out of stock while absent,
					 * and on its return the listing JSON is byte-identical, so a
					 * pure hash comparison would skip it and leave it out of stock
					 * permanently.
					 */
					if ( $hash_now === $hash_old && ! $this->availability_drifted( $existing, $listing, $mapper ) ) {
						++$summary['unchanged'];
						continue;
					}

					$this->update_product( $existing, $listing, $mapper, $dry_run );
					++$summary['updated'];
					++$processed;
				}
			} catch ( Throwable $e ) {
				++$summary['errors'];
				ADCT_DDPro_Logger::error( 'Exception: ' . $e->getMessage(), $dd_id, (int) ( $existing ?? 0 ) );
			}
		}

		// Cars we manage that are no longer in the feed.
		$summary['sold'] = $this->handle_missing( $seen_ids, $dry_run );

		$queued            = $this->count_queued_photos();
		$summary['photos_q'] = $queued;
		$summary['ok']       = true;
		$summary['message']  = sprintf(
			'%s finished. Created %d, updated %d, unchanged %d, adopted by VIN %d, auto-matched by specs %d, held for review %d, duplicates blocked %d, sold/missing %d, skipped %d, errors %d. %d cars have photos queued.',
			$dry_run ? 'Dry run' : 'Sync',
			$summary['created'],
			$summary['updated'],
			$summary['unchanged'],
			$summary['adopted'],
			$summary['auto_matched'],
			$summary['held'],
			$summary['duplicates'],
			$summary['sold'],
			$summary['skipped'],
			$summary['errors'],
			$queued
		);

		ADCT_DDPro_Logger::info( $summary['message'] );

		if ( ! $dry_run ) {
			update_option( 'adct_ddpro_last_run', array( 'at' => time(), 'summary' => $summary ), false );
			// Keep the log bounded (there is no manual "clear" any more).
			ADCT_DDPro_Logger::prune();
		}

		return $summary;
	}

	/**
	 * True when the product's stock status disagrees with the feed.
	 *
	 * @param int                 $post_id Product.
	 * @param array<string,mixed> $listing Feed listing.
	 * @param ADCT_DDPro_Mapper   $mapper  Mapper.
	 */
	private function availability_drifted( int $post_id, array $listing, ADCT_DDPro_Mapper $mapper ): bool {
		$stock     = (string) get_post_meta( $post_id, '_stock_status', true );
		$available = $mapper->is_available( $listing );

		return $available ? ( 'outofstock' === $stock ) : ( 'outofstock' !== $stock );
	}

	/**
	 * Load dd_id and VIN lookups for the whole catalogue in two queries.
	 */
	private function build_indexes(): void {
		global $wpdb;

		$this->dd_index  = array();
		$this->vin_index = array();

		$statuses = "'publish','draft','pending','private','future'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value <> ''
				   AND p.post_type = 'product' AND p.post_status IN ({$statuses})",
				ADCT_DDPRO_META_ID
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$this->dd_index[ (string) $r['meta_value'] ] = (int) $r['post_id'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, UPPER(pm.meta_value) AS vin FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_sku' AND CHAR_LENGTH(pm.meta_value) = 17
			   AND p.post_type = 'product' AND p.post_status IN ({$statuses})",
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$this->vin_index[ (string) $r['vin'] ][] = (int) $r['post_id'];
		}

		// Stored hashes too, so the unchanged-car fast path costs no queries at all.
		$this->hash_index = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				ADCT_DDPRO_META_HASH
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$this->hash_index[ (int) $r['post_id'] ] = (string) $r['meta_value'];
		}
	}

	/**
	 * Find a product previously imported for this dd_id.
	 *
	 * @param string $dd_id DD Pro id.
	 * @return int|null Post ID.
	 */
	private function find_by_dd_id( string $dd_id ): ?int {
		if ( null === $this->dd_index ) {
			$this->build_indexes();
		}
		return $this->dd_index[ $dd_id ] ?? null;
	}

	/**
	 * Every product carrying this VIN in `_sku`.
	 *
	 * @param string $vin VIN.
	 * @return array<int,int>
	 */
	private function find_products_by_vin( string $vin ): array {
		$vin = strtoupper( trim( $vin ) );

		// A real VIN is 17 characters; anything shorter is a stock number and
		// would produce false matches against the existing catalogue.
		if ( 17 !== strlen( $vin ) ) {
			return array();
		}
		if ( null === $this->vin_index ) {
			$this->build_indexes();
		}
		return $this->vin_index[ $vin ] ?? array();
	}

	/**
	 * Single product holding this VIN.
	 *
	 * Returns 0 when nothing matches AND when more than one product matches —
	 * an ambiguous VIN must never be resolved by guessing.
	 *
	 * @param string $vin VIN.
	 * @return int Post ID, or 0.
	 */
	private function find_by_vin( string $vin ): int {
		$ids = $this->find_products_by_vin( $vin );
		return 1 === count( $ids ) ? $ids[0] : 0;
	}

	/**
	 * Claim an existing product that carries this listing's VIN.
	 *
	 * VIN is the only durable shared key between the two systems: DD Pro assigns
	 * dd_id and reassigns it if a listing is re-created, while the VIN belongs to
	 * the physical car. Once adopted, the product is written with `_ddpro_id` and
	 * the normal update path takes over — which means price and mileage start
	 * syncing while the title, description and Yoast fields stay untouched.
	 *
	 * @param array<string,mixed> $listing Feed listing.
	 * @param string              $dd_id   Feed id.
	 * @param bool                $dry_run Dry run.
	 * @return int Adopted post ID, or 0.
	 */
	private function adopt_by_vin( array $listing, string $dd_id, bool $dry_run ): int {
		if ( 'adopt' !== ADCT_DDPro_Settings::get( 'vin_match_action' ) ) {
			return 0;
		}

		$vin     = strtoupper( trim( (string) ( $listing['vin'] ?? '' ) ) );
		$matches = $this->find_products_by_vin( $vin );

		if ( count( $matches ) > 1 ) {
			ADCT_DDPro_Logger::error(
				sprintf(
					'VIN %s is on %d products (#%s) — refusing to adopt any of them. Two products share a VIN; delete or correct one, then re-run.',
					$vin,
					count( $matches ),
					implode( ', #', $matches )
				),
				$dd_id
			);
			return 0;
		}

		$owner = 1 === count( $matches ) ? $matches[0] : 0;

		if ( $owner <= 0 ) {
			return 0;
		}

		// Never steal a product that another listing already manages.
		$held = (string) get_post_meta( $owner, ADCT_DDPRO_META_ID, true );
		if ( '' !== $held && $held !== $dd_id ) {
			ADCT_DDPro_Logger::warn(
				sprintf(
					'VIN %s matches product #%d, but that product is already linked to listing %s. Left alone — resolve by hand.',
					$vin,
					$owner,
					$held
				),
				$dd_id,
				$owner
			);
			return 0;
		}

		ADCT_DDPro_Logger::change(
			sprintf(
				'%s existing product #%d ("%s") by VIN %s — no duplicate created; it will now sync price and mileage.',
				$dry_run ? 'WOULD ADOPT' : 'Adopted',
				$owner,
				get_the_title( $owner ),
				$vin
			),
			$dd_id,
			$owner
		);

		$this->just_adopted[ $owner ] = true;
		$this->dd_index[ $dd_id ]     = $owner;

		if ( ! $dry_run ) {
			update_post_meta( $owner, ADCT_DDPRO_META_ID, $dd_id );
			update_post_meta( $owner, ADCT_DDPRO_META_GTIN, strtok( (string) $dd_id, '-' ) );
			// No stored hash => the update path treats it as changed and brings
			// price, mileage and photos up to date on this very run.
			delete_post_meta( $owner, ADCT_DDPRO_META_HASH );
		}

		return $owner;
	}

	/**
	 * Link a no-VIN car to an existing product when the hard facts all agree:
	 * brand, year, price (exact), mileage, and colour. That combination is strong
	 * enough to treat as the same physical car without a VIN.
	 *
	 * Safety: only when EXACTLY ONE product meets all of them. A tie (two products
	 * matching) is refused, so it falls through to human review instead.
	 *
	 * @param array<string,mixed>   $listing    Feed listing.
	 * @param string                $dd_id      Feed id.
	 * @param ADCT_DDPro_Mapper     $mapper     Mapper.
	 * @param ADCT_DDPro_Reconciler $reconciler Reconciler.
	 * @param bool                  $dry_run    Dry run.
	 * @return int Linked post ID, or 0.
	 */
	private function auto_match_by_specs( array $listing, string $dd_id, ADCT_DDPro_Mapper $mapper, ADCT_DDPro_Reconciler $reconciler, bool $dry_run ): int {
		if ( ! ADCT_DDPro_Settings::get( 'auto_match_specs' ) ) {
			return 0;
		}
		// A human already said this one is genuinely new.
		if ( ADCT_DDPro_Review::is_forced( $dd_id ) ) {
			return 0;
		}

		// Candidates already gate on brand+year; we additionally require the MODEL
		// to match, a mileage match, and a colour match — all reported as reasons.
		// (Price is not required: the feed updates price, so it may differ on a
		// genuine same-car match.)
		$strong = array();
		foreach ( $reconciler->candidates( $listing, $mapper ) as $c ) {
			$r = (string) ( $c['reasons'] ?? '' );
			if ( false !== strpos( $r, 'model ' )
				&& false !== strpos( $r, 'mileage match' )
				&& false !== strpos( $r, 'colour' ) ) {
				$strong[] = (int) $c['post_id'];
			}
		}
		$strong = array_values( array_unique( $strong ) );

		if ( 1 !== count( $strong ) ) {
			return 0; // none, or ambiguous — leave to review
		}
		$owner = $strong[0];

		// Never steal a product another listing already manages.
		$held = (string) get_post_meta( $owner, ADCT_DDPRO_META_ID, true );
		if ( '' !== $held && $held !== $dd_id ) {
			return 0;
		}

		ADCT_DDPro_Logger::change(
			sprintf(
				'%s existing product #%d ("%s") by exact specs — brand, year, model, mileage and colour all match (no VIN).',
				$dry_run ? 'WOULD AUTO-MATCH' : 'Auto-matched',
				$owner,
				get_the_title( $owner )
			),
			$dd_id,
			$owner
		);

		if ( ! $dry_run ) {
			$this->just_adopted[ $owner ] = true;
			$this->dd_index[ $dd_id ]     = $owner;
			update_post_meta( $owner, ADCT_DDPRO_META_ID, $dd_id );
			update_post_meta( $owner, ADCT_DDPRO_META_GTIN, strtok( (string) $dd_id, '-' ) );
			delete_post_meta( $owner, ADCT_DDPRO_META_HASH );
			ADCT_DDPro_Review::remove( $dd_id );
		}

		return $owner;
	}

	/**
	 * Decide whether an un-created car should be held for human review instead
	 * of being created, because it fuzzily matches a product already on the site.
	 *
	 * A VIN match never reaches here — those are adopted automatically upstream.
	 * This is only the no-VIN case the review queue exists for.
	 *
	 * @param array<string,mixed>   $listing    Feed listing.
	 * @param string                $dd_id      Feed id.
	 * @param ADCT_DDPro_Mapper     $mapper     Mapper.
	 * @param ADCT_DDPro_Reconciler $reconciler Reconciler.
	 * @param bool                  $dry_run    Dry run.
	 */
	private function should_hold_for_review( array $listing, string $dd_id, ADCT_DDPro_Mapper $mapper, ADCT_DDPro_Reconciler $reconciler, bool $dry_run ): bool {
		if ( ! ADCT_DDPro_Settings::get( 'review_fuzzy' ) ) {
			return false;
		}

		// A human already decided this one is genuinely new.
		if ( ADCT_DDPro_Review::is_forced( $dd_id ) ) {
			if ( ! $dry_run ) {
				ADCT_DDPro_Review::clear_forced( $dd_id );
			}
			return false;
		}

		// Already parked — keep it parked; do not create.
		if ( array_key_exists( $dd_id, ADCT_DDPro_Review::queue() ) ) {
			return true;
		}

		$candidates = $reconciler->candidates( $listing, $mapper );
		$top        = (int) ( $candidates[0]['score'] ?? 0 );

		if ( $top < ADCT_DDPro_Review::HOLD_THRESHOLD ) {
			return false; // No plausible existing match — a genuinely new car.
		}

		$price   = $mapper->price( $listing );
		$summary = array(
			'label'   => trim( sprintf( '%s %s %s', $listing['year'] ?? '', $listing['brand']['name'] ?? '', $mapper->model_name( $listing ) ) ),
			'make'    => (string) ( $listing['brand']['name'] ?? '' ),
			'year'    => (string) ( $listing['year'] ?? '' ),
			'price'   => null !== $price ? number_format( (float) $price ) : '',
			'mileage' => isset( $listing['mileage_km'] ) ? number_format( (float) $listing['mileage_km'] ) : '',
			'color'   => (string) ( $listing['body_color']['name'] ?? '' ),
			'vin'     => (string) ( $listing['vin'] ?? '' ),
		);

		if ( $dry_run ) {
			ADCT_DDPro_Logger::change( sprintf( 'WOULD HOLD for review: "%s" (%d candidate(s), top score %d)', $summary['label'], count( $candidates ), $top ), $dd_id );
		} else {
			ADCT_DDPro_Review::hold( $dd_id, $summary, $candidates );
			ADCT_DDPro_Logger::change( sprintf( 'Held for review: "%s" resembles an existing product (top score %d) — not created.', $summary['label'], $top ), $dd_id );
		}

		return true;
	}

	/**
	 * Create a new product from a listing.
	 *
	 * @param array<string,mixed>  $listing Listing.
	 * @param ADCT_DDPro_Mapper    $mapper  Mapper.
	 * @param bool                 $dry_run Dry run.
	 * @return int New post ID, 0 on failure.
	 */
	private function create_product( array $listing, ADCT_DDPro_Mapper $mapper, bool $dry_run ): int {
		$dd_id  = (string) $listing['dd_id'];
		$title  = $mapper->suggested_title( $listing );
		$target = (string) ADCT_DDPro_Settings::get( 'new_post_status' );

		/*
		 * Gated publishing: when the target is "publish" and the gate is on, the
		 * product is created as a DRAFT and only promoted to publish later, once
		 * its photos have downloaded and it has a price and core specs. This stops
		 * a car from going live with empty image boxes. The promotion happens in
		 * drain_photo_queue() the moment the last photo lands. See
		 * maybe_publish_when_complete().
		 */
		$gated  = ( 'publish' === $target ) && (bool) ADCT_DDPro_Settings::get( 'gate_publish' );
		$status = $gated ? 'draft' : $target;

		/*
		 * Refuse to create a second product for a VIN we already hold. Better to
		 * skip and report than to silently duplicate a car.
		 */
		$vin         = (string) ( $listing['vin'] ?? '' );
		$vin_matches = $this->find_products_by_vin( $vin );
		$vin_owner   = $vin_matches[0] ?? 0;

		if ( $vin_owner > 0 ) {
			ADCT_DDPro_Logger::warn(
				sprintf(
					'SKIPPED creating "%s" — VIN %s already belongs to product #%d ("%s"). Link them instead: wp ddpro link --dd-id=%s --post-id=%d',
					$title,
					strtoupper( trim( $vin ) ),
					$vin_owner,
					get_the_title( $vin_owner ),
					$dd_id,
					$vin_owner
				),
				$dd_id,
				$vin_owner
			);
			return self::SKIP_DUPLICATE;
		}

		if ( $dry_run ) {
			ADCT_DDPro_Logger::change(
				sprintf( 'WOULD CREATE as %s: "%s" (%d photos)', $status, $title, count( $mapper->photo_urls( $listing ) ) ),
				$dd_id
			);
			return 0;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'product',
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $mapper->draft_content( $listing ),
				'post_excerpt' => $mapper->short_description( $listing ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			ADCT_DDPro_Logger::error(
				'Create failed: ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'unknown error' ),
				$dd_id
			);
			return 0;
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, ADCT_DDPRO_META_ID, $dd_id );
		update_post_meta( $post_id, ADCT_DDPRO_META_GTIN, strtok( (string) $dd_id, '-' ) );
		update_post_meta( $post_id, ADCT_DDPRO_META_LOCATION, (string) ( $listing['dealer_location_id'] ?? '' ) );

		// Mark this as a product the feed created (not an existing car we adopted),
		// so "new from DD Pro" is always an exact, filterable set.
		update_post_meta( $post_id, ADCT_DDPRO_META_CREATED, '1' );

		// Draft awaiting completeness — promoted to publish once photos and data are in.
		if ( $gated ) {
			update_post_meta( $post_id, ADCT_DDPRO_META_PENDING, '1' );
		}

		// Keep the in-memory lookups honest for the rest of this run.
		$this->dd_index[ $dd_id ] = $post_id;
		$vin_new                  = strtoupper( trim( (string) ( $listing['vin'] ?? '' ) ) );
		if ( 17 === strlen( $vin_new ) ) {
			$this->vin_index[ $vin_new ][] = $post_id;
		}

		// Full spec write happens only here, at creation.
		$this->write_acf( $post_id, $mapper->acf_fields( $listing ) );
		$this->write_wc_basics( $post_id, $listing, $mapper );
		$this->assign_brand( $post_id, $mapper->acf_fields( $listing )['make'] ?? '' );
		$this->write_import_extras( $post_id );
		$this->apply_stock_tags( $post_id, $mapper->is_available( $listing ), 'sold', false );
		$this->queue_photos( $post_id, $this->cap_photos( $mapper->photo_urls( $listing ) ) );

		update_post_meta( $post_id, ADCT_DDPRO_META_HASH, $mapper->hash( $listing ) );
		update_post_meta( $post_id, ADCT_DDPRO_META_SYNCED, time() );

		ADCT_DDPro_Logger::change(
			sprintf( 'Created product #%d as %s: "%s"', $post_id, $status, $title ),
			$dd_id,
			$post_id
		);

		// Surface source-data problems so they get fixed in DD Pro rather than
		// quietly shipping onto a product page.
		$warnings = $mapper->data_warnings( $listing );
		if ( $warnings ) {
			ADCT_DDPro_Logger::warn(
				sprintf( 'Feed data gaps for #%d: %s', $post_id, implode( '; ', $warnings ) ),
				$dd_id,
				$post_id
			);
		}

		return $post_id;
	}

	/**
	 * Update an existing product — owned fields only.
	 *
	 * @param int                 $post_id Product.
	 * @param array<string,mixed> $listing Listing.
	 * @param ADCT_DDPro_Mapper   $mapper  Mapper.
	 * @param bool                $dry_run Dry run.
	 */
	private function update_product( int $post_id, array $listing, ADCT_DDPro_Mapper $mapper, bool $dry_run ): void {
		$dd_id   = (string) $listing['dd_id'];
		$changes = array();

		$all_fields = $mapper->acf_fields( $listing );

		// --- Title & short description (feed-owned by owner choice) -------
		/*
		 * The showroom asked for the feed to keep the product TITLE and the SHORT
		 * description current. Both are gathered into one wp_update_post() call.
		 * The URL slug (post_name) is deliberately left out so the page keeps its
		 * address, and the LONG description (post_content) plus every Yoast field
		 * are never included, so hand-written SEO stays intact.
		 */
		$post_edits = array();

		if ( ADCT_DDPro_Settings::get( 'sync_title' ) ) {
			$new_title = $mapper->suggested_title( $listing );
			$old_title = (string) get_the_title( $post_id );
			if ( '' !== $new_title && $new_title !== $old_title ) {
				$post_edits['post_title'] = $new_title;
				$changes[]                = sprintf( 'title "%s" -> "%s"', $old_title, $new_title );
			}
		}

		if ( ADCT_DDPro_Settings::get( 'sync_short_desc' ) ) {
			$new_excerpt = $mapper->short_description( $listing );
			$old_excerpt = (string) get_post_field( 'post_excerpt', $post_id );
			if ( '' !== $new_excerpt && $new_excerpt !== $old_excerpt ) {
				$post_edits['post_excerpt'] = $new_excerpt;
				$changes[]                  = 'short description updated';
			}
		}

		if ( $post_edits && ! $dry_run ) {
			$post_edits['ID'] = $post_id;
			wp_update_post( $post_edits ); // No post_name key => slug/URL unchanged.
		}

		// --- Price -------------------------------------------------------
		$new_price = $mapper->price( $listing );
		$old_price = get_post_meta( $post_id, '_regular_price', true );

		if ( null !== $new_price && (string) $new_price !== (string) $old_price ) {
			$changes[] = sprintf( 'price %s -> %s', $old_price !== '' ? $old_price : '(none)', $new_price );
			if ( ! $dry_run ) {
				update_post_meta( $post_id, '_regular_price', (string) $new_price );
				$sale = get_post_meta( $post_id, '_sale_price', true );
				if ( '' === $sale ) {
					update_post_meta( $post_id, '_price', (string) $new_price );
				}
			}
		}

		// --- Whitelisted ACF fields --------------------------------------
		foreach ( self::SYNC_ON_UPDATE as $field ) {
			if ( ! array_key_exists( $field, $all_fields ) ) {
				continue;
			}
			$new = $all_fields[ $field ];
			$old = (string) get_post_meta( $post_id, $field, true );

			if ( '' !== $new && $new !== $old ) {
				$changes[] = sprintf( '%s "%s" -> "%s"', $field, $old, $new );
				if ( ! $dry_run ) {
					update_post_meta( $post_id, $field, $new );
					$this->ensure_acf_key( $post_id, $field );
				}
			}
		}

		// --- First-adoption reconciliation -------------------------------
		/*
		 * Only on the run that adopts a car. This is the moment WordPress and
		 * DD Pro are being brought into agreement, so spec fields are written
		 * too — subject to the adopt_fill policy. Content and SEO are still
		 * never touched.
		 */
		if ( isset( $this->just_adopted[ $post_id ] ) ) {
			$policy = (string) ADCT_DDPro_Settings::get( 'adopt_fill' );

			if ( 'none' !== $policy ) {
				$specs = array_diff_key( $all_fields, array_flip( self::SYNC_ON_UPDATE ) );

				foreach ( $specs as $field => $new_value ) {
					if ( '' === $new_value ) {
						continue;
					}

					$old_value = (string) get_post_meta( $post_id, $field, true );

					if ( $old_value === $new_value ) {
						continue;
					}
					// 'empty' fills gaps only; it never overwrites a human's value.
					if ( 'empty' === $policy && '' !== $old_value ) {
						continue;
					}

					$changes[] = sprintf(
						'%s %s "%s"',
						$field,
						'' === $old_value ? 'filled' : 'mirrored from "' . $old_value . '" to',
						$new_value
					);

					if ( ! $dry_run ) {
						update_post_meta( $post_id, $field, $new_value );
						$this->ensure_acf_key( $post_id, $field );
					}
				}
			}
		}

		// --- Availability ------------------------------------------------
		$available = $mapper->is_available( $listing );
		$stock     = (string) get_post_meta( $post_id, '_stock_status', true );

		if ( $mapper->status_is_unknown( $listing ) ) {
			ADCT_DDPro_Logger::warn(
				sprintf(
					'#%d: unrecognised feed status "%s" — left available. If this means sold, add the code to ADCT_DDPro_Mapper::UNAVAILABLE_CODES.',
					$post_id,
					(string) ( $listing['status']['code'] ?? '(empty)' )
				),
				$dd_id,
				$post_id
			);
		}

		if ( ! $available && 'outofstock' !== $stock ) {
			$changes[] = 'marked out of stock (feed status: ' . (string) ( $listing['status']['code'] ?? '?' ) . ')';
			$tag_note  = $this->apply_stock_tags( $post_id, false, 'status:' . (string) ( $listing['status']['code'] ?? '?' ), $dry_run );
			if ( '' !== $tag_note ) {
				$changes[] = $tag_note;
			}
			if ( ! $dry_run ) {
				$this->mark_unavailable( $post_id );
			}
		} elseif ( $available && 'outofstock' === $stock ) {
			$changes[] = 'back in stock';
			$tag_note  = $this->apply_stock_tags( $post_id, true, '', $dry_run );
			if ( '' !== $tag_note ) {
				$changes[] = $tag_note;
			}
			if ( ! $dry_run ) {
				update_post_meta( $post_id, '_stock_status', 'instock' );
			}
		}

		// --- New photos only --------------------------------------------
		$new_photos = $this->new_photo_urls( $post_id, $this->cap_photos( $mapper->photo_urls( $listing ) ) );

		/*
		 * A car that already existed on the site — adopted by VIN, spec-matched,
		 * or linked by the "apply verified matches" tool — has its own
		 * photography, shot and ordered by the dealership. Appending 25 CDN images
		 * to it is almost never wanted, so we skip feed photos for ANY product the
		 * feed did NOT itself create, as long as it already has a gallery. Only
		 * cars DD Pro actually created get their photos from the feed. (A
		 * pre-existing car with an empty gallery still has the feed fill it.)
		 */
		$is_feed_created = '1' === (string) get_post_meta( $post_id, ADCT_DDPRO_META_CREATED, true );
		if ( $new_photos
			&& ! $is_feed_created
			&& 'skip' === ADCT_DDPro_Settings::get( 'adopt_photos' )
			&& $this->has_own_photos( $post_id )
		) {
			$changes[] = sprintf( '%d feed photo(s) NOT imported — product already has its own gallery', count( $new_photos ) );
			if ( ! $dry_run ) {
				// Record them as seen so they are not re-offered every run.
				$map = get_post_meta( $post_id, ADCT_DDPRO_META_PHOTOS, true );
				$map = is_array( $map ) ? $map : array();
				foreach ( $new_photos as $u ) {
					$map[ md5( $u ) ] = 0;
				}
				update_post_meta( $post_id, ADCT_DDPRO_META_PHOTOS, $map );
			}
			$new_photos = array();
		}

		if ( $new_photos ) {
			$changes[] = sprintf( '%d new photo(s) queued', count( $new_photos ) );
			if ( ! $dry_run ) {
				$this->queue_photos( $post_id, $new_photos );
			}
		}

		if ( ! $dry_run ) {
			update_post_meta( $post_id, ADCT_DDPRO_META_HASH, $mapper->hash( $listing ) );
			update_post_meta( $post_id, ADCT_DDPRO_META_SYNCED, time() );
		}

		if ( $changes ) {
			ADCT_DDPro_Logger::change(
				sprintf(
					'%s #%d: %s',
					$dry_run ? 'WOULD UPDATE' : 'Updated',
					$post_id,
					implode( '; ', $changes )
				),
				$dd_id,
				$post_id
			);
		} else {
			ADCT_DDPro_Logger::info(
				sprintf( 'Feed changed for #%d but no owned field differed (content and SEO left untouched).', $post_id ),
				$dd_id,
				$post_id
			);
		}
	}

	/**
	 * Copy the feed's VIN into the product SKU when the listing carries a real
	 * 17-character VIN and the SKU does not already hold it.
	 *
	 * This is the "if the dd_id was matched, always check for a VIN and copy it"
	 * rule: on any matched car, whenever DD Pro provides a VIN it becomes the SKU,
	 * overwriting any older stock number. When the feed has no VIN the SKU is left
	 * exactly as it is. A VIN that is already the SKU produces no change, so this
	 * is safe to call on every run.
	 *
	 * The in-memory VIN index is kept honest so later listings in the same run see
	 * the new SKU.
	 *
	 * @param int                 $post_id Product.
	 * @param array<string,mixed> $listing Feed listing.
	 * @param bool                $dry_run Dry run.
	 * @return string Description of the change, or '' when nothing changed.
	 */
	private function stamp_vin( int $post_id, array $listing, bool $dry_run ): string {
		$vin = strtoupper( trim( (string) ( $listing['vin'] ?? '' ) ) );

		// Only a real 17-char VIN. A shorter value is a stock number and must
		// never overwrite a genuine SKU.
		if ( 17 !== strlen( $vin ) ) {
			return '';
		}

		$sku = strtoupper( trim( (string) get_post_meta( $post_id, '_sku', true ) ) );
		if ( $sku === $vin ) {
			return ''; // Already set — nothing to do.
		}

		if ( ! $dry_run ) {
			update_post_meta( $post_id, '_sku', sanitize_text_field( $vin ) );

			// Keep the run's VIN lookup in step with the new SKU.
			if ( null !== $this->vin_index ) {
				$this->vin_index[ $vin ]   = array_values( array_unique( array_merge( $this->vin_index[ $vin ] ?? array(), array( $post_id ) ) ) );
				if ( 17 === strlen( $sku ) && isset( $this->vin_index[ $sku ] ) ) {
					$this->vin_index[ $sku ] = array_values( array_diff( $this->vin_index[ $sku ], array( $post_id ) ) );
				}
			}
		}

		return '' === $sku
			? sprintf( 'SKU set to VIN %s', $vin )
			: sprintf( 'SKU %s -> VIN %s', $sku, $vin );
	}

	/**
	 * Products we manage that vanished from the feed.
	 *
	 * @param array<int,string> $seen_ids dd_ids present this run.
	 * @param bool              $dry_run  Dry run.
	 * @return int Count affected.
	 */
	private function handle_missing( array $seen_ids, bool $dry_run ): int {
		$policy = (string) ADCT_DDPro_Settings::get( 'sold_policy' );
		if ( 'ignore' === $policy ) {
			return 0;
		}

		$q = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => ADCT_DDPRO_META_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$count = 0;

		foreach ( $q->posts as $pid ) {
			$pid   = (int) $pid;
			$dd_id = (string) get_post_meta( $pid, ADCT_DDPRO_META_ID, true );

			if ( '' === $dd_id || in_array( $dd_id, $seen_ids, true ) ) {
				continue;
			}

			if ( 'draft' === $policy ) {
				if ( 'draft' === get_post_status( $pid ) ) {
					continue;
				}
				++$count;
				ADCT_DDPro_Logger::change(
					sprintf( '%s #%d to draft: no longer in feed.', $dry_run ? 'WOULD SET' : 'Set', $pid ),
					$dd_id,
					$pid
				);
				if ( ! $dry_run ) {
					wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
				}
			} else { // outofstock
				if ( 'outofstock' === (string) get_post_meta( $pid, '_stock_status', true ) ) {
					continue;
				}
				++$count;
				ADCT_DDPro_Logger::change(
					sprintf( '%s #%d out of stock: no longer in feed.', $dry_run ? 'WOULD MARK' : 'Marked', $pid ),
					$dd_id,
					$pid
				);
				if ( ! $dry_run ) {
					$this->mark_unavailable( $pid, 'missing-from-feed' );
				} else {
					$this->apply_stock_tags( $pid, false, 'missing-from-feed', true );
				}
			}
		}

		return $count;
	}

	/**
	 * Resolve a product_tag term by name, creating it only if missing.
	 *
	 * @param string $name Tag name.
	 * @return int Term ID, or 0.
	 */
	private function tag_id( string $name ): int {
		$name = trim( $name );
		if ( '' === $name || ! taxonomy_exists( 'product_tag' ) ) {
			return 0;
		}

		$term = get_term_by( 'name', $name, 'product_tag' );
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}

		$created = wp_insert_term( $name, 'product_tag' );
		if ( is_wp_error( $created ) ) {
			ADCT_DDPro_Logger::warn( 'Could not create product tag "' . $name . '": ' . $created->get_error_message() );
			return 0;
		}

		return (int) $created['term_id'];
	}

	/**
	 * Give a product exactly ONE tag: the sold tag, or the available tag.
	 *
	 * Any other tags are removed. This is deliberate — a car must never read as
	 * both sold and available, and mixed tags are what makes shop filtering
	 * unreliable.
	 *
	 * Why a car became unavailable (sold, returned to its owner, export simply
	 * switched off) cannot be expressed in the tag, because there is only one.
	 * The reason and the date are recorded in post meta instead, so the shop
	 * stays simple while the office can still tell the cases apart.
	 *
	 * @param int    $post_id   Product.
	 * @param bool   $available Feed availability.
	 * @param string $reason    Why it became unavailable.
	 * @param bool   $dry_run   Dry run.
	 * @return string Description of the change, or '' when nothing changed.
	 */
	private function apply_stock_tags( int $post_id, bool $available, string $reason = '', bool $dry_run = false ): string {
		if ( ! ADCT_DDPro_Settings::get( 'manage_tags' ) || ! taxonomy_exists( 'product_tag' ) ) {
			return '';
		}

		$want_name = $available
			? (string) ADCT_DDPro_Settings::get( 'available_tag' )
			: (string) ADCT_DDPro_Settings::get( 'sold_tag' );

		$want_id = $this->tag_id( $want_name );
		if ( $want_id <= 0 ) {
			return '';
		}

		$current = wp_get_object_terms( $post_id, 'product_tag', array( 'fields' => 'ids' ) );
		$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );

		if ( ! $dry_run ) {
			if ( $available ) {
				delete_post_meta( $post_id, ADCT_DDPRO_META_GONE_SINCE );
				delete_post_meta( $post_id, ADCT_DDPRO_META_GONE_REASON );
			} else {
				if ( ! metadata_exists( 'post', $post_id, ADCT_DDPRO_META_GONE_SINCE ) ) {
					update_post_meta( $post_id, ADCT_DDPRO_META_GONE_SINCE, time() );
				}
				update_post_meta( $post_id, ADCT_DDPRO_META_GONE_REASON, $reason );
			}
		}

		// Already exactly right.
		if ( array( $want_id ) === $current ) {
			return '';
		}

		if ( ! $dry_run ) {
			// false = replace, so the product ends up with this tag and no other.
			wp_set_object_terms( $post_id, array( $want_id ), 'product_tag', false );
		}

		$removed = array();
		foreach ( array_diff( $current, array( $want_id ) ) as $tid ) {
			$t = get_term( $tid, 'product_tag' );
			if ( $t instanceof WP_Term ) {
				$removed[] = $t->name;
			}
		}

		return $removed
			? sprintf( 'tag set to "%s" only (removed %s)', $want_name, implode( ', ', $removed ) )
			: sprintf( 'tag set to "%s"', $want_name );
	}

	/**
	 * Mark a product unavailable without unpublishing it — the page keeps its
	 * rankings and inbound links instead of turning into a 404.
	 *
	 * @param int $post_id Product.
	 */
	private function mark_unavailable( int $post_id, string $reason = 'sold' ): void {
		update_post_meta( $post_id, '_stock_status', 'outofstock' );
		$this->apply_stock_tags( $post_id, false, $reason, false );
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$product->set_stock_status( 'outofstock' );
				$product->save();
			}
		}
	}

	/**
	 * Write ACF values plus their field-key pointers.
	 *
	 * @param int                  $post_id Product.
	 * @param array<string,string> $fields  name => value.
	 */
	private function write_acf( int $post_id, array $fields ): void {
		foreach ( $fields as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			update_post_meta( $post_id, $name, $value );
			$this->ensure_acf_key( $post_id, $name );
		}
	}

	/**
	 * ACF needs `_field_name` = field key alongside the value or the editor
	 * renders the field as empty.
	 *
	 * @param int    $post_id Product.
	 * @param string $name    Field name.
	 */
	private function ensure_acf_key( int $post_id, string $name ): void {
		$key = ADCT_DDPro_Mapper::ACF_KEYS[ $name ] ?? '';
		if ( '' !== $key ) {
			update_post_meta( $post_id, '_' . $name, $key );
		}
	}

	/**
	 * WooCommerce price and product-type basics for a new product.
	 *
	 * @param array<string,mixed> $listing Listing.
	 */
	private function write_wc_basics( int $post_id, array $listing, ADCT_DDPro_Mapper $mapper ): void {
		$price = $mapper->price( $listing );

		if ( null !== $price ) {
			update_post_meta( $post_id, '_regular_price', (string) $price );
			update_post_meta( $post_id, '_price', (string) $price );
		}

		update_post_meta( $post_id, '_stock_status', $mapper->is_available( $listing ) ? 'instock' : 'outofstock' );
		update_post_meta( $post_id, '_manage_stock', 'no' );
		update_post_meta( $post_id, '_virtual', 'no' );
		update_post_meta( $post_id, '_downloadable', 'no' );
		update_post_meta( $post_id, '_sold_individually', 'yes' );
		update_post_meta( $post_id, '_tax_status', 'none' );
		update_post_meta( $post_id, '_backorders', 'no' );

		if ( ! empty( $listing['vin'] ) ) {
			update_post_meta( $post_id, '_sku', sanitize_text_field( (string) $listing['vin'] ) );
		}

		wp_set_object_terms( $post_id, 'simple', 'product_type' );
	}

	/**
	 * Attach the product_brand term, reusing an existing term when present so
	 * the feed cannot create near-duplicate brands.
	 *
	 * @param int    $post_id Product.
	 * @param string $brand   Brand name.
	 */
	private function assign_brand( int $post_id, string $brand ): void {
		$brand = trim( $brand );
		if ( '' === $brand || ! taxonomy_exists( 'product_brand' ) ) {
			return;
		}

		$term = get_term_by( 'name', $brand, 'product_brand' );
		if ( ! $term ) {
			$created = wp_insert_term( $brand, 'product_brand' );
			if ( is_wp_error( $created ) ) {
				ADCT_DDPro_Logger::warn( 'Could not create brand term "' . $brand . '": ' . $created->get_error_message(), '', $post_id );
				return;
			}
			$term_id = (int) $created['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}

		wp_set_object_terms( $post_id, array( $term_id ), 'product_brand', false );
	}

	/**
	 * Fill the standing fields every hand-made product carries, so an imported
	 * car does not read as half-finished: product category, the sales-agent list,
	 * the official video URL, and the showroom contact link. Values come from
	 * settings so the office can change them without touching code.
	 *
	 * The official video IMAGE is not set here — it mirrors the featured image,
	 * which does not exist until photos download, so it is set in
	 * drain_photo_queue() at the moment the thumbnail is attached.
	 *
	 * @param int $post_id Product.
	 */
	private function write_import_extras( int $post_id ): void {
		$settings = ADCT_DDPro_Settings::all();

		// Product category (e.g. "Luxury Cars"). Added, not replaced.
		$cat = trim( (string) ( $settings['default_category'] ?? '' ) );
		if ( '' !== $cat && taxonomy_exists( 'product_cat' ) ) {
			$term = get_term_by( 'name', $cat, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				wp_set_object_terms( $post_id, array( (int) $term->term_id ), 'product_cat', true );
			} else {
				ADCT_DDPro_Logger::warn( sprintf( 'Product category "%s" does not exist — not assigned to #%d.', $cat, $post_id ), '', $post_id );
			}
		}

		// Sales agents: all of them, Faisal first (ACF relationship field).
		if ( ! empty( $settings['assign_agents'] ) ) {
			$agents = $this->all_sales_agents();
			if ( $agents ) {
				update_post_meta( $post_id, 'sales_agents', $agents );
				update_post_meta( $post_id, '_sales_agents', ADCT_DDPro_Mapper::ACF_KEYS['sales_agents'] );
			}
		}

		// Official video URL (ACF text field).
		$video = trim( (string) ( $settings['default_video_url'] ?? '' ) );
		if ( '' !== $video ) {
			update_post_meta( $post_id, 'official_video_url', esc_url_raw( $video ) );
			update_post_meta( $post_id, '_official_video_url', ADCT_DDPro_Mapper::ACF_KEYS['official_video_url'] );
		}

		// Showroom contact link (ACF link_url + link_text both hold the URL).
		$link = trim( (string) ( $settings['contact_link'] ?? '' ) );
		if ( '' !== $link ) {
			$link = esc_url_raw( $link );
			update_post_meta( $post_id, 'link_url', $link );
			update_post_meta( $post_id, '_link_url', ADCT_DDPro_Mapper::ACF_KEYS['link_url'] );
			update_post_meta( $post_id, 'link_text', $link );
			update_post_meta( $post_id, '_link_text', ADCT_DDPro_Mapper::ACF_KEYS['link_text'] );
		}
	}

	/**
	 * All published sales-agent post IDs (as strings, the shape an ACF
	 * relationship field stores), ordered with Faisal first.
	 *
	 * @return array<int,string>
	 */
	private function all_sales_agents(): array {
		if ( null !== $this->agents_cache ) {
			return $this->agents_cache;
		}

		if ( ! post_type_exists( 'sales-agents' ) ) {
			$this->agents_cache = array();
			return $this->agents_cache;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'sales-agents',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		// Move Faisal to the front.
		$faisal = 0;
		foreach ( $ids as $id ) {
			if ( false !== stripos( (string) get_the_title( $id ), 'faisal' ) ) {
				$faisal = $id;
				break;
			}
		}
		if ( $faisal > 0 ) {
			$ids = array_merge( array( $faisal ), array_values( array_diff( $ids, array( $faisal ) ) ) );
		}

		$this->agents_cache = array_map( 'strval', $ids );
		return $this->agents_cache;
	}

	// -----------------------------------------------------------------
	// Photos
	// -----------------------------------------------------------------

	/**
	 * Whether the product already has photography of its own.
	 *
	 * @param int $post_id Product.
	 */
	private function has_own_photos( int $post_id ): bool {
		if ( get_post_thumbnail_id( $post_id ) ) {
			return true;
		}
		$gallery = array_filter( explode( ',', (string) get_post_meta( $post_id, '_product_image_gallery', true ) ) );
		return count( $gallery ) > 0;
	}

	/**
	 * Photo URLs not yet imported for this product.
	 *
	 * @param int               $post_id Product.
	 * @param array<int,string> $urls    Feed URLs.
	 * @return array<int,string>
	 */
	private function new_photo_urls( int $post_id, array $urls ): array {
		$map = get_post_meta( $post_id, ADCT_DDPRO_META_PHOTOS, true );
		$map = is_array( $map ) ? $map : array();

		$queued = get_post_meta( $post_id, ADCT_DDPRO_META_QUEUE, true );
		$queued = is_array( $queued ) ? $queued : array();

		return array_values(
			array_filter(
				$urls,
				static function ( string $u ) use ( $map, $queued ): bool {
					return ! isset( $map[ md5( $u ) ] ) && ! in_array( $u, $queued, true );
				}
			)
		);
	}

	/**
	 * Cap a photo list to the per-car limit from settings (0 = no limit).
	 *
	 * @param array<int,string> $urls URLs.
	 * @return array<int,string>
	 */
	private function cap_photos( array $urls ): array {
		$cap = (int) ADCT_DDPro_Settings::get( 'photos_per_car' );
		return $cap > 0 ? array_slice( $urls, 0, $cap ) : $urls;
	}

	/**
	 * Add URLs to a product's photo queue.
	 *
	 * @param int               $post_id Product.
	 * @param array<int,string> $urls    URLs.
	 */
	private function queue_photos( int $post_id, array $urls ): void {
		if ( ! $urls || ! ADCT_DDPro_Settings::get( 'attach_photos' ) ) {
			return;
		}

		$queue = get_post_meta( $post_id, ADCT_DDPRO_META_QUEUE, true );
		$queue = is_array( $queue ) ? $queue : array();

		update_post_meta( $post_id, ADCT_DDPRO_META_QUEUE, array_values( array_unique( array_merge( $queue, $urls ) ) ) );
	}

	/**
	 * Number of products with a non-empty photo queue.
	 */
	private function count_queued_photos(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' AND meta_value != 'a:0:{}'",
				ADCT_DDPRO_META_QUEUE
			)
		);
		return (int) $n;
	}

	/**
	 * Download a bounded number of queued photos.
	 *
	 * Runs on its own 5-minute cron so a 25-photo car cannot time out a sync.
	 *
	 * @return array{done:int,products:int}
	 */
	public function drain_photo_queue(): array {
		$budget = (int) ADCT_DDPro_Settings::get( 'photos_per_run' );
		$done   = 0;
		$touched = 0;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value != '' AND meta_value != 'a:0:{}'
				 ORDER BY post_id ASC LIMIT 20",
				ADCT_DDPRO_META_QUEUE
			)
		);

		if ( ! $post_ids ) {
			// No photos to fetch, but a complete-yet-stranded draft may still be
			// waiting for promotion, so run the safety-net sweep before leaving.
			$this->promote_ready_pending();
			return array( 'done' => 0, 'products' => 0 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		foreach ( $post_ids as $pid ) {
			if ( $done >= $budget ) {
				break;
			}

			$pid   = (int) $pid;
			$queue = get_post_meta( $pid, ADCT_DDPRO_META_QUEUE, true );
			$queue = is_array( $queue ) ? $queue : array();

			if ( ! $queue ) {
				delete_post_meta( $pid, ADCT_DDPRO_META_QUEUE );
				continue;
			}

			++$touched;
			$map = get_post_meta( $pid, ADCT_DDPRO_META_PHOTOS, true );
			$map = is_array( $map ) ? $map : array();

			$gallery = array_filter( explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) );

			while ( $queue && $done < $budget ) {
				$url = array_shift( $queue );
				$att = $this->sideload( $url, $pid );

				if ( is_wp_error( $att ) ) {
					ADCT_DDPro_Logger::warn( 'Photo failed (' . $url . '): ' . $att->get_error_message(), '', $pid );
					++$done; // consume budget so one bad URL cannot spin forever
					continue;
				}

				$map[ md5( $url ) ] = $att;

				if ( ! get_post_thumbnail_id( $pid ) ) {
					set_post_thumbnail( $pid, $att );
					// Mirror the featured image into the ACF "Official Video Image".
					update_post_meta( $pid, 'official_video_image', (string) $att );
					update_post_meta( $pid, '_official_video_image', ADCT_DDPro_Mapper::ACF_KEYS['official_video_image'] );
				} else {
					$gallery[] = (string) $att;
				}

				++$done;
			}

			update_post_meta( $pid, ADCT_DDPRO_META_PHOTOS, $map );
			update_post_meta( $pid, '_product_image_gallery', implode( ',', array_unique( $gallery ) ) );

			if ( $queue ) {
				update_post_meta( $pid, ADCT_DDPRO_META_QUEUE, array_values( $queue ) );
			} else {
				delete_post_meta( $pid, ADCT_DDPRO_META_QUEUE );
				ADCT_DDPro_Logger::info( 'All photos imported for #' . $pid . '.', (string) get_post_meta( $pid, ADCT_DDPRO_META_ID, true ), $pid );
				// The gallery is complete — this is the moment a gated draft can go live.
				$this->maybe_publish_when_complete( $pid );
			}
		}

		// Safety net: promote any gated draft that is already complete but was
		// left behind — e.g. a drain that crashed right after its last photo
		// landed, before the per-product promotion could run. Without this such a
		// car would stay a draft forever, because the queue that triggers
		// promotion is already empty and never revisited.
		$this->promote_ready_pending();

		if ( $done > 0 ) {
			ADCT_DDPro_Logger::info( sprintf( 'Photo queue: imported %d image(s) across %d product(s).', $done, $touched ) );
		}

		return array( 'done' => $done, 'products' => $touched );
	}

	/**
	 * Promote every gated draft that now meets the publish bar.
	 *
	 * Cheap: one meta query for the pending flag, then the same completeness
	 * check used everywhere else. Runs at the end of each photo drain.
	 *
	 * @return int Number promoted this pass.
	 */
	private function promote_ready_pending(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => ADCT_DDPRO_META_PENDING,
						'value' => '1',
					),
				),
			)
		);

		$promoted = 0;
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			$this->maybe_publish_when_complete( $pid );
			if ( 'publish' === get_post_status( $pid ) ) {
				++$promoted;
			}
		}

		return $promoted;
	}

	/**
	 * Whether a gated draft now meets the bar to go live: a featured image, a
	 * price, and the core specs (make, year, mileage). Anything short of this
	 * keeps the car as a draft rather than publishing an incomplete page.
	 *
	 * @param int $post_id Product.
	 */
	private function is_publishable( int $post_id ): bool {
		if ( ! get_post_thumbnail_id( $post_id ) ) {
			return false;
		}
		if ( (float) get_post_meta( $post_id, '_regular_price', true ) <= 0 ) {
			return false;
		}
		foreach ( array( 'make', 'year', 'mileage' ) as $field ) {
			if ( '' === trim( (string) get_post_meta( $post_id, $field, true ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Promote a gated draft to publish once it is complete.
	 *
	 * Called when a product's photo queue empties. A car only carries the pending
	 * flag when it was created under gated publishing (target = publish, gate on);
	 * everything else is left exactly as it is. An incomplete car keeps the flag
	 * and stays a draft, so it is retried on a later run rather than shipped broken.
	 *
	 * @param int $post_id Product.
	 */
	private function maybe_publish_when_complete( int $post_id ): void {
		if ( '1' !== (string) get_post_meta( $post_id, ADCT_DDPRO_META_PENDING, true ) ) {
			return;
		}

		// If a human already moved it out of draft, respect that and stop gating.
		if ( 'draft' !== get_post_status( $post_id ) ) {
			delete_post_meta( $post_id, ADCT_DDPRO_META_PENDING );
			return;
		}

		if ( ! $this->is_publishable( $post_id ) ) {
			return;
		}

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		delete_post_meta( $post_id, ADCT_DDPRO_META_PENDING );

		ADCT_DDPro_Logger::change(
			sprintf( 'Auto-published #%d — photos, price and specs all present.', $post_id ),
			(string) get_post_meta( $post_id, ADCT_DDPRO_META_ID, true ),
			$post_id
		);
	}

	/**
	 * Download one image into the media library and attach it to the product.
	 *
	 * Filenames are rewritten from the CDN's opaque hash to something
	 * descriptive, because image filenames and alt text are part of the SEO the
	 * site depends on.
	 *
	 * @param string $url     Image URL.
	 * @param int    $post_id Product.
	 * @return int|WP_Error Attachment ID.
	 */
	private function sideload( string $url, int $post_id ) {
		$tmp = download_url( $url, 45 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$title = get_the_title( $post_id );
		$base  = sanitize_title( $title );
		if ( '' === $base ) {
			$base = 'ddpro-' . $post_id;
		}

		$ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION );
		$ext = preg_match( '/^(jpe?g|png|webp|avif)$/i', (string) $ext ) ? strtolower( (string) $ext ) : 'jpg';

		$existing = get_post_meta( $post_id, ADCT_DDPRO_META_PHOTOS, true );
		$index    = ( is_array( $existing ) ? count( $existing ) : 0 ) + 1;

		$file = array(
			'name'     => substr( $base, 0, 80 ) . '-' . $index . '.' . $ext,
			'tmp_name' => $tmp,
		);

		$att_id = media_handle_sideload( $file, $post_id, null );

		if ( is_wp_error( $att_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $att_id;
		}

		// Alt text from the product title so images stay searchable.
		update_post_meta( (int) $att_id, '_wp_attachment_image_alt', $title );

		return (int) $att_id;
	}
}
