<?php
/**
 * Activation, deactivation and migrations.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Database;

use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades tables, registers capabilities and default options.
 */
final class Installer {

	public const DB_VERSION_OPTION = 'paydarpress_db_version';

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install();

		if ( false === get_option( 'paydarpress_onboarding', false ) ) {
			add_option(
				'paydarpress_onboarding',
				array(
					'activated_at' => gmdate( 'Y-m-d H:i:s' ),
					'steps'        => array(),
				),
				'',
				false
			);
		}

		// Opens the welcome screen once after activation (handled by AdminPage).
		set_transient( 'paydarpress_activation_redirect', 1, 60 );
	}

	/**
	 * Deactivation hook. Never deletes data; only unschedules background work.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'paydarpress' );
		}
		wp_clear_scheduled_hook( 'paydarpress_license_check' );
	}

	/**
	 * Runs migrations when the stored schema version is older than the code.
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );

		if ( Schema::VERSION === $installed ) {
			return;
		}

		self::install();
		self::migrate( $installed );
	}

	/**
	 * Data migrations between schema versions (table changes are handled by dbDelta).
	 *
	 * @param string $from Previously installed schema version ('' on fresh installs).
	 */
	private static function migrate( string $from ): void {
		global $wpdb;

		// dbDelta can silently skip ALTER TABLE on some hosts/database layers; verify columns.
		self::ensure_column( 'inventory', 'images_generic_alt', 'smallint(5) unsigned NOT NULL DEFAULT 0' );
		self::ensure_column( 'link_suggestions', 'note', 'text DEFAULT NULL' );
		self::ensure_column( 'link_suggestions', 'inserted', 'text DEFAULT NULL' );
		self::ensure_column( 'link_suggestions', 'matched_text', 'varchar(255) DEFAULT NULL' );
		self::ensure_column( 'link_suggestions', 'applied_at', 'datetime DEFAULT NULL' );
		self::ensure_column( 'changes', 'reason', 'text DEFAULT NULL' );
		self::ensure_column( 'changes', 'history', 'text DEFAULT NULL' );
		self::ensure_column( 'changes', 'measurable', 'tinyint(1) NOT NULL DEFAULT 1' );

		if ( '' !== $from && version_compare( $from, '1.1.0', '<' ) ) {
			// New derived columns: force the next scan to reprocess every item.
			$table = Schema::table( 'inventory' );
			$wpdb->query( "UPDATE {$table} SET content_hash = ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Creates or updates tables and options. Safe to run repeatedly.
	 */
	private static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements() as $sql ) {
			dbDelta( $sql );
		}

		Capabilities::add();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		update_option( self::DB_VERSION_OPTION, Schema::VERSION, true );
	}

	/**
	 * Adds a column if it is missing.
	 *
	 * @param string $table      Short table name.
	 * @param string $column     Column name.
	 * @param string $definition Column definition.
	 */
	private static function ensure_column( string $table, string $column, string $definition ): void {
		global $wpdb;

		$full    = Schema::table( $table );
		$columns = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$full}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( array() !== $columns && ! in_array( strtolower( $column ), $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$full} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Removes all PaydarPress data. Called only from uninstall.php when the user opted in.
	 */
	public static function uninstall(): void {
		global $wpdb;

		foreach ( Schema::TABLES as $name ) {
			$table = Schema::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}

		$options = array(
			self::DB_VERSION_OPTION,
			Settings::OPTION,
			'paydarpress_profile',
			'paydarpress_stats',
			'paydarpress_onboarding',
			'paydarpress_last_export',
			'paydarpress_crawlers',
			'paydarpress_scan_cursor',
			'paydarpress_ai',
			'paydarpress_settling',
			'paydarpress_structure_plan',
			'paydarpress_tasks',
			'paydarpress_webp_stats',
			'paydarpress_webp_bulk',
			'paydarpress_license',
			'paydarpress_term_flags',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}
		delete_site_transient( 'paydarpress_update' );

		// Post meta written by ContentMaster imports (the drafts themselves are the user's content and stay).
		foreach ( array( '_paydarpress_import_id', '_paydarpress_image_prompt', '_paydarpress_image_alt', '_paydarpress_faq', '_paydarpress_seo' ) as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}

		delete_metadata( 'user', 0, 'paydarpress_dismissed_help', '', true );
		delete_metadata( 'user', 0, 'paydarpress_ui_mode', '', true );

		// Export files.
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'paydarpress';
		if ( is_dir( $dir ) ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : wp_delete_file( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		Capabilities::remove();
	}
}
