<?php
/**
 * Near-duplicate image detection with a 64-bit difference hash (dHash): small, fast and
 * robust to resizing, compression and format changes.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Images;

use PaydarWP\PaydarPress\Queue\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Hash in post meta; similar images (distance ≤ THRESHOLD) are listed on the attachment.
 */
final class ImageHash {

	public const META      = '_paydarpress_dhash';
	public const META_SIM  = '_paydarpress_similar';
	public const THRESHOLD = 6;
	public const ACTION    = 'paydarpress/images/hash_batch';

	private const BATCH = 40;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_metadata' ), 20, 2 );
		add_action( self::ACTION, array( $this, 'batch' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'field' ), 10, 2 );
	}

	/**
	 * Hash after WordPress created the sizes (so the medium size can be used).
	 *
	 * @param array $metadata      Metadata.
	 * @param int   $attachment_id Attachment.
	 * @return array
	 */
	public function on_metadata( $metadata, $attachment_id ) {
		if ( is_array( $metadata ) && wp_attachment_is_image( $attachment_id ) ) {
			self::process( (int) $attachment_id, $metadata, true );
		}
		return $metadata;
	}

	/**
	 * Computes and stores the hash; optionally records similar images in both directions.
	 *
	 * @param int        $id       Attachment.
	 * @param array|null $metadata Metadata.
	 * @param bool       $compare  Compare with the library.
	 */
	public static function process( int $id, ?array $metadata = null, bool $compare = true ): ?string {
		$metadata = $metadata ?? wp_get_attachment_metadata( $id );
		$file     = get_attached_file( $id );
		if ( ! $file ) {
			return null;
		}
		// The medium size is small and keeps the whole composition (thumbnails are cropped).
		if ( is_array( $metadata ) && ! empty( $metadata['sizes']['medium']['file'] ) ) {
			$medium = trailingslashit( dirname( $file ) ) . $metadata['sizes']['medium']['file'];
			if ( file_exists( $medium ) ) {
				$file = $medium;
			}
		}
		$hash = self::hash_file( $file );
		if ( null === $hash ) {
			update_post_meta( $id, self::META, 'x' );
			return null;
		}
		update_post_meta( $id, self::META, $hash );

		if ( $compare ) {
			$similar = self::similar_to( $hash, $id );
			if ( $similar ) {
				update_post_meta( $id, self::META_SIM, array_slice( array_keys( $similar ), 0, 5 ) );
				foreach ( array_keys( $similar ) as $other ) {
					$list = (array) get_post_meta( $other, self::META_SIM, true );
					if ( ! in_array( $id, $list, true ) ) {
						$list[] = $id;
						update_post_meta( $other, self::META_SIM, array_slice( array_values( array_filter( array_map( 'intval', $list ) ) ), -5 ) );
					}
				}
			}
		}
		return $hash;
	}

	/**
	 * dHash of an image file, or null when it cannot be read.
	 *
	 * @param string $path File.
	 */
	public static function hash_file( string $path ): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! is_readable( $path ) ) {
			return null;
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || $info[0] * $info[1] > 30000000 ) {
			return null;
		}
		$src = null;
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				$src = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			case IMAGETYPE_PNG:
				$src = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			case IMAGETYPE_GIF:
				$src = @imagecreatefromgif( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			default:
				if ( defined( 'IMAGETYPE_WEBP' ) && IMAGETYPE_WEBP === $info[2] && function_exists( 'imagecreatefromwebp' ) ) {
					$src = @imagecreatefromwebp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
		}
		if ( ! $src ) {
			return null;
		}
		$small = imagecreatetruecolor( 9, 8 );
		imagecopyresampled( $small, $src, 0, 0, 0, 0, 9, 8, imagesx( $src ), imagesy( $src ) );

		$gray = array();
		for ( $y = 0; $y < 8; $y++ ) {
			for ( $x = 0; $x < 9; $x++ ) {
				$c              = imagecolorat( $small, $x, $y );
				$gray[ $y ][ $x ] = ( ( $c >> 16 ) & 0xFF ) * 0.299 + ( ( $c >> 8 ) & 0xFF ) * 0.587 + ( $c & 0xFF ) * 0.114;
			}
		}
		return self::from_gray( $gray );
	}

	/**
	 * Hash from an 8×9 grayscale grid (left pixel brighter than right = 1).
	 *
	 * @param array $gray Rows of 9 values.
	 */
	public static function from_gray( array $gray ): string {
		$hex = '';
		for ( $y = 0; $y < 8; $y++ ) {
			$byte = 0;
			for ( $x = 0; $x < 8; $x++ ) {
				$byte = ( $byte << 1 ) | ( $gray[ $y ][ $x ] > $gray[ $y ][ $x + 1 ] ? 1 : 0 );
			}
			$hex .= str_pad( dechex( $byte ), 2, '0', STR_PAD_LEFT );
		}
		return $hex;
	}

	/**
	 * Number of differing bits.
	 *
	 * @param string $a Hex hash.
	 * @param string $b Hex hash.
	 */
	public static function distance( string $a, string $b ): int {
		if ( 16 !== strlen( $a ) || 16 !== strlen( $b ) ) {
			return 64;
		}
		$d = 0;
		for ( $i = 0; $i < 16; $i += 2 ) {
			$x = hexdec( substr( $a, $i, 2 ) ) ^ hexdec( substr( $b, $i, 2 ) );
			for ( ; $x; $x >>= 1 ) {
				$d += $x & 1;
			}
		}
		return $d;
	}

	/**
	 * Library images close to a hash.
	 *
	 * @param string $hash Hash.
	 * @param int    $self Attachment to leave out.
	 * @return array<int, int> id => distance
	 */
	public static function similar_to( string $hash, int $self = 0 ): array {
		$out = array();
		foreach ( self::all_hashes() as $id => $other ) {
			if ( $id === $self ) {
				continue;
			}
			$d = self::distance( $hash, $other );
			if ( $d <= self::THRESHOLD ) {
				$out[ $id ] = $d;
			}
		}
		asort( $out );
		return $out;
	}

	/**
	 * All stored hashes.
	 *
	 * @return array<int, string>
	 */
	public static function all_hashes(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> 'x'", self::META ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['post_id'] ] = (string) $r['meta_value'];
		}
		return $out;
	}

	/**
	 * Groups of similar images in the library.
	 *
	 * @param int $limit Max groups.
	 * @return array<int, int[]>
	 */
	public static function groups( int $limit = 30 ): array {
		$hashes = self::all_hashes();
		$ids    = array_keys( $hashes );
		$seen   = array();
		$groups = array();
		$count  = count( $ids );
		for ( $i = 0; $i < $count; $i++ ) {
			$a = $ids[ $i ];
			if ( isset( $seen[ $a ] ) ) {
				continue;
			}
			$group = array( $a );
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$b = $ids[ $j ];
				if ( ! isset( $seen[ $b ] ) && self::distance( $hashes[ $a ], $hashes[ $b ] ) <= self::THRESHOLD ) {
					$group[]    = $b;
					$seen[ $b ] = true;
				}
			}
			if ( count( $group ) > 1 ) {
				$seen[ $a ] = true;
				$groups[]   = $group;
				if ( count( $groups ) >= $limit ) {
					break;
				}
			}
		}
		return $groups;
	}

	/**
	 * Counts for the UI.
	 *
	 * @return array{images: int, hashed: int}
	 */
	public static function progress(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$images = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
		$hashed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'attachment'", self::META ) );
		// phpcs:enable
		return compact( 'images', 'hashed' );
	}

	/**
	 * Starts hashing the existing library in the background.
	 */
	public static function start(): void {
		Queue::schedule( self::ACTION, array(), 1 );
	}

	/**
	 * Background step: hashes images that have no hash yet.
	 */
	public function batch(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND m.meta_id IS NULL LIMIT %d",
				self::META,
				self::BATCH
			)
		);
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			// Comparing is done at the end, from the groups view; here only hashes are stored.
			self::process( $id, null, false );
		}
		if ( count( (array) $ids ) === self::BATCH ) {
			Queue::schedule( self::ACTION, array(), 5 );
		}
	}

	/**
	 * Warning in the media modal / attachment screen.
	 *
	 * @param array    $fields Fields.
	 * @param \WP_Post $post   Attachment.
	 */
	public function field( $fields, $post ) {
		// Aspect ratio check against the site's featured / inline ratios.
		$meta = wp_get_attachment_metadata( $post->ID );
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$r    = $meta['width'] / $meta['height'];
			$want = array();
			foreach ( array( 'images.featured_ratio' => '16:9', 'images.inline_ratio' => '4:3' ) as $key => $def ) {
				list( $w, $h ) = array_map( 'floatval', explode( ':', (string) \PaydarWP\PaydarPress\Core\Settings::get( $key, $def ) ) + array( 1, 1 ) );
				$want[]        = $h > 0 ? $w / $h : 1;
			}
			$ok = false;
			foreach ( $want as $x ) {
				$ok = $ok || abs( $r - $x ) / $x < 0.06;
			}
			if ( ! $ok ) {
				$fields['paydarpress_ratio'] = array(
					'label' => 'نسبت تصویر',
					'input' => 'html',
					'html'  => '<div dir="rtl" style="color:#8a5300">' . esc_html( sprintf( 'این تصویر %d×%d است و با نسبت تصاویر سایت (شاخص %s، داخل متن %s) نمی‌خواند؛ برای یکدستی آن را برش بزنید (ویرایش تصویر ← برش).', $meta['width'], $meta['height'], \PaydarWP\PaydarPress\Core\Settings::get( 'images.featured_ratio', '16:9' ), \PaydarWP\PaydarPress\Core\Settings::get( 'images.inline_ratio', '4:3' ) ) ) . '</div>',
				);
			}
		}
		$similar = array_filter( array_map( 'intval', (array) get_post_meta( $post->ID, self::META_SIM, true ) ), static fn( $id ) => 'attachment' === get_post_type( $id ) );
		if ( $similar ) {
			$links = implode(
				' ',
				array_map(
					static fn( $id ) => '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $id, array( 48, 48 ) ) . '</a>',
					$similar
				)
			);
			$fields['paydarpress_similar'] = array(
				'label' => 'پایدارپرس',
				'input' => 'html',
				'html'  => '<div dir="rtl" style="color:#8a5300">این تصویر بسیار شبیه تصاویر زیر در کتابخانه است. تصویر تکراری ارزش صفحه را پایین می‌آورد؛ تصویر دیگری بسازید یا از همان قبلی استفاده کنید.</div><div>' . $links . '</div>',
			);
		}
		return $fields;
	}
}
