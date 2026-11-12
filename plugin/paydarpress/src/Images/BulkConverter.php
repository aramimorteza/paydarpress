<?php
/**
 * Careful conversion of existing PNG/JPEG images to WebP: background batches, every address
 * in post content (and Elementor data) updated, originals kept until the owner has checked the
 * site, and a full restore until then.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Images;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Queue\Queue;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Per attachment, the backup meta keeps the old file, metadata, MIME type, the address map
 * and the posts that were changed, so a restore can undo everything.
 */
final class BulkConverter {

	public const STATE  = 'paydarpress_webp_bulk';
	public const BACKUP = '_paydarpress_webp_backup';
	public const SKIP   = '_paydarpress_webp_skip';
	public const ACTION = 'paydarpress/images/convert_batch';

	private const BATCH = 5;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( self::ACTION, array( $this, 'batch' ) );
	}

	/**
	 * What a conversion would do.
	 *
	 * @return array{count: int, bytes: int, estimate: int, converted: int, pending_cleanup: int, state: array}
	 */
	public static function report(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/png','image/jpeg') AND s.meta_id IS NULL LIMIT 5000",
				self::SKIP
			)
		);
		$cleanup = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP ) );
		// phpcs:enable
		$bytes = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$file   = get_attached_file( $id );
			$bytes += $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
		}
		return array(
			'count'           => count( (array) $ids ),
			'bytes'           => $bytes,
			// WebP at quality 80 is typically 60–80% smaller than PNG and 25–35% smaller than JPEG.
			'estimate'        => (int) round( $bytes * 0.6 ),
			'pending_cleanup' => $cleanup,
			'state'           => self::state(),
		);
	}

	/**
	 * Current run.
	 */
	public static function state(): array {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Starts converting.
	 */
	public static function start(): void {
		$report = self::report();
		update_option(
			self::STATE,
			array(
				'running'    => true,
				'total'      => $report['count'],
				'done'       => 0,
				'skipped'    => 0,
				'failed'     => 0,
				'posts'      => 0,
				'before'     => 0,
				'after'      => 0,
				'started_at' => gmdate( 'c' ),
				'errors'     => array(),
			),
			false
		);
		Queue::schedule( self::ACTION, array(), 1 );
		Activity::log( 'settings.webp_bulk', sprintf( 'تبدیل %s تصویر قدیمی به WebP شروع شد.', PersianNormalizer::fa_number( $report['count'] ) ) );
	}

	/**
	 * Stops after the current batch.
	 */
	public static function stop(): void {
		$s            = self::state();
		$s['running'] = false;
		update_option( self::STATE, $s, false );
	}

	/**
	 * Background step.
	 */
	public function batch(): void {
		global $wpdb;
		$s = self::state();
		if ( empty( $s['running'] ) ) {
			return;
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/png','image/jpeg') AND s.meta_id IS NULL ORDER BY p.ID LIMIT %d",
				self::SKIP,
				self::BATCH
			)
		);
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$r = self::convert_one( $id );
			if ( true === $r['ok'] ) {
				++$s['done'];
				$s['before'] += $r['before'];
				$s['after']  += $r['after'];
				$s['posts']  += $r['posts'];
			} elseif ( 'skip' === $r['ok'] ) {
				++$s['skipped'];
			} else {
				++$s['failed'];
				if ( count( $s['errors'] ) < 20 ) {
					$s['errors'][] = '#' . $id . ': ' . $r['error'];
				}
			}
		}
		if ( count( (array) $ids ) < self::BATCH ) {
			$s['running']     = false;
			$s['finished_at'] = gmdate( 'c' );
			Activity::log( 'settings.webp_bulk', sprintf( 'تبدیل تصاویر قدیمی تمام شد: %s تصویر، %s نوشته به‌روز شد.', PersianNormalizer::fa_number( $s['done'] ), PersianNormalizer::fa_number( $s['posts'] ) ) );
		}
		update_option( self::STATE, $s, false );
		if ( $s['running'] ) {
			Queue::schedule( self::ACTION, array(), 2 );
		}
	}

	/**
	 * Converts one attachment and updates every address that points to it.
	 *
	 * @param int $id Attachment.
	 * @return array{ok: bool|string, before?: int, after?: int, posts?: int, error?: string}
	 */
	public static function convert_one( int $id ): array {
		$file = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		$mime = (string) get_post_mime_type( $id );
		if ( ! $file || ! file_exists( $file ) || ! is_array( $meta ) ) {
			update_post_meta( $id, self::SKIP, 'missing' );
			return array( 'ok' => 'skip' );
		}
		// WordPress "-scaled" copies: convert from the real original when it still exists.
		$source = ! empty( $meta['original_image'] ) && file_exists( trailingslashit( dirname( $file ) ) . $meta['original_image'] ) ? trailingslashit( dirname( $file ) ) . $meta['original_image'] : $file;
		$before = (int) filesize( $file );

		$webp = WebpConverter::convert( $source, false );
		if ( null === $webp ) {
			update_post_meta( $id, self::SKIP, 'failed' );
			return array( 'ok' => false, 'error' => 'ساخت WebP ممکن نشد.' );
		}
		if ( $webp['size'] >= $before ) {
			wp_delete_file( $webp['path'] );
			update_post_meta( $id, self::SKIP, 'larger' );
			return array( 'ok' => 'skip' );
		}

		$old_paths = self::paths( $file, $meta );
		update_attached_file( $id, $webp['path'] );
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => 'image/webp',
			)
		);
		$new_meta = wp_generate_attachment_metadata( $id, $webp['path'] );
		wp_update_attachment_metadata( $id, $new_meta );
		$new_paths = self::paths( $webp['path'], is_array( $new_meta ) ? $new_meta : array() );

		// Old address → new address (same size name; otherwise the full image).
		$map = array();
		foreach ( $old_paths as $size => $old ) {
			$map[ $old ] = $new_paths[ $size ] ?? $new_paths['full'];
		}
		$posts = self::replace_in_content( $map );

		update_post_meta(
			$id,
			self::BACKUP,
			array(
				'file'  => $file,
				'meta'  => $meta,
				'mime'  => $mime,
				'map'   => $map,
				'posts' => $posts,
				'new'   => array_values( array_unique( $new_paths ) ),
				'at'    => gmdate( 'c' ),
			)
		);
		update_post_meta( $id, self::SKIP, 'converted' );
		WebpConverter::count( $before, (int) $webp['size'] );

		return array(
			'ok'     => true,
			'before' => $before,
			'after'  => (int) $webp['size'],
			'posts'  => count( $posts ),
		);
	}

	/**
	 * Uploads-relative paths of the file and its sizes, keyed by size name.
	 *
	 * @param string $file Full path.
	 * @param array  $meta Metadata.
	 * @return array<string, string>
	 */
	private static function paths( string $file, array $meta ): array {
		$base = trailingslashit( wp_get_upload_dir()['basedir'] );
		$rel  = ltrim( str_replace( $base, '', $file ), '/' );
		$dir  = dirname( $rel );
		$dir  = '.' === $dir ? '' : $dir . '/';
		$out  = array( 'full' => $rel );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$out[ $name ] = $dir . $size['file'];
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$out['original_image'] = $dir . $meta['original_image'];
		}
		return $out;
	}

	/**
	 * Replaces uploads-relative paths in post content and Elementor data.
	 *
	 * @param array<string, string> $map Old → new relative path.
	 * @return int[] Changed post ids.
	 */
	private static function replace_in_content( array $map ): array {
		global $wpdb;
		$map = array_filter( $map, static fn( $new, $old ) => $new !== $old, ARRAY_FILTER_USE_BOTH );
		if ( ! $map ) {
			return array();
		}
		uksort( $map, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		// The file name without extension narrows the search to posts that can contain it.
		$needle  = '%' . $wpdb->esc_like( pathinfo( (string) array_key_last( $map ), PATHINFO_FILENAME ) ) . '%';
		$changed = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_content LIKE %s", $needle ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$new = strtr( (string) $row['post_content'], $map );
			if ( $new !== $row['post_content'] ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row['ID'] ) );
				clean_post_cache( (int) $row['ID'] );
				$changed[] = (int) $row['ID'];
			}
		}

		// Elementor stores JSON with escaped slashes.
		$escaped = array();
		foreach ( $map as $old => $new ) {
			$escaped[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
			$escaped[ $old ]                            = $new;
		}
		$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s", $needle ), ARRAY_A );
		foreach ( (array) $meta_rows as $row ) {
			$new = strtr( (string) $row['meta_value'], $escaped );
			if ( $new !== $row['meta_value'] ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'post_id' => (int) $row['post_id'], 'meta_key' => '_elementor_data' ) );
				delete_post_meta( (int) $row['post_id'], '_elementor_css' );
				wp_cache_delete( (int) $row['post_id'], 'post_meta' );
				$changed[] = (int) $row['post_id'];
			}
		}
		// phpcs:enable
		return array_values( array_unique( $changed ) );
	}

	/**
	 * Undoes every conversion that still has a backup.
	 *
	 * @return int Restored attachments.
	 */
	public static function restore_all(): int {
		global $wpdb;
		self::stop();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP ) );
		$n   = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$b = get_post_meta( $id, self::BACKUP, true );
			if ( ! is_array( $b ) || empty( $b['file'] ) || ! file_exists( $b['file'] ) ) {
				continue;
			}
			self::replace_in_content( array_flip( $b['map'] ) );
			update_attached_file( $id, $b['file'] );
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => $b['mime'],
				)
			);
			wp_update_attachment_metadata( $id, $b['meta'] );
			$base = trailingslashit( wp_get_upload_dir()['basedir'] );
			foreach ( (array) ( $b['new'] ?? array() ) as $rel ) {
				if ( preg_match( '/\.webp$/i', $rel ) ) {
					wp_delete_file( $base . $rel );
				}
			}
			delete_post_meta( $id, self::BACKUP );
			delete_post_meta( $id, self::SKIP );
			++$n;
		}
		Activity::log( 'settings.webp_bulk', sprintf( 'تبدیل WebP برای %s تصویر برگردانده شد.', PersianNormalizer::fa_number( $n ) ) );
		return $n;
	}

	/**
	 * Deletes the old PNG/JPEG files after the owner has checked the site (no restore after this).
	 *
	 * @return int Files deleted.
	 */
	public static function delete_originals(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP ) );
		$base = trailingslashit( wp_get_upload_dir()['basedir'] );
		$n    = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$b = get_post_meta( $id, self::BACKUP, true );
			if ( ! is_array( $b ) ) {
				continue;
			}
			foreach ( array_keys( (array) $b['map'] ) as $rel ) {
				if ( preg_match( '/\.(png|jpe?g)$/i', $rel ) && file_exists( $base . $rel ) ) {
					wp_delete_file( $base . $rel );
					++$n;
				}
			}
			delete_post_meta( $id, self::BACKUP );
		}
		Activity::log( 'settings.webp_bulk', sprintf( '%s فایل اصلی PNG/JPEG پس از تبدیل حذف شد.', PersianNormalizer::fa_number( $n ) ) );
		return $n;
	}
}
