<?php
/**
 * Plugin Name:       ADCT DD Pro Sync
 * Plugin URI:        https://github.com/benjamindimalanta/adct-ddpro-sync
 * Description:       Imports vehicle listings from the DD Pro (Deal Drive) JSON feed into WooCommerce products, syncing specs, price, photos, titles and short descriptions while leaving the long description and Yoast SEO fields untouched.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Benjamin Clar
 * Author URI:        https://github.com/benjamindimalanta
 * Text Domain:       adct-ddpro
 *
 * Design note — field ownership
 * -----------------------------
 * The feed owns structured data: specs, price, mileage, status, photos, and —
 * by owner choice — the product title and the short description (both derived
 * from DD Pro's headline; see the sync_title / sync_short_desc settings). The
 * LONG description (post_content) and every Yoast SEO field stay human-owned and
 * are NEVER written on an update, and the page slug (post_name) is never changed
 * so URLs and rankings are preserved. That split is the whole point of the
 * plugin: the long body copy and Yoast fields are what earn rankings.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ADCT_DDPRO_VERSION', '1.0.0' );
define( 'ADCT_DDPRO_FILE', __FILE__ );
define( 'ADCT_DDPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADCT_DDPRO_SLUG', 'adct-ddpro-sync' );

/** Option key holding all settings. */
define( 'ADCT_DDPRO_OPTION', 'adct_ddpro_settings' );

/** Post meta keys owned by this plugin. */
define( 'ADCT_DDPRO_META_ID', '_ddpro_id' );
define( 'ADCT_DDPRO_META_HASH', '_ddpro_hash' );
define( 'ADCT_DDPRO_META_PHOTOS', '_ddpro_photo_map' );
define( 'ADCT_DDPRO_META_QUEUE', '_ddpro_photo_queue' );
define( 'ADCT_DDPRO_META_SYNCED', '_ddpro_last_synced' );
define( 'ADCT_DDPRO_META_LOCATION', '_ddpro_location_id' );
define( 'ADCT_DDPRO_META_GONE_SINCE', '_ddpro_unavailable_since' );
define( 'ADCT_DDPRO_META_GONE_REASON', '_ddpro_unavailable_reason' );

/** Set on a draft that should auto-publish once it is complete (photos + price + specs). */
define( 'ADCT_DDPRO_META_PENDING', '_ddpro_pending_publish' );

/** '1' on products this plugin CREATED (vs adopted an existing product). */
define( 'ADCT_DDPRO_META_CREATED', '_ddpro_created' );

/** WooCommerce's GTIN/UPC/EAN/ISBN field — we mirror the dd_id here as a visible key. */
define( 'ADCT_DDPRO_META_GTIN', '_global_unique_id' );

/** Cron hooks. */
define( 'ADCT_DDPRO_CRON_SYNC', 'adct_ddpro_cron_sync' );
define( 'ADCT_DDPRO_CRON_PHOTOS', 'adct_ddpro_cron_photos' );

require_once ADCT_DDPRO_DIR . 'includes/class-logger.php';
require_once ADCT_DDPRO_DIR . 'includes/class-settings.php';
require_once ADCT_DDPRO_DIR . 'includes/class-feed-client.php';
require_once ADCT_DDPRO_DIR . 'includes/class-mapper.php';
require_once ADCT_DDPRO_DIR . 'includes/class-importer.php';
require_once ADCT_DDPRO_DIR . 'includes/class-reconciler.php';
require_once ADCT_DDPRO_DIR . 'includes/class-auditor.php';
require_once ADCT_DDPRO_DIR . 'includes/class-admin.php';
require_once ADCT_DDPRO_DIR . 'includes/class-review.php';
require_once ADCT_DDPRO_DIR . 'includes/class-quick-edit.php';
require_once ADCT_DDPRO_DIR . 'includes/class-cli.php';

/**
 * Bootstrap.
 */
