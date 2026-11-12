<?php
/**
 * Converts uploaded PNG and JPEG images to WebP (resized, metadata stripped) and removes the
 * original, so AI-generated PNGs of several megabytes do not slow the site down.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Images;

use PaydarWP\PaydarPress\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on upload (media library, block editor, sideload) before WordPress creates sub-sizes,
 * so every size is generated from the WebP file.
 */
final class WebpConverter {

	public const STATS = 'paydarpress_webp_stats';

	private const MIMES = array( 'image/png', 'image/jpeg' );

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'wp_handle_upload', array( $this, 'on_upload' ), 20, 2 );
		add_filter( 'wp_handle_sideload', array( $this, 'on_upload' ), 20, 2 );
	}

	/**
	 * Whether the server can write WebP.
	 */
	public static function supported(): bool {
		if ( ! function_exists( 'wp_image_editor_supports' ) ) {
			require_once ABSPATH . WPINC . '/media.php';
		}
		return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	/**
	 * Upload filter.
	 *
	 * @param array  $upload  file, url, type.
	 * @param string $context upload|sideload.
	 * @return array
	 */
	public function on_upload( $upload, $context = 'upload' ) {
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || ! in_array( $upload['type'] ?? '', self::MIMES, true ) ) {
			return $upload;
		}
		if ( ! (bool) Settings::get( 'images.convert_webp', true ) || ! self::supported() ) {
			return $upload;
		}
		$before = (int) @filesize( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $before < (int) Settings::get( 'images.min_kb', 30 ) * 1024 ) {
			return $upload;
		}

		$result = self::convert( $upload['file'] );
		if ( null === $result ) {
			return $upload;
		}

		if ( (bool) Settings::get( 'images.delete_original', true ) ) {
			wp_delete_file( $upload['file'] );
		}
		self::count( $before, $result['size'] );

		$upload['file'] = $result['path'];
		$upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . rawurlencode( wp_basename( $result['path'] ) );
		$upload['type'] = 'image/webp';
		return $upload;
	}

	/**
	 * Converts one file to WebP next to it.
	 *
	 * @param string $path    Source file.
	 * @param bool   $require_smaller Return null when the WebP is not smaller.
	 * @return array{path: string, size: int, width: int, height: int}|null
	 */
	public static function convert( string $path, bool $require_smaller = true ): ?array {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		$max  = max( 600, (int) Settings::get( 'images.max_dimension', 1920 ) );
		$size = $editor->get_size();
		if ( ( $size['width'] ?? 0 ) > $max || ( $size['height'] ?? 0 ) > $max ) {
			$editor->resize( $max, $max, false );
		}
		$editor->set_quality( max( 40, min( 95, (int) Settings::get( 'images.webp_quality', 80 ) ) ) );

		$dir    = dirname( $path );
		$name   = wp_unique_filename( $dir, pathinfo( $path, PATHINFO_FILENAME ) . '.webp' );
		$saved  = $editor->save( trailingslashit( $dir ) . $name, 'image/webp' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return null;
		}
		$after = (int) @filesize( $saved['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $require_smaller && $after >= (int) @filesize( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $saved['path'] );
			return null;
		}
		return array(
			'path'   => $saved['path'],
			'size'   => $after,
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
		);
	}

	/**
	 * Adds to the savings counter.
	 *
	 * @param int $before Bytes before.
	 * @param int $after  Bytes after.
	 */
	public static function count( int $before, int $after ): void {
		$s = get_option( self::STATS, array() );
		$s = is_array( $s ) ? $s : array();
		update_option(
			self::STATS,
			array(
				'files'  => (int) ( $s['files'] ?? 0 ) + 1,
				'before' => (int) ( $s['before'] ?? 0 ) + $before,
				'after'  => (int) ( $s['after'] ?? 0 ) + $after,
			),
			false
		);
	}
}
