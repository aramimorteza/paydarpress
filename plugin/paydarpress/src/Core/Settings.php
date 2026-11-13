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
				'gsc_min_impressions'     => 100,
			),
			'policy'      => array(
				'new_per_month'      => 8,
				'publish_per_day'    => 2,
				'refresh_share'      => 40,
				'grace_days'         => 45,
				'links_per_post_month' => 1,
				'links_per_week'     => 15,
				'words_per_link'     => 150,
				'edit_cooldown_days' => 30,
				'require_review'     => true,
				'site_stage'         => 'auto',
				'shop_focus'         => 'auto',
				'slug_style'         => 'english',
			),
			'images'      => array(
				'convert_webp'    => true,
				'delete_original' => true,
				'max_dimension'   => 1920,
				'webp_quality'    => 80,
				'min_kb'          => 30,
				'featured_ratio'  => '16:9',
				'inline_ratio'    => '4:3',
			),
			'ai'          => array(
				'monthly_articles' => 30,
				'monthly_requests' => 300,
				'max_output_tokens' => 8000,
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
	 * Bounds for numeric settings: path => [min, max].
	 */
	public const LIMITS = array(
		'performance.batch_size'           => array( 5, 200 ),
		'performance.batch_time_limit'     => array( 5, 60 ),
		'performance.quiet_hours_start'    => array( 0, 23 ),
		'performance.quiet_hours_end'      => array( 0, 23 ),
		'audit.thin_words'                 => array( 50, 3000 ),
		'audit.weak_category_min'          => array( 1, 50 ),
		'audit.tag_overuse_max'            => array( 2, 50 ),
		'audit.weak_inbound_min'           => array( 1, 20 ),
		'audit.inline_images_min_words'    => array( 200, 5000 ),
		'audit.stale_months'               => array( 3, 60 ),
		'audit.gsc_min_impressions'        => array( 10, 100000 ),
		'retention.activity_days'          => array( 7, 365 ),
		'policy.new_per_month'             => array( 0, 500 ),
		'policy.publish_per_day'           => array( 1, 50 ),
		'policy.refresh_share'             => array( 0, 100 ),
		'policy.grace_days'                => array( 0, 180 ),
		'policy.links_per_post_month'      => array( 1, 10 ),
		'policy.links_per_week'            => array( 1, 500 ),
		'policy.words_per_link'            => array( 50, 1000 ),
		'policy.edit_cooldown_days'        => array( 0, 365 ),
		'images.max_dimension'             => array( 800, 4000 ),
		'images.webp_quality'              => array( 50, 95 ),
		'images.min_kb'                    => array( 0, 2000 ),
		'ai.monthly_articles'              => array( 0, 1000 ),
		'ai.monthly_requests'              => array( 0, 10000 ),
		'ai.max_output_tokens'             => array( 1000, 64000 ),
	);

	/** Allowed values of text settings: path => list. */
	public const CHOICES = array(
		'policy.site_stage' => array( 'auto', 'new', 'growing', 'mature' ),
		'policy.shop_focus' => array( 'auto', 'product', 'category', 'mixed' ),
		'policy.slug_style' => array( 'english', 'persian' ),
		'images.featured_ratio' => array( '16:9', '3:2', '4:3', '1:1' ),
		'images.inline_ratio'   => array( '16:9', '3:2', '4:3', '1:1' ),
	);

	/**
	 * Keeps only known keys, casts each value to the default's type and clamps numbers.
	 *
	 * @param array $input Untrusted input (same shape as defaults()).
	 * @return array Sanitized partial settings.
	 */
	public static function sanitize( array $input ): array {
		$out = array();

		foreach ( self::defaults() as $group => $fields ) {
			if ( ! isset( $input[ $group ] ) || ! is_array( $input[ $group ] ) ) {
				continue;
			}
			foreach ( $fields as $key => $default ) {
				if ( ! array_key_exists( $key, $input[ $group ] ) ) {
					continue;
				}
				$value = $input[ $group ][ $key ];
				$path  = $group . '.' . $key;

				if ( is_bool( $default ) ) {
					$out[ $group ][ $key ] = (bool) rest_sanitize_boolean( $value );
				} elseif ( is_int( $default ) ) {
					$value = (int) $value;
					if ( isset( self::LIMITS[ $path ] ) ) {
						$value = max( self::LIMITS[ $path ][0], min( self::LIMITS[ $path ][1], $value ) );
					}
					$out[ $group ][ $key ] = $value;
				} elseif ( is_string( $default ) ) {
					$value                 = (string) preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( (string) $value ) );
					$out[ $group ][ $key ] = in_array( $value, self::CHOICES[ $path ] ?? array( $default ), true ) ? $value : $default;
				} elseif ( is_array( $default ) ) {
					// Only list of IDs is supported (orphan_exclude_ids).
					$ids = is_array( $value ) ? $value : preg_split( '/[\s,،]+/u', (string) $value );
					$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
					$out[ $group ][ $key ] = array_slice( $ids, 0, 500 );
				}
			}
		}

		return $out;
	}

	/**
	 * Replaces list values instead of merging them (used by update()).
	 *
	 * @param array $settings Sanitized partial settings.
	 */
	public static function replace( array $settings ): void {
		$all = self::all();
		foreach ( $settings as $group => $fields ) {
			foreach ( $fields as $key => $value ) {
				$all[ $group ][ $key ] = $value;
			}
		}

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $all, '', false );
			return;
		}

		update_option( self::OPTION, $all, false );
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
