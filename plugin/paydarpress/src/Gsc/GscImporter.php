<?php
/**
 * Imports a Search Console Performance export: replaces the stored data set, matches page
 * URLs to content and reruns the audit.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Gsc;

use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\LinkResolver;
use PaydarWP\PaydarPress\Links\UrlNormalizer;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Console importer.
 */
final class GscImporter {

	/** Maximum upload size. */
	public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

	/**
	 * Imports uploaded files (zip and/or CSV).
	 *
	 * @param array<int, array{name:string, tmp_name:string, size:int}> $uploads Uploaded files.
	 * @return array Summary.
	 * @throws RuntimeException When nothing usable was found.
	 */
	public function import_uploads( array $uploads ): array {
		$files = array();
		$names = array();

		foreach ( $uploads as $upload ) {
			$name = sanitize_file_name( (string) $upload['name'] );
			if ( (int) $upload['size'] > self::MAX_UPLOAD_BYTES ) {
				throw new RuntimeException( 'حجم فایل بیش از ۱۰ مگابایت است. فقط خروجی گزارش «عملکرد» سرچ کنسول را آپلود کنید.' );
			}
			$names[] = $name;

			if ( ZipReader::is_zip( (string) $upload['tmp_name'] ) ) {
				$files = array_merge( $files, ZipReader::csv_files( (string) $upload['tmp_name'] ) );
				continue;
			}

			if ( ! preg_match( '/\.(csv|txt)$/i', $name ) ) {
				throw new RuntimeException( 'فقط فایل zip خروجی سرچ کنسول یا فایل‌های CSV داخل آن پذیرفته می‌شود.' );
			}

			$content = file_get_contents( (string) $upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $content ) {
				$files[ $name ] = $content;
			}
		}

		// The «Not found (404)» export of the Pages report goes to its own list and never
		// replaces the performance data. Both zips can be uploaded together.
		$label = implode( '، ', $names );
		$nf    = NotFound::detect( $files );
		$nf_r  = null === $nf ? null : NotFound::import( $nf['rows'], $label );
		try {
			$summary = $this->import( $files, $label );
		} catch ( RuntimeException $e ) {
			if ( null === $nf_r ) {
				throw $e;
			}
			return $nf_r;
		}
		return $summary + array( 'notfound' => $nf_r );
	}

	/**
	 * Imports parsed files.
	 *
	 * @param array<string, string> $files     File name => CSV.
	 * @param string                $file_name Name shown in the history.
	 * @return array Summary.
	 * @throws RuntimeException When nothing usable was found.
	 */
	public function import( array $files, string $file_name ): array {
		global $wpdb;

		$parsed = GscParser::parse( $files );

		if ( array() === $parsed['pages'] && array() === $parsed['queries'] ) {
			throw new RuntimeException( 'در فایل، جدول «صفحه‌ها» یا «عبارت‌های جست‌وجو» سرچ کنسول پیدا نشد. در گزارش «عملکرد» دکمه «خروجی» و سپس «دانلود CSV» را بزنید و همان فایل zip را آپلود کنید.' );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'imports' ),
			array(
				'type'       => 'gsc',
				'file_name'  => mb_substr( $file_name, 0, 255 ),
				'status'     => 'running',
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$import_id = (int) $wpdb->insert_id;

		GscRepository::clear();

		$urls     = new UrlNormalizer( home_url( '/' ) );
		$resolver = new LinkResolver();
		$counts   = array(
			'matched'     => 0,
			'old_url'     => 0,
			'unpublished' => 0,
			'archive'     => 0,
			'missing'     => 0,
			'foreign'     => 0,
		);
		$rows     = array();
		$clicks   = 0;
		$impr     = 0;
		$objects  = array();

		foreach ( $parsed['pages'] as $page ) {
			$url = (string) preg_replace( '/#.*$/', '', $page['key'] );
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				$counts['foreign']++;
				continue;
			}

			list( $object_id, $state ) = $urls->is_internal( $url ) ? $this->match( $resolver, $url ) : array( null, 'foreign' );
			$counts[ $state ]++;
			if ( 'matched' === $state ) {
				$objects[ $object_id ] = true;
			}

			$clicks += (int) $page['clicks'];
			$impr   += (int) $page['impressions'];

			if ( 'foreign' === $state ) {
				continue;
			}

			$rows[] = $this->row( $import_id, 'page', rawurldecode( $url ), $urls->key( $url ), $object_id, $state, $page );
		}

		foreach ( $parsed['queries'] as $query ) {
			$text = mb_substr( trim( wp_strip_all_tags( $query['key'] ) ), 0, 500 );
			if ( '' === $text ) {
				continue;
			}
			$rows[] = $this->row( $import_id, 'query', $text, PersianNormalizer::for_key( $text ), null, '', $query );
		}

		$this->insert_rows( $rows );
		unset( $rows );

		$internal = count( $parsed['pages'] ) - $counts['foreign'];
		$summary  = array(
			'period'         => $parsed['period'],
			'compare'        => $parsed['compare'],
			'files'          => $parsed['files'],
			'pages'          => count( $parsed['pages'] ),
			'pages_internal' => $internal,
			'pages_matched'  => $counts['matched'],
			'match_counts'   => $counts,
			// Home and archive pages are not content items, so they do not count against the rate.
			'match_rate'     => $internal - $counts['archive'] > 0 ? round( ( $counts['matched'] + $counts['unpublished'] ) / ( $internal - $counts['archive'] ), 3 ) : null,
			'content_items'  => count( $objects ),
			'queries'        => count( $parsed['queries'] ),
			'pages_capped'   => $parsed['pages_capped'],
			'queries_capped' => $parsed['queries_capped'],
			'clicks'         => $clicks,
			'impressions'    => $impr,
			'warnings'       => $parsed['warnings'],
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'imports' ),
			array(
				'status'  => 'completed',
				'summary' => wp_json_encode( $summary, JSON_UNESCAPED_UNICODE ),
			),
			array( 'id' => $import_id )
		);
		GscRepository::forget();

