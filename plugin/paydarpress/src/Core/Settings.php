<?php
/**
 * Plugin settings with defaults.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the `paydarpress_settings` option (autoload: no).
 */
final class Settings {

	public const OPTION = 'paydarpress_settings';

	/**
	 * Default settings. See docs/phase1-spec.md sections 5.2 and 7.
	 */
	public static function defaults(): array {
		return array(
			'performance' => array(
				'shared_hosting'    => false,
				'batch_size'        => 50,
				'batch_time_limit'  => 20,
				'quiet_hours_only'  => false,
				'quiet_hours_start' => 1,
				'quiet_hours_end'   => 6,
			),
			'audit'       => array(
				'thin_words'              => 300,
				'weak_category_min'       => 3,
				'tag_overuse_max'         => 8,
				'weak_inbound_min'        => 3,
				'inline_images_min_words' => 800,
				'stale_months'            => 18,
				'orphan_exclude_ids'      => array(),
			),
			'retention'   => array(
				'activity_days' => 90,
			),
			'ui'          => array(
				'font_everywhere'  => false,
				'dashboard_widget' => true,
			),
			'uninstall'   => array(
				'delete_data' => false,
			),
		);
	}

	/**
	 * Returns all settings merged with defaults.
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::merge( self::defaults(), $stored );
	}

	/**
	 * Returns a setting by dot path, e.g. `audit.thin_words`.
	 *
	 * @param string $path    Dot-separated path.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( string $path, $default = null ) {
		$value = self::all();

		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $default;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Saves settings (merged over current values).
	 *
	 * @param array $settings Partial settings.
	 */
	public static function update( array $settings ): void {
		$merged = self::merge( self::all(), $settings );

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $merged, '', false );
			return;
		}

		update_option( self::OPTION, $merged, false );
	}

	/**
	 * Recursive merge where scalar values and lists in $override replace those in $base.
	 *
	 * @param array $base     Base values.
	 * @param array $override Override values.
	 */
	private static function merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * PHP 8.0-compatible replacement for array_is_list() (PHP 8.1+).
	 *
	 * @param array $value Array to test.
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
