<?php
/**
 * Settings access.
 *
 * The feed URL contains an unauthenticated access token, so anyone holding the
 * URL can read the full stock list including VINs. Prefer defining it as a
 * constant in wp-config.php, which keeps it out of the database and out of any
 * database export committed to the backup repo:
 *
 *   define( 'ADCT_DDPRO_FEED_URL', 'https://services.deal-drive.com/feeds/ddexport/....json' );
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Settings {

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'          => false,
			'feed_url'         => '',
			'new_post_status'  => 'draft',
			'gate_publish'     => true,
			'sold_policy'      => 'outofstock',
			'cars_per_run'     => 25,
			'photos_per_run'   => 20,
			'attach_photos'    => true,
			'vin_match_action' => 'adopt',
			'adopt_fill'       => 'empty',
			'adopt_photos'     => 'skip',
			'manage_tags'      => true,
			'sold_tag'         => 'Sold Cars',
			'available_tag'    => 'Available Cars',
			'condition_used'   => 'Pre-Owned',
			'condition_new'    => 'Brand New',
			'default_location' => 'Dubai, United Arab Emirates',
			// Fields filled on every newly imported car so it matches a hand-made product.
			'default_category'  => 'Luxury Cars',
			'default_video_url' => 'https://youtu.be/q0eFLgdPdQY',
			'contact_link'      => 'https://g.page/autodealsdubai?gm',
			'assign_agents'     => true,
			// Hold a no-VIN car for review when it fuzzily matches an existing product.
			'review_fuzzy'      => true,
			// Max photos to import per car (0 = all).
			'photos_per_car'    => 0,
			// Auto-link a no-VIN car when brand+year+price+mileage+colour all match exactly.
			'auto_match_specs'  => true,
			// Keep the product TITLE in sync with DD Pro's headline (new + existing cars).
			'sync_title'        => true,
			// Keep the SHORT description in sync with DD Pro (new + existing cars).
			'sync_short_desc'   => true,
			// DD Pro headlines up to this many characters are used as the title
			// verbatim; longer ones are reduced to the template. 0 = always reduce.
			'title_max_length'  => 100,
		);
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$saved = get_option( ADCT_DDPRO_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Feed URL, preferring the wp-config constant over the stored option.
	 */
	public static function feed_url(): string {
		if ( defined( 'ADCT_DDPRO_FEED_URL' ) && is_string( constant( 'ADCT_DDPRO_FEED_URL' ) ) ) {
			return trim( (string) constant( 'ADCT_DDPRO_FEED_URL' ) );
		}
		return trim( (string) self::get( 'feed_url' ) );
	}

	/**
	 * True when the URL comes from wp-config rather than the database.
	 */
	public static function feed_url_is_constant(): bool {
		return defined( 'ADCT_DDPRO_FEED_URL' ) && '' !== trim( (string) constant( 'ADCT_DDPRO_FEED_URL' ) );
	}

	/**
	 * Persist a settings array after sanitising.
	 *
	 * @param array<string,mixed> $input Raw input.
	 */
	public static function save( array $input ): void {
		$clean = array(
			'enabled'          => ! empty( $input['enabled'] ),
			'attach_photos'    => ! empty( $input['attach_photos'] ),
			'gate_publish'     => ! empty( $input['gate_publish'] ),
			'feed_url'         => esc_url_raw( trim( (string) ( $input['feed_url'] ?? '' ) ) ),
			'new_post_status'  => in_array( $input['new_post_status'] ?? '', array( 'draft', 'pending', 'publish' ), true )
				? (string) $input['new_post_status'] : 'draft',
			'sold_policy'      => in_array( $input['sold_policy'] ?? '', array( 'outofstock', 'draft', 'ignore' ), true )
				? (string) $input['sold_policy'] : 'outofstock',
			'cars_per_run'     => max( 1, min( 200, (int) ( $input['cars_per_run'] ?? 25 ) ) ),
			'photos_per_run'   => max( 1, min( 200, (int) ( $input['photos_per_run'] ?? 20 ) ) ),
			'vin_match_action' => in_array( $input['vin_match_action'] ?? '', array( 'adopt', 'skip' ), true )
				? (string) $input['vin_match_action'] : 'adopt',
			'adopt_fill'       => in_array( $input['adopt_fill'] ?? '', array( 'empty', 'overwrite', 'none' ), true )
				? (string) $input['adopt_fill'] : 'empty',
			'adopt_photos'     => in_array( $input['adopt_photos'] ?? '', array( 'skip', 'append' ), true )
				? (string) $input['adopt_photos'] : 'skip',
			'manage_tags'      => ! empty( $input['manage_tags'] ),
			'sold_tag'         => sanitize_text_field( (string) ( $input['sold_tag'] ?? 'Sold Cars' ) ),
			'available_tag'    => sanitize_text_field( (string) ( $input['available_tag'] ?? 'Available Cars' ) ),
			'condition_used'   => sanitize_text_field( (string) ( $input['condition_used'] ?? 'Pre-Owned' ) ),
			'condition_new'    => sanitize_text_field( (string) ( $input['condition_new'] ?? 'Brand New' ) ),
			'default_location' => sanitize_text_field( (string) ( $input['default_location'] ?? '' ) ),
			'default_category'  => sanitize_text_field( (string) ( $input['default_category'] ?? '' ) ),
			'default_video_url' => esc_url_raw( trim( (string) ( $input['default_video_url'] ?? '' ) ) ),
			'contact_link'      => esc_url_raw( trim( (string) ( $input['contact_link'] ?? '' ) ) ),
			'assign_agents'     => ! empty( $input['assign_agents'] ),
			'review_fuzzy'      => ! empty( $input['review_fuzzy'] ),
			'photos_per_car'    => max( 0, min( 100, (int) ( $input['photos_per_car'] ?? 0 ) ) ),
			'auto_match_specs'  => ! empty( $input['auto_match_specs'] ),
			'sync_title'        => ! empty( $input['sync_title'] ),
			'sync_short_desc'   => ! empty( $input['sync_short_desc'] ),
			'title_max_length'  => max( 0, min( 300, (int) ( $input['title_max_length'] ?? 100 ) ) ),
		);

		update_option( ADCT_DDPRO_OPTION, $clean );
	}
}