		Activity::log(
			'import.gsc',
			sprintf(
				'داده سرچ کنسول وارد شد: %s صفحه (%s مطلب سایت) و %s عبارت جست‌وجو.',
				PersianNormalizer::fa_number( count( $parsed['pages'] ) ),
				PersianNormalizer::fa_number( count( $objects ) ),
				PersianNormalizer::fa_number( count( $parsed['queries'] ) )
			),
			'import',
			$import_id
		);

		// The audit is fast (well under a second for thousands of posts), so findings and
		// refresh scores are up to date when the page reloads.
		( new AuditEngine() )->run( false );
		\PaydarWP\PaydarPress\Strategy\ChangeLog::measure();

		return $summary;
	}

	/**
	 * Matches a Search Console URL to content.
	 *
	 * @param LinkResolver $resolver Resolver.
	 * @param string       $url      Absolute internal URL.
	 * @return array{0:?int, 1:string} [object_id, match_state]
	 */
	private function match( LinkResolver $resolver, string $url ): array {
		list( $id, $state ) = $resolver->resolve( $url );

		if ( null !== $id && 'ok' === $state ) {
			return array( (int) $id, 'matched' );
		}
		if ( null !== $id && 'unpublished' === $state ) {
			return array( (int) $id, 'unpublished' );
		}
		if ( 'redirect' === $state ) {
			return array( null === $id ? null : (int) $id, 'old_url' );
		}
		if ( 'missing' === $state ) {
			return array( null, 'missing' );
		}

		return array( null, 'archive' );
	}

	/**
	 * One table row.
	 *
	 * @param int      $import_id Import.
	 * @param string   $kind      page|query.
	 * @param string   $item      URL or query.
	 * @param string   $hash_key  Value hashed for the index.
	 * @param int|null $object_id Matched content.
	 * @param string   $state     Match state.
	 * @param array    $metrics   Parsed metrics.
	 */
	private function row( int $import_id, string $kind, string $item, string $hash_key, ?int $object_id, string $state, array $metrics ): array {
		$int = static fn( $v ) => null === $v ? null : max( 0, (int) round( (float) $v ) );

		return array(
			$import_id,
			$kind,
			mb_substr( $item, 0, 1000 ),
			md5( $hash_key ),
			$object_id,
			$state,
			(int) $int( $metrics['clicks'] ),
			(int) $int( $metrics['impressions'] ),
			(float) ( $metrics['ctr'] ?? 0 ),
			(float) ( $metrics['position'] ?? 0 ),
			$int( $metrics['prev_clicks'] ),
			$int( $metrics['prev_impressions'] ),
			null === $metrics['prev_position'] ? null : (float) $metrics['prev_position'],
		);
	}

	/**
	 * Inserts rows in chunks.
	 *
	 * @param array<int, array> $rows Rows.
	 */
	private function insert_rows( array $rows ): void {
		global $wpdb;

		$table = Schema::table( 'gsc' );

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $r ) {
				$values[] = $wpdb->prepare(
					'(%d, %s, %s, %s, ' . ( null === $r[4] ? 'NULL' : '%d' ) . ', %s, %d, %d, %f, %f, ' . ( null === $r[10] ? 'NULL' : '%d' ) . ', ' . ( null === $r[11] ? 'NULL' : '%d' ) . ', ' . ( null === $r[12] ? 'NULL' : '%f' ) . ')',
					array_values( array_filter( $r, static fn( $v ) => null !== $v ) )
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO {$table} (import_id, kind, item, item_hash, object_id, match_state, clicks, impressions, ctr, position, prev_clicks, prev_impressions, prev_position) VALUES " . implode( ',', $values ) );
		}
	}
}
