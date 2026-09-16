<?php
/**
 * Consistency audit: is WordPress actually in step with DD Pro?
 *
 * Uses the VIN as the key, because it is the only identifier that belongs to
 * the physical car rather than to one of the two systems.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Auditor {

	/**
	 * Bucket descriptions, in the order they are reported.
	 *
	 * @var array<string,string>
	 */
	public const BUCKETS = array(
		'drift'     => 'linked, but a feed value differs - run a sync',
		'adoptable' => 'VIN is in the feed but not linked yet - sync will adopt it',
		'gone'      => 'VIN not in the feed - turned off or sold in DD Pro, or never added',
		'feed-only' => 'in DD Pro only - sync will create it as a draft',
		'no-vin'    => 'no VIN - cannot be matched, and WILL duplicate if DD Pro has it',
		'ok'        => 'in step with the feed',
	);

	/**
	 * Run the audit.
	 *
	 * @param array{listings:array<int,array<string,mixed>>,locations:array<int,array<string,mixed>>} $feed Decoded feed.
	 * @return array{rows:array<int,array<string,string>>,counts:array<string,int>,products:int}
	 */
	public function run( array $feed ): array {
		global $wpdb;

		$mapper = new ADCT_DDPro_Mapper( $feed['locations'] );

		$by_vin = array();
		foreach ( $feed['listings'] as $l ) {
			$vin = strtoupper( trim( (string) ( $l['vin'] ?? '' ) ) );
			if ( 17 === strlen( $vin ) ) {
				$by_vin[ $vin ] = $l;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$products = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_status,
			        MAX(CASE WHEN m.meta_key='_sku'           THEN m.meta_value END) AS sku,
			        MAX(CASE WHEN m.meta_key='_ddpro_id'      THEN m.meta_value END) AS dd_id,
			        MAX(CASE WHEN m.meta_key='_regular_price' THEN m.meta_value END) AS price,
			        MAX(CASE WHEN m.meta_key='mileage'        THEN m.meta_value END) AS mileage,
			        MAX(CASE WHEN m.meta_key='warranty'       THEN m.meta_value END) AS warranty,
			        MAX(CASE WHEN m.meta_key='_stock_status'  THEN m.meta_value END) AS stock
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type='product' AND p.post_status IN ('publish','draft','pending','private')
			 GROUP BY p.ID",
			ARRAY_A
		);

		$rows   = array();
		$seen   = array();
		$counts = array_fill_keys( array_keys( self::BUCKETS ), 0 );

		foreach ( (array) $products as $p ) {
			$pid   = (int) $p['ID'];
			$vin   = strtoupper( trim( (string) $p['sku'] ) );
			$dd_id = (string) $p['dd_id'];
			$has   = 17 === strlen( $vin );

			if ( $has ) {
				$seen[ $vin ] = true;
			}

			if ( ! $has ) {
				$bucket = 'no-vin';
				$note   = '' !== $dd_id ? 'linked by dd_id only - add its VIN' : 'no VIN and no link';
			} elseif ( ! isset( $by_vin[ $vin ] ) ) {
				$bucket = 'gone';
				$note   = '' !== $dd_id
					? 'was in the feed, now absent - turned off or sold in DD Pro'
					: 'VIN not in the feed - not in DD Pro, or its export is off';
			} else {
				$l    = $by_vin[ $vin ];
				$diff = array();

				$fp = $mapper->price( $l );
				if ( null !== $fp && (string) $fp !== (string) $p['price'] ) {
					$diff[] = sprintf( 'price %s->%s', (string) $p['price'], (string) $fp );
				}

				$acf = $mapper->acf_fields( $l );
				foreach ( array( 'mileage', 'warranty' ) as $f ) {
					$new = (string) ( $acf[ $f ] ?? '' );
					$old = (string) ( $p[ $f ] ?? '' );
					if ( '' !== $new && $new !== $old ) {
						$diff[] = sprintf( '%s "%s"->"%s"', $f, $old, $new );
					}
				}

				if ( ! $mapper->is_available( $l ) && 'outofstock' !== (string) $p['stock'] ) {
					$diff[] = 'should be out of stock';
				}

				if ( '' === $dd_id ) {
					$bucket = 'adoptable';
					$note   = 'VIN is in the feed but not linked - sync will adopt it';
				} elseif ( $diff ) {
					$bucket = 'drift';
					$note   = implode( '; ', $diff );
				} else {
					$bucket = 'ok';
					$note   = 'in step with the feed';
				}
			}

			++$counts[ $bucket ];

			$rows[] = array(
				'bucket'  => $bucket,
				'post_id' => (string) $pid,
				'status'  => (string) $p['post_status'],
				'vin'     => $has ? $vin : '',
				'dd_id'   => $dd_id,
				'car'     => mb_substr( (string) $p['post_title'], 0, 46 ),
				'note'    => $note,
			);
		}

		foreach ( $by_vin as $vin => $l ) {
			if ( isset( $seen[ $vin ] ) ) {
				continue;
			}
			++$counts['feed-only'];
			$rows[] = array(
				'bucket'  => 'feed-only',
				'post_id' => '',
				'status'  => '',
				'vin'     => (string) $vin,
				'dd_id'   => (string) ( $l['dd_id'] ?? '' ),
				'car'     => mb_substr( trim( sprintf( '%s %s %s', $l['year'] ?? '', $l['brand']['name'] ?? '', $mapper->model_name( $l ) ) ), 0, 46 ),
				'note'    => 'in DD Pro but not on the site - sync will create it as a draft',
			);
		}

		$order = array_flip( array_keys( self::BUCKETS ) );
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $order ): int {
				return ( $order[ $a['bucket'] ] ?? 9 ) <=> ( $order[ $b['bucket'] ] ?? 9 );
			}
		);

		return array(
			'rows'     => $rows,
			'counts'   => $counts,
			'products' => count( (array) $products ),
		);
	}
}