function adct_ddpro_init(): void {
	ADCT_DDPro_Logger::init();
	( new ADCT_DDPro_Admin() )->hooks();
	( new ADCT_DDPro_Review() )->hooks();
	( new ADCT_DDPro_Quick_Edit() )->hooks();

	add_action( ADCT_DDPRO_CRON_SYNC, 'adct_ddpro_run_scheduled_sync' );
	add_action( ADCT_DDPRO_CRON_PHOTOS, 'adct_ddpro_run_photo_queue' );

	/*
	 * Keep the sync on the 3-minute schedule. The feed is ~1 MB and fetches in a
	 * couple of seconds, so a 3-minute check is cheap. This also migrates older
	 * installs that were on a different schedule without needing a manual
	 * reactivation — it only touches cron when the schedule is actually wrong.
	 */
	if ( wp_next_scheduled( ADCT_DDPRO_CRON_SYNC ) && 'adct_ddpro_three_minutes' !== wp_get_schedule( ADCT_DDPRO_CRON_SYNC ) ) {
		wp_clear_scheduled_hook( ADCT_DDPRO_CRON_SYNC );
		wp_schedule_event( time() + 60, 'adct_ddpro_three_minutes', ADCT_DDPRO_CRON_SYNC );
	}
}
add_action( 'plugins_loaded', 'adct_ddpro_init' );

/**
 * Scheduled full sync.
 */
function adct_ddpro_run_scheduled_sync(): void {
	if ( ! ADCT_DDPro_Settings::get( 'enabled' ) ) {
		ADCT_DDPro_Logger::info( 'Scheduled sync skipped: plugin is disabled in settings.' );
		return;
	}
	( new ADCT_DDPro_Importer() )->run( false );
}

/**
 * Scheduled photo queue drain. Images are the heavy part of this integration
 * (25 photos x ~190 KB per car), so they are pulled in small batches on their
 * own schedule rather than inside the listing sync.
 */
function adct_ddpro_run_photo_queue(): void {
	if ( ! ADCT_DDPro_Settings::get( 'enabled' ) ) {
		return;
	}
	( new ADCT_DDPro_Importer() )->drain_photo_queue();
}

/**
 * Activation: create the log table and schedule cron.
 */
function adct_ddpro_activate(): void {
	ADCT_DDPro_Logger::install();

	if ( ! wp_next_scheduled( ADCT_DDPRO_CRON_SYNC ) ) {
		wp_schedule_event( time() + 120, 'adct_ddpro_three_minutes', ADCT_DDPRO_CRON_SYNC );
	}
	if ( ! wp_next_scheduled( ADCT_DDPRO_CRON_PHOTOS ) ) {
		wp_schedule_event( time() + 120, 'adct_ddpro_five_minutes', ADCT_DDPRO_CRON_PHOTOS );
	}
}
register_activation_hook( __FILE__, 'adct_ddpro_activate' );

/**
 * Deactivation: clear cron. Data and logs are left in place.
 */
function adct_ddpro_deactivate(): void {
	wp_clear_scheduled_hook( ADCT_DDPRO_CRON_SYNC );
	wp_clear_scheduled_hook( ADCT_DDPRO_CRON_PHOTOS );
}
register_deactivation_hook( __FILE__, 'adct_ddpro_deactivate' );

/**
 * Custom five-minute schedule for the photo queue.
 *
 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
 * @return array<string,array{interval:int,display:string}>
 */
function adct_ddpro_cron_schedules( array $schedules ): array {
	$schedules['adct_ddpro_three_minutes'] = array(
		'interval' => 3 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 3 minutes (DD Pro sync)', 'adct-ddpro' ),
	);
	$schedules['adct_ddpro_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 minutes (DD Pro photos)', 'adct-ddpro' ),
	);
	$schedules['adct_ddpro_fifteen_minutes'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 15 minutes', 'adct-ddpro' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'adct_ddpro_cron_schedules' );
