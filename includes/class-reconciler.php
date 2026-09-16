<?php
/**
 * Matches DD Pro listings against products that already exist on the site.
 *
 * Why this is needed
 * -----------------
 * There is no shared key. Existing products carry a 5-6 digit stock number in
 * `_sku`; the feed supplies a 17-character VIN. So a car already published on
 * the site will be imported a SECOND time unless it is linked first by writing
 * `_ddpro_id` onto the existing product.
 *
 * This class only ever SUGGESTS matches with a confidence score. Nothing is
 * linked automatically — a wrong link would attach a feed listing to the wrong
 * car and start syncing the wrong price onto a ranking page.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Reconciler {

	/**
	 * Canonical brand for the messy values found in the live `make` field.
	 * Keys are lowercased. Anything not listed is used as-is.
	 *
	 * @var array<string,string>
	 */
	private const BRAND_ALIASES = array(
		'porshe'                        => 'porsche',
		'bently'                        => 'bentley',
		'mecredes benz g 63 amg'        => 'mercedes benz',
		'mercedes benz g 63 amg'        => 'mercedes benz',
		'mercedes-benz'                 => 'mercedes benz',
		'range rover'                   => 'land rover',
		'lamborghini, aventador'        => 'lamborghini',
		'lamborghini aventador'         => 'lamborghini',
		'bmw, 7 series'                 => 'bmw',
		'mustang, ford'                 => 'ford',
		'rolls-royce ghost black badge' => 'rolls-royce',
		'rolls-royce ghost standard'    => 'rolls-royce',
		'suzuki jimny glx at 5-door'    => 'suzuki',
		'rolls royce'                   => 'rolls-royce',
	);

	/** Cached catalogue snapshot. @var array<int,array<string,mixed>>|null */
	private ?array $catalogue = null;

	/**
	 * Canonicalise a make string for comparison.
	 */
	public static function canon_brand( string $make ): string {
		$m = strtolower( trim( preg_replace( '/\s+/', ' ', $make ) ?? $make ) );
		if ( isset( self::BRAND_ALIASES[ $m ] ) ) {
			return self::BRAND_ALIASES[ $m ];
		}
		// Fall back to the leading token run before a comma.
		$m = trim( explode( ',', $m )[0] );
		return self::BRAND_ALIASES[ $m ] ?? $m;
	}

	/**
	 * Load every candidate product once.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function catalogue(): array {
		if ( null !== $this->catalogue ) {
			return $this->catalogue;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_status,
			        MAX(CASE WHEN m.meta_key='make'    THEN m.meta_value END) AS make,
			        MAX(CASE WHEN m.meta_key='year'    THEN m.meta_value END) AS year,
			        MAX(CASE WHEN m.meta_key='mileage' THEN m.meta_value END) AS mileage,
			        MAX(CASE WHEN m.meta_key='_regular_price' THEN m.meta_value END) AS price,
			        MAX(CASE WHEN m.meta_key='color'   THEN m.meta_value END) AS color,
			        MAX(CASE WHEN m.meta_key='_sku'    THEN m.meta_value END) AS sku,
			        MAX(CASE WHEN m.meta_key='_ddpro_id' THEN m.meta_value END) AS ddpro_id
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type='product' AND p.post_status IN ('publish','draft','pending','private')
			 GROUP BY p.ID",
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'ID'       => (int) $r['ID'],
				'title'    => (string) $r['post_title'],
				'status'   => (string) $r['post_status'],
				'make'     => self::canon_brand( (string) ( $r['make'] ?? '' ) ),
				'year'     => (int) ( $r['year'] ?? 0 ),
				'mileage'  => (float) preg_replace( '/[^\d.]/', '', (string) ( $r['mileage'] ?? '' ) ),
				'price'    => (float) ( $r['price'] ?? 0 ),
				'color'    => strtolower( trim( (string) ( $r['color'] ?? '' ) ) ),
				'sku'      => (string) ( $r['sku'] ?? '' ),
				'ddpro_id' => (string) ( $r['ddpro_id'] ?? '' ),
				'haystack' => strtolower( (string) $r['post_title'] ),
			);
		}

		$this->catalogue = $out;
		return $out;
	}

	/**
	 * Score candidate products for one feed listing.
	 *
	 * Scoring is deliberately conservative: year and brand must both agree or
	 * the candidate is discarded outright, because a "close" match on price
	 * alone is worthless for identifying a specific car.
	 *
	 * @param array<string,mixed> $listing Feed listing.
	 * @param ADCT_DDPro_Mapper   $mapper  Mapper.
	 * @param int                 $limit   Max candidates.
	 * @return array<int,array<string,mixed>>
	 */
	public function candidates( array $listing, ADCT_DDPro_Mapper $mapper, int $limit = 5 ): array {
		$feed_year  = (int) ( $listing['year'] ?? 0 );
		$feed_brand = self::canon_brand( (string) ( $listing['brand']['name'] ?? '' ) );
		$feed_model = strtolower( $mapper->model_name( $listing ) );
		$feed_price = (float) ( $mapper->price( $listing ) ?? 0 );
		$feed_km    = isset( $listing['mileage_km'] ) ? (float) $listing['mileage_km'] : 0.0;
		$feed_color = strtolower( trim( (string) ( $listing['body_color']['name'] ?? '' ) ) );

		$model_tokens = array_values(
			array_filter(
				preg_split( '/[^a-z0-9]+/', $feed_model ) ?: array(),
				static fn( string $t ): bool => strlen( $t ) >= 2
			)
		);

		$scored = array();

		foreach ( $this->catalogue() as $c ) {
			// Already linked to a different listing — never re-suggest.
			if ( '' !== $c['ddpro_id'] ) {
				continue;
			}

			// Hard gates.
			if ( $feed_year > 0 && $c['year'] > 0 && $feed_year !== $c['year'] ) {
				continue;
			}
			if ( '' !== $feed_brand && '' !== $c['make'] && $feed_brand !== $c['make'] ) {
				continue;
			}

			$score   = 0;
			$reasons = array();

			$score    += 30;
			$reasons[] = 'year+brand';

			// Model tokens present in the product title.
			$hits = 0;
			foreach ( $model_tokens as $t ) {
				if ( str_contains( $c['haystack'], $t ) ) {
					++$hits;
				}
			}
			if ( $model_tokens ) {
				$ratio  = $hits / count( $model_tokens );
				$score += (int) round( 40 * $ratio );
				if ( $hits > 0 ) {
					$reasons[] = sprintf( 'model %d/%d', $hits, count( $model_tokens ) );
				}
			}

			// Price proximity.
			if ( $feed_price > 0 && $c['price'] > 0 ) {
				$delta = abs( $feed_price - $c['price'] ) / max( $feed_price, $c['price'] );
				if ( $delta < 0.001 ) {
					$score    += 20;
					$reasons[] = 'price exact';
				} elseif ( $delta <= 0.10 ) {
					$score    += 10;
					$reasons[] = sprintf( 'price %.0f%%', $delta * 100 );
				}
			}

			// Mileage proximity.
			if ( $feed_km > 0 && $c['mileage'] > 0 ) {
				$delta = abs( $feed_km - $c['mileage'] ) / max( $feed_km, $c['mileage'] );
				if ( $delta <= 0.02 ) {
					$score    += 10;
					$reasons[] = 'mileage match';
				} elseif ( $delta <= 0.15 ) {
					$score    += 4;
					$reasons[] = 'mileage close';
				}
			}

			// Colour agreement — a weak tiebreaker, never a gate (naming varies).
			if ( '' !== $feed_color && '' !== $c['color']
				&& ( $feed_color === $c['color'] || str_contains( $c['color'], $feed_color ) || str_contains( $feed_color, $c['color'] ) ) ) {
				$score    += 5;
				$reasons[] = 'colour';
			}

			$scored[] = array(
				'post_id' => $c['ID'],
				'title'   => $c['title'],
				'status'  => $c['status'],
				'sku'     => $c['sku'],
				'price'   => $c['price'],
				'mileage' => $c['mileage'],
				'color'   => $c['color'],
				'score'   => $score,
				'reasons' => implode( ', ', $reasons ),
			);
		}

		usort( $scored, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return array_slice( $scored, 0, $limit );
	}

	/**
	 * Confidence banding for a score.
	 */
	public static function band( int $score ): string {
		if ( $score >= 85 ) {
			return 'high';
		}
		if ( $score >= 60 ) {
			return 'medium';
		}
		if ( $score >= 40 ) {
			return 'low';
		}
		return 'weak';
	}

	/**
	 * Link a feed listing to an existing product.
	 *
	 * @param string $dd_id   Feed id.
	 * @param int    $post_id Product.
	 * @return true|WP_Error
	 */
	public function link( string $dd_id, int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'product' !== $post->post_type ) {
			return new WP_Error( 'adct_ddpro_no_product', sprintf( 'Post #%d is not a product.', $post_id ) );
		}

		$existing = (string) get_post_meta( $post_id, ADCT_DDPRO_META_ID, true );
		if ( '' !== $existing && $existing !== $dd_id ) {
			return new WP_Error(
				'adct_ddpro_already_linked',
				sprintf( 'Product #%d is already linked to %s.', $post_id, $existing )
			);
		}

		// Refuse to double-link one listing to two products.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$other = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s AND post_id<>%d LIMIT 1",
				ADCT_DDPRO_META_ID,
				$dd_id,
				$post_id
			)
		);
		if ( $other > 0 ) {
			return new WP_Error(
				'adct_ddpro_dd_id_taken',
				sprintf( 'Listing %s is already linked to product #%d.', $dd_id, $other )
			);
		}

		update_post_meta( $post_id, ADCT_DDPRO_META_ID, $dd_id );
		update_post_meta( $post_id, ADCT_DDPRO_META_GTIN, strtok( (string) $dd_id, '-' ) );

		/*
		 * Deliberately do NOT set _ddpro_hash. Leaving it absent makes the next
		 * sync treat this car as changed, so price/mileage/photos are brought
		 * up to date once — while title, content and SEO stay untouched.
		 */
		delete_post_meta( $post_id, ADCT_DDPRO_META_HASH );

		ADCT_DDPro_Logger::change(
			sprintf( 'Linked existing product #%d to feed listing %s.', $post_id, $dd_id ),
			$dd_id,
			$post_id
		);

		return true;
	}

	/**
	 * Remove the link from a product.
	 *
	 * Clears the pending photo queue too. Without that, an unlinked product
	 * keeps a queue entry and the photo cron carries on downloading feed images
	 * onto a car the feed no longer manages.
	 *
	 * Already-imported attachments and the gallery are left alone — deleting
	 * media that may be in use is not this method's job.
	 *
	 * @param int $post_id Product.
	 */
	public function unlink( int $post_id ): void {
		$dd_id = (string) get_post_meta( $post_id, ADCT_DDPRO_META_ID, true );

		$pending = get_post_meta( $post_id, ADCT_DDPRO_META_QUEUE, true );
		$pending = is_array( $pending ) ? count( $pending ) : 0;

		delete_post_meta( $post_id, ADCT_DDPRO_META_ID );
		delete_post_meta( $post_id, ADCT_DDPRO_META_HASH );
		delete_post_meta( $post_id, ADCT_DDPRO_META_QUEUE );
		delete_post_meta( $post_id, ADCT_DDPRO_META_SYNCED );

		ADCT_DDPro_Logger::change(
			sprintf(
				'Unlinked product #%d from %s%s.',
				$post_id,
				'' !== $dd_id ? $dd_id : '(no listing)',
				$pending > 0 ? sprintf( '; cancelled %d pending photo download(s)', $pending ) : ''
			),
			$dd_id,
			$post_id
		);
	}
}
