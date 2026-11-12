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
	}

	/**
	 * Runs migrations when the stored schema version is older than the code.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === Schema::VERSION ) {
			return;
		}

		self::install();
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
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		delete_metadata( 'user', 0, 'paydarpress_dismissed_help', '', true );
		delete_metadata( 'user', 0, 'paydarpress_ui_mode', '', true );

		Capabilities::remove();
	}
}
