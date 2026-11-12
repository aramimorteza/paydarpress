<?php
/**
 * Reads CSV files from an uploaded zip in memory (nothing is extracted to disk).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Gsc;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zip reader with size limits (protection against zip bombs).
 */
final class ZipReader {

	/** Maximum uncompressed size of one file inside the zip. */
	public const MAX_ENTRY_BYTES = 20 * 1024 * 1024;

	/** Maximum number of entries looked at. */
	private const MAX_ENTRIES = 50;

	/**
	 * CSV files of a zip archive.
	 *
	 * @param string $path Uploaded temp file.
	 * @return array<string, string> Entry name => content.
	 * @throws RuntimeException When the archive cannot be read.
	 */
	public static function csv_files( string $path ): array {
		if ( class_exists( '\ZipArchive' ) ) {
			return self::with_ziparchive( $path );
		}

		return self::with_pclzip( $path );
	}

	/**
	 * Whether the file starts with the zip signature.
	 *
	 * @param string $path File.
	 */
	public static function is_zip( string $path ): bool {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return false;
		}
		$magic = (string) fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return "PK\x03\x04" === $magic;
	}

	/**
	 * ZipArchive implementation.
	 *
	 * @param string $path File.
	 * @return array<string, string>
	 * @throws RuntimeException On errors.
	 */
	private static function with_ziparchive( string $path ): array {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			throw new RuntimeException( 'فایل zip قابل باز کردن نیست. فایل را دوباره از سرچ کنسول دانلود کنید.' );
		}

		$files = array();
		$count = min( $zip->numFiles, self::MAX_ENTRIES );

		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat || ! self::wanted( (string) $stat['name'] ) ) {
				continue;
			}
			if ( (int) $stat['size'] > self::MAX_ENTRY_BYTES ) {
				$zip->close();
				throw new RuntimeException( 'یکی از فایل‌های داخل zip بیش از حد بزرگ است.' );
			}
			// Size already checked above; passing a length would preallocate that many bytes.
			$content = $zip->getFromIndex( $i );
			if ( is_string( $content ) ) {
				$files[ basename( (string) $stat['name'] ) ] = $content;
			}
		}

		$zip->close();

		return $files;
	}

	/**
	 * PclZip fallback (bundled with WordPress) for hosts without the zip extension.
	 *
	 * @param string $path File.
	 * @return array<string, string>
	 * @throws RuntimeException On errors.
	 */
	private static function with_pclzip( string $path ): array {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$zip  = new \PclZip( $path );
		$list = $zip->listContent();
		if ( ! is_array( $list ) ) {
			throw new RuntimeException( 'فایل zip قابل باز کردن نیست. فایل را دوباره از سرچ کنسول دانلود کنید.' );
		}

		$files = array();
		foreach ( array_slice( $list, 0, self::MAX_ENTRIES ) as $entry ) {
			if ( ! empty( $entry['folder'] ) || ! self::wanted( (string) $entry['filename'] ) ) {
				continue;
			}
			if ( (int) $entry['size'] > self::MAX_ENTRY_BYTES ) {
				throw new RuntimeException( 'یکی از فایل‌های داخل zip بیش از حد بزرگ است.' );
			}
			$data = $zip->extract( PCLZIP_OPT_BY_INDEX, (int) $entry['index'], PCLZIP_OPT_EXTRACT_AS_STRING );
			if ( is_array( $data ) && isset( $data[0]['content'] ) ) {
				$files[ basename( (string) $entry['filename'] ) ] = (string) $data[0]['content'];
			}
		}

		return $files;
	}

	/**
	 * CSV files only; macOS metadata entries are skipped.
	 *
	 * @param string $name Entry name.
	 */
	private static function wanted( string $name ): bool {
		return str_ends_with( strtolower( $name ), '.csv' ) && ! str_contains( $name, '__MACOSX' ) && ! str_starts_with( basename( $name ), '._' );
	}
}
