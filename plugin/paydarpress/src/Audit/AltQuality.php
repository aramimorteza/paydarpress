<?php
/**
 * Image alt text quality checks. WordPress-independent.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Audit;

use PaydarWP\PaydarPress\Text\PersianNormalizer;

/**
 * Spec section 7.4.
 */
final class AltQuality {

	private const GENERIC_WORDS = array( 'تصویر', 'عکس', 'image', 'photo', 'picture', 'img', 'pic', 'thumbnail', 'تصویر شاخص', 'بدون عنوان', 'untitled' );

	/**
	 * Whether a non-empty alt is meaningless (file name, camera name, generic word).
	 *
	 * @param string $alt Alt text.
	 * @param string $src Image URL (to compare with the file name).
	 */
	public static function is_generic( string $alt, string $src = '' ): bool {
		$alt = PersianNormalizer::for_key( $alt );

		if ( '' === $alt ) {
			return false; // Empty is "missing", not "generic".
		}

		if ( in_array( $alt, self::GENERIC_WORDS, true ) ) {
			return true;
		}

		if ( preg_match( '/^(img|image|dsc|dscn|dcim|photo|screenshot|screen shot|pxl|wp|whatsapp image|tasvir|aks)[\s_\-]*\d*[\s_\-\d]*$/u', $alt ) ) {
			return true;
		}

		if ( preg_match( '/^[\d\s_\-]+$/u', $alt ) ) {
			return true;
		}

		if ( '' !== $src ) {
			$file = pathinfo( rawurldecode( (string) parse_url( $src, PHP_URL_PATH ) ), PATHINFO_FILENAME );
			$file = (string) preg_replace( '/-\d+x\d+$/', '', $file ); // WordPress size suffix.
			if ( '' !== $file && PersianNormalizer::compact( $file ) === PersianNormalizer::compact( $alt ) ) {
				return true;
			}
		}

		return false;
	}
}
