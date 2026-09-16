<?php
/**
 * DD Pro feed fetch and validation.
 *
 * The feed serves no Last-Modified, ETag or Cache-Control header, so there is
 * no conditional-GET path available: every run pulls the whole document and we
 * detect per-car changes with a content hash instead. At roughly 5.7 KB per car
 * that is ~2.6 MB at 450 cars, which is acceptable.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Feed_Client {

	/**
	 * Fetch and decode the feed.
	 *
	 * @return array{listings:array<int,array<string,mixed>>,locations:array<int,array<string,mixed>>}|WP_Error
	 */
	public function fetch() {
		$url = ADCT_DDPro_Settings::feed_url();

		if ( '' === $url ) {
			return new WP_Error( 'adct_ddpro_no_url', __( 'No DD Pro feed URL is configured.', 'adct-ddpro' ) );
		}
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'adct_ddpro_bad_url', __( 'The configured DD Pro feed URL is not a valid URL.', 'adct-ddpro' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 60,
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'application/json' ),
				'user-agent'  => 'ADCT-DDPro-Sync/' . ADCT_DDPRO_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'adct_ddpro_http',
				sprintf( /* translators: %d: HTTP status code. */ __( 'DD Pro feed returned HTTP %d.', 'adct-ddpro' ), $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'adct_ddpro_empty', __( 'DD Pro feed returned an empty body.', 'adct-ddpro' ) );
		}

		$data = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error(
				'adct_ddpro_json',
				sprintf( /* translators: %s: JSON error message. */ __( 'Could not parse the DD Pro feed: %s', 'adct-ddpro' ), json_last_error_msg() )
			);
		}

		if ( ! isset( $data['listings'] ) || ! is_array( $data['listings'] ) ) {
			return new WP_Error( 'adct_ddpro_shape', __( 'DD Pro feed has no "listings" array — the feed format may have changed.', 'adct-ddpro' ) );
		}

		/*
		 * Guard against a truncated or reset feed silently emptying the
		 * catalogue. If the feed suddenly reports zero cars we refuse to act
		 * on it rather than marking every product sold.
		 */
		if ( 0 === count( $data['listings'] ) ) {
			return new WP_Error( 'adct_ddpro_no_listings', __( 'DD Pro feed contains zero listings; refusing to sync so existing products are not marked sold.', 'adct-ddpro' ) );
		}

		$feed = array(
			'listings'  => array_values( array_filter( $data['listings'], 'is_array' ) ),
			'locations' => isset( $data['locations'] ) && is_array( $data['locations'] ) ? $data['locations'] : array(),
		);

		/**
		 * Filter the decoded feed before it is processed.
		 *
		 * Exists so sold / withdrawn / unknown-status behaviour can be exercised
		 * without waiting for DD Pro to actually sell a car.
		 *
		 * @param array{listings:array<int,array<string,mixed>>,locations:array<int,array<string,mixed>>} $feed Decoded feed.
		 */
		return apply_filters( 'adct_ddpro_feed_data', $feed );
	}
}
