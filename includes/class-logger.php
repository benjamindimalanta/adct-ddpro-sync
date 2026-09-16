<?php
/**
 * Sync log, stored in its own table so a failed import run leaves a trail.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Logger {

	/** In-memory buffer for the current run, surfaced in the admin notice. */
	private static array $run = array();

	public static function init(): void {
		self::$run = array();
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'adct_ddpro_log';
	}

	/**
	 * Create the log table.
	 */
	public static function install(): void {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			logged_at DATETIME NOT NULL,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			dd_id VARCHAR(64) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			message TEXT NOT NULL,
			PRIMARY KEY (id),
			KEY level (level),
			KEY dd_id (dd_id),
			KEY logged_at (logged_at)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Write a log line.
	 *
	 * @param string $level   info|change|warn|error.
	 * @param string $message Message.
	 * @param string $dd_id   DD Pro listing id.
	 * @param int    $post_id Product id.
	 */
	public static function log( string $level, string $message, string $dd_id = '', int $post_id = 0 ): void {
		global $wpdb;

		self::$run[] = array(
			'level'   => $level,
			'message' => $message,
			'dd_id'   => $dd_id,
			'post_id' => $post_id,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'logged_at' => current_time( 'mysql' ),
				'level'     => $level,
				'dd_id'     => $dd_id,
				'post_id'   => $post_id,
				'message'   => $message,
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		// Mirror into Simple History, which the site already runs.
		if ( in_array( $level, array( 'warn', 'error' ), true ) && function_exists( 'apply_filters' ) ) {
			do_action( 'simple_history_log', 'DD Pro Sync: ' . $message, null, 'warning' );
		}
	}

	public static function info( string $m, string $dd = '', int $p = 0 ): void {
		self::log( 'info', $m, $dd, $p );
	}

	public static function change( string $m, string $dd = '', int $p = 0 ): void {
		self::log( 'change', $m, $dd, $p );
	}

	public static function warn( string $m, string $dd = '', int $p = 0 ): void {
		self::log( 'warn', $m, $dd, $p );
	}

	public static function error( string $m, string $dd = '', int $p = 0 ): void {
		self::log( 'error', $m, $dd, $p );
	}

	/**
	 * Lines written during this request.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function run_lines(): array {
		return self::$run;
	}

	/**
	 * Recent log rows, optionally filtered to one level.
	 *
	 * @param int    $limit Row count.
	 * @param string $level Optional level filter ('change', 'error', …). '' = all.
	 * @return array<int,object>
	 */
	public static function recent( int $limit = 200, string $level = '' ): array {
		global $wpdb;
		$table = self::table();

		if ( '' !== $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d", $level, $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many change/error rows exist — the audit trail that must never be lost.
	 */
	public static function change_count(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE level IN ('change','error')" );
	}

	/**
	 * Keep the log from growing without bound now that there is no "clear" button.
	 *
	 * The noisy 'info' rows (every sync writes a started/finished pair) are pruned
	 * aggressively — only the newest are kept. The 'change' and 'error' rows are
	 * the audit trail, so they are kept far longer. Cheap: two DELETEs by id.
	 *
	 * @param int $keep_info    Newest info rows to keep.
	 * @param int $keep_changes Newest change/error rows to keep.
	 */
	public static function prune( int $keep_info = 500, int $keep_changes = 20000 ): void {
		global $wpdb;
		$table = self::table();

		// Prune info/warn noise.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cut = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE level IN ('info','warn') ORDER BY id DESC LIMIT 1 OFFSET %d",
				$keep_info
			)
		);
		if ( $cut > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE level IN ('info','warn') AND id <= %d", $cut ) );
		}

		// Prune the (much larger) change/error retention as a safety cap only.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cut = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE level IN ('change','error') ORDER BY id DESC LIMIT 1 OFFSET %d",
				$keep_changes
			)
		);
		if ( $cut > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE level IN ('change','error') AND id <= %d", $cut ) );
		}
	}
}
