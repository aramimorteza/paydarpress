<?php
/**
 * Search Console «Not found (404)» export: which addresses Google still requests, which of
 * them are worth fixing, and the likely fix (redirect, restore from trash, fix internal links).
 *
 * The export comes from Indexing → Pages → «Not found (404)» → Export (zip with Table.csv).
 * Every import refreshes the list; the owner's decisions (ignored / done) are kept.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Gsc;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\LinkResolver;
use PaydarWP\PaydarPress\Links\UrlNormalizer;
use PaydarWP\PaydarPress\Strategy\RedirectOwner;
use PaydarWP\PaydarPress\Strategy\Redirects;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * States: notfound (needs a decision), resolved (the address works again), redirected (a
 * redirect exists), junk (probing/system addresses), file (missing file). Status: open,
 * ignored, done.
 */
final class NotFound {

	/** Impressions in the performance export that make an address worth fixing. */
	public const VALUE_IMPRESSIONS = 10;

	public const MAX_ROWS = 5000;

	public const STATE_LABELS = array(
		'notfound'   => 'هنوز ۴۰۴',
		'resolved'   => 'حل شده (صفحه دوباره وجود دارد یا وردپرس ریدایرکت می‌کند)',
		'redirected' => 'ریدایرکت دارد',
		'junk'       => 'آدرس سیستمی یا اسپم',
		'file'       => 'فایل حذف‌شده',
	);

	public const REASON_LABELS = array(
		'trash'   => 'این مطلب در زباله‌دان است',
		'merged'  => 'این مطلب در مطلب دیگری ادغام شده',
		'moved'   => 'همین نامک در آدرس دیگری منتشر است',
		'similar' => 'مطلب با نامک یا عنوان مشابه',
	);

	/**
	 * Finds a «Not found (404)» table among CSV files.
	 *
	 * @param array<string, string> $files File name => CSV.
	 * @return array{rows: array<int, array{url: string, crawled: ?string}>, issue: string}|null
	 * @throws RuntimeException When the export is from another Pages issue.
	 */
	public static function detect( array $files ): ?array {
		$issue = '';
		$table = null;
		foreach ( $files as $name => $content ) {
			$rows = CsvReader::parse( (string) $content, self::MAX_ROWS )['rows'];
			if ( count( $rows ) < 1 ) {
				continue;
			}
			$base   = mb_strtolower( basename( (string) $name ), 'UTF-8' );
			$header = mb_strtolower( implode( ' | ', array_map( 'strval', $rows[0] ) ), 'UTF-8' );
			if ( preg_match( '/^metadata|فراداده/u', $base ) ) {
				foreach ( $rows as $r ) {
					$issue .= ' ' . implode( ' ', array_map( 'strval', $r ) );
				}
				continue;
			}
			$url_col  = null;
			$date_col = null;
			foreach ( $rows[0] as $i => $label ) {
				$l = mb_strtolower( trim( (string) $label ), 'UTF-8' );
				if ( null === $url_col && preg_match( '/^(url|نشانی|آدرس|نشانی وب)/u', $l ) ) {
					$url_col = $i;
				} elseif ( null === $date_col && preg_match( '/crawl|خزش|خزیده/u', $l ) ) {
					$date_col = $i;
				}
			}
			// The performance export has clicks; this one has a URL and a crawl date.
			if ( null === $url_col || null === $date_col || preg_match( '/click|کلیک|impression|نمایش/u', $header ) ) {
				continue;
			}
			$table = array();
			foreach ( array_slice( $rows, 1, self::MAX_ROWS ) as $r ) {
				$url = trim( (string) ( $r[ $url_col ] ?? '' ) );
				if ( preg_match( '#^https?://#i', $url ) ) {
					$table[] = array(
						'url'     => $url,
						'crawled' => self::date( (string) ( $r[ $date_col ] ?? '' ) ),
					);
				}
			}
		}
		if ( null === $table ) {
			return null;
		}
		$issue = trim( $issue );
		if ( '' !== $issue && ! preg_match( '/404|not found|یافت نشد|پیدا نشد/iu', $issue ) ) {
			throw new RuntimeException( 'این خروجی مربوط به ردیف دیگری از گزارش «صفحه‌ها» است. در سرچ کنسول روی ردیف «Not found (404)» بزنید و از همان صفحه خروجی بگیرید.' );
		}
		return array(
			'rows'  => $table,
			'issue' => $issue,
		);
	}

	/**
	 * Imports the rows: upsert by path, analyse, keep the owner's decisions.
	 *
	 * @param array  $rows      Rows from detect().
	 * @param string $file_name File name for the log.
	 * @return array Summary.
	 */
	public static function import( array $rows, string $file_name ): array {
		global $wpdb;
		$table    = Schema::table( 'notfound' );
		$now      = current_time( 'mysql', true );
		$urls     = new UrlNormalizer( home_url( '/' ) );
		$resolver = new LinkResolver();
		$ctx      = self::context( $urls );
		$counts   = array_fill_keys( array_keys( self::STATE_LABELS ), 0 ) + array(
			'foreign'  => 0,
			'valuable' => 0,
		);

		$seen = array();
		foreach ( $rows as $row ) {
			$url = (string) preg_replace( '/#.*$/', '', $row['url'] );
			if ( ! $urls->is_internal( $url ) ) {
				++$counts['foreign'];
				continue;
			}
			$path = Redirects::path( $url );
			$hash = md5( $path );
			if ( '' === $path || isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;

			$a = self::analyse( $url, $path, $urls, $resolver, $ctx );
			++$counts[ $a['state'] ];
			if ( $a['valuable'] && 'notfound' === $a['state'] ) {
				++$counts['valuable'];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE path_hash = %s", $hash ), ARRAY_A );
			$data     = array(
				'url'              => mb_substr( rawurldecode( $url ), 0, 1000 ),
				'last_crawled'     => $row['crawled'],
				'state'            => $a['state'],
				'candidate_id'     => $a['candidate_id'],
				'candidate_reason' => $a['reason'],
				'clicks'           => $a['clicks'],
				'impressions'      => $a['impressions'],
				'inbound'          => $a['inbound'],
				'valuable'         => $a['valuable'] ? 1 : 0,
				'seen_at'          => $now,
			);
			if ( $existing ) {
				// Fixed addresses that Google still reports stay «done»; ignored stay ignored.
				$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$wpdb->insert( $table, $data + array( 'path_hash' => $hash, 'status' => 'open', 'first_seen' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		// Addresses no longer in Google's list are gone from the report: drop the open ones.
		if ( $seen ) {
			$in = implode( ',', array_map( static fn( $h ) => "'" . esc_sql( $h ) . "'", array_keys( $seen ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE status = 'open' AND path_hash NOT IN ({$in})" );
		}

		update_option( 'paydarpress_notfound_import', array( 'at' => $now, 'file' => mb_substr( $file_name, 0, 200 ), 'counts' => $counts ), false );
		Activity::log(
			'gsc.notfound',
			sprintf( 'خروجی ۴۰۴ سرچ کنسول وارد شد: %s آدرس، %s آدرس باارزش که هنوز ۴۰۴ است.', PersianNormalizer::fa_number( count( $seen ) ), PersianNormalizer::fa_number( $counts['valuable'] ) )
		);
		return array(
			'type'   => 'notfound',
			'total'  => count( $seen ),
			'counts' => $counts,
		);
	}

	/**
	 * Analyses one address.
	 *
	 * @param string        $url      URL.
	 * @param string        $path     Normalised path.
	 * @param UrlNormalizer $urls     Normaliser.
	 * @param LinkResolver  $resolver Resolver.
	 * @param array         $ctx      Lookups from context().
	 * @return array{state: string, candidate_id: ?int, reason: string, clicks: int, impressions: int, inbound: int, valuable: bool}
	 */
	public static function analyse( string $url, string $path, UrlNormalizer $urls, LinkResolver $resolver, array $ctx ): array {
		$key     = $urls->key( $url );
		$metrics = $ctx['gsc'][ md5( $key ) ] ?? array( 0, 0 );
		$inbound = (int) ( $ctx['inbound'][ $key ] ?? 0 );
		$out     = array(
			'state'        => 'notfound',
			'candidate_id' => null,
			'reason'       => '',
			'clicks'       => (int) $metrics[0],
			'impressions'  => (int) $metrics[1],
			'inbound'      => $inbound,
			'valuable'     => $metrics[0] > 0 || $metrics[1] >= self::VALUE_IMPRESSIONS || $inbound > 0,
		);

		if ( isset( $ctx['redirects'][ md5( $path ) ] ) ) {
			$out['state'] = 'redirected';
			return $out;
		}
		list( $id, $state ) = $resolver->resolve( $url );
		if ( 'ok' === $state || 'redirect' === $state ) {
			$out['state'] = 'resolved';
			return $out;
		}
		if ( self::is_junk( $path, $url ) ) {
			$out['state']    = 'junk';
			$out['valuable'] = false;
			return $out;
		}
		if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|pdf|zip|rar|docx?|xlsx?|mp[34]|css|js)$/i', $path ) ) {
			$out['state'] = 'file';
			return $out;
		}

		// Candidates, strongest first.
		$slug = mb_strtolower( (string) basename( $path ), 'UTF-8' );
		foreach ( self::by_slug( $slug ) as $post ) {
			if ( 'trash' === $post['status'] ) {
				$merged = (int) get_post_meta( (int) $post['id'], '_paydarpress_merged_into', true );
				$out['candidate_id'] = $merged > 0 ? $merged : (int) $post['id'];
				$out['reason']       = $merged > 0 ? 'merged' : 'trash';
				return $out;
			}
			$merged = (int) get_post_meta( (int) $post['id'], '_paydarpress_merged_into', true );
			if ( $merged > 0 && 'publish' === get_post_status( $merged ) ) {
				$out['candidate_id'] = $merged;
				$out['reason']       = 'merged';
				return $out;
			}
			if ( 'publish' === $post['status'] ) {
				$out['candidate_id'] = (int) $post['id'];
				$out['reason']       = 'moved';
				return $out;
			}
		}
		$similar = self::similar( $slug, $ctx['index'] );
		if ( null !== $similar ) {
			$out['candidate_id'] = $similar;
			$out['reason']       = 'similar';
		}
		unset( $id );
		return $out;
	}

	/**
	 * Lists rows for the UI.
	 *
	 * @param string $view todo (open, still 404, valuable first) | all | done.
	 * @param int    $limit Limit.
	 * @return array[]
	 */
	public static function list( string $view = 'todo', int $limit = 200 ): array {
		global $wpdb;
		$table = Schema::table( 'notfound' );
		$where = array(
			'todo' => "status = 'open' AND state IN ('notfound','file')",
			'all'  => "status = 'open'",
			'done' => "status IN ('done','ignored')",
		)[ $view ] ?? "status = 'open'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY valuable DESC, clicks DESC, impressions DESC, inbound DESC, id ASC LIMIT %d", $limit ), ARRAY_A );
		return array_map( array( self::class, 'view' ), (array) $rows );
	}

	/**
	 * Counts for the page header and the task list.
	 *
	 * @return array{todo: int, valuable: int, total: int, imported: ?array}
	 */
	public static function counts(): array {
		global $wpdb;
		$table = Schema::table( 'notfound' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(status = 'open' AND state IN ('notfound','file')) AS todo, SUM(status = 'open' AND state = 'notfound' AND valuable = 1) AS valuable FROM {$table}", ARRAY_A );
		$imp = get_option( 'paydarpress_notfound_import', null );
		return array(
			'todo'     => (int) ( $row['todo'] ?? 0 ),
			'valuable' => (int) ( $row['valuable'] ?? 0 ),
			'total'    => (int) ( $row['total'] ?? 0 ),
			'imported' => is_array( $imp ) ? $imp + array( 'at_iso' => gmdate( 'c', (int) strtotime( $imp['at'] . ' UTC' ) ) ) : null,
		);
	}

	/**
	 * Applies the owner's decision.
	 *
	 * @param int    $id     Row.
	 * @param string $action redirect | restore | ignore | reopen.
	 * @param int    $target Target post for redirect (0: the suggested candidate).
	 * @return array{ok: bool, message: string}
	 * @throws RuntimeException On invalid input.
	 */
	public static function act( int $id, string $action, int $target = 0 ): array {
		global $wpdb;
		$table = Schema::table( 'notfound' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			throw new RuntimeException( 'این آدرس پیدا نشد.' );
		}
		$set = static function ( string $status, string $note ) use ( $wpdb, $table, $id ) {
			$wpdb->update( $table, array( 'status' => $status, 'note' => mb_substr( $note, 0, 255 ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		};

		switch ( $action ) {
			case 'redirect':
				$target = $target > 0 ? $target : (int) $row['candidate_id'];
				if ( $target <= 0 || 'publish' !== get_post_status( $target ) ) {
					throw new RuntimeException( 'مقصد ریدایرکت باید یک مطلب منتشرشده باشد.' );
				}
				$r = RedirectOwner::ensure( (string) $row['url'], $target, 'gsc404' );
				$set( 'done', $r['message'] );
				\PaydarWP\PaydarPress\Strategy\ChangeLog::record( 'redirect', $target, 'آدرس ۴۰۴ «' . rawurldecode( (string) wp_parse_url( (string) $row['url'], PHP_URL_PATH ) ) . '» به این صفحه ریدایرکت شد', 'post', sprintf( 'گوگل این آدرس را ۴۰۴ دیده بود (%s نمایش، %s لینک ورودی).', \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( (int) $row['impressions'] ), \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( (int) $row['inbound'] ) ) );
				return array( 'ok' => true, 'message' => $r['message'] );

			case 'restore':
				$post = (int) $row['candidate_id'];
				if ( $post <= 0 || 'trash' !== get_post_status( $post ) || ! current_user_can( 'delete_post', $post ) ) {
					throw new RuntimeException( 'این مطلب در زباله‌دان نیست یا اجازه بازگردانی ندارید.' );
				}
				// Restore to the status it had before trashing (WordPress 5.6+ restores to draft by default).
				add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
				wp_untrash_post( $post );
				remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
				$msg = 'مطلب از زباله‌دان برگشت (وضعیت: ' . get_post_status( $post ) . ').';
				$set( 'done', $msg );
				return array( 'ok' => true, 'message' => $msg );

			case 'ignore':
				$set( 'ignored', 'رها شد' );
				return array( 'ok' => true, 'message' => 'این آدرس دیگر در فهرست کارها نمی‌آید.' );

			case 'reopen':
				$set( 'open', '' );
				return array( 'ok' => true, 'message' => '' );
		}
		throw new RuntimeException( 'اقدام نامعتبر.' );
	}

	/**
	 * Row for the UI.
	 *
	 * @param array $r Row.
	 */
	private static function view( array $r ): array {
		$cand = $r['candidate_id'] ? (int) $r['candidate_id'] : null;
		return array(
			'id'           => (int) $r['id'],
			'url'          => $r['url'],
			'path'         => Redirects::path( $r['url'] ),
			'last_crawled' => $r['last_crawled'],
			'state'        => $r['state'],
			'state_label'  => self::STATE_LABELS[ $r['state'] ] ?? $r['state'],
			'status'       => $r['status'],
			'clicks'       => (int) $r['clicks'],
			'impressions'  => (int) $r['impressions'],
			'inbound'      => (int) $r['inbound'],
			'valuable'     => (bool) $r['valuable'],
			'note'         => $r['note'],
			'candidate'    => null === $cand ? null : array(
				'id'           => $cand,
				'title'        => html_entity_decode( get_the_title( $cand ), ENT_QUOTES, 'UTF-8' ),
				'url'          => (string) get_permalink( $cand ),
				'status'       => (string) get_post_status( $cand ),
				'reason'       => $r['candidate_reason'],
				'reason_label' => self::REASON_LABELS[ $r['candidate_reason'] ] ?? '',
			),
		);
	}

	/**
	 * Lookups shared by all rows of one import.
	 *
	 * @param UrlNormalizer $urls Normaliser.
	 */
	private static function context( UrlNormalizer $urls ): array {
		global $wpdb;

		// Performance metrics of pages (from the last performance import).
		$gsc = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( 'SELECT item_hash, clicks, impressions FROM ' . Schema::table( 'gsc' ) . " WHERE kind = 'page'", ARRAY_A ) as $g ) {
			$gsc[ $g['item_hash'] ] = array( (int) $g['clicks'], (int) $g['impressions'] );
		}

		// Internal links from published content that point to addresses without content.
		$inbound = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links = $wpdb->get_results( 'SELECT DISTINCT source_id, target_url FROM ' . Schema::table( 'links' ) . " WHERE link_type = 'internal' AND target_state IN ('missing','unknown','unpublished')", ARRAY_A );
		foreach ( (array) $links as $l ) {
			$k             = $urls->key( (string) $l['target_url'] );
			$inbound[ $k ] = ( $inbound[ $k ] ?? 0 ) + 1;
		}

		$redirects = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( 'SELECT source_hash FROM ' . Schema::table( 'redirects' ) ) as $h ) {
			$redirects[ $h ] = true;
		}

		// Token index of published content slugs and titles (similar-address suggestions).
		$index = array( 'tokens' => array(), 'items' => array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( 'SELECT object_id, url, title_norm FROM ' . Schema::table( 'inventory' ) . " WHERE status = 'publish' AND object_type IN ('post','page','product') LIMIT 20000", ARRAY_A );
		foreach ( (array) $items as $it ) {
			$slug   = rawurldecode( (string) basename( rtrim( (string) wp_parse_url( (string) $it['url'], PHP_URL_PATH ), '/' ) ) );
			$st     = self::tokens( $slug );
			$tt     = self::tokens( (string) $it['title_norm'] );
			$id     = (int) $it['object_id'];
			$index['items'][ $id ] = array( $st, $tt );
			foreach ( array_unique( array_merge( $st, $tt ) ) as $t ) {
				$index['tokens'][ $t ][] = $id;
			}
		}

		return compact( 'gsc', 'inbound', 'redirects', 'index' );
	}

	/**
	 * Posts (any status) whose slug is this one (trashed slugs end in «__trashed»).
	 *
	 * @param string $slug Decoded slug.
	 * @return array<int, array{id: int, status: string}>
	 */
	private static function by_slug( string $slug ): array {
		global $wpdb;
		if ( '' === $slug ) {
			return array();
		}
		$enc = strtolower( rawurlencode( $slug ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID AS id, post_status AS status FROM {$wpdb->posts} WHERE post_type IN ('post','page','product') AND post_name IN (%s, %s, %s, %s) ORDER BY FIELD(post_status, 'trash', 'publish') DESC LIMIT 5", $slug, $slug . '__trashed', $enc, $enc . '__trashed' ), ARRAY_A );
		return array_map( static fn( $r ) => array( 'id' => (int) $r['id'], 'status' => (string) $r['status'] ), (array) $rows );
	}

	/**
	 * Most similar published item by shared slug/title tokens (Jaccard ≥ 0.5, ≥ 2 shared).
	 *
	 * @param string $slug  Decoded slug.
	 * @param array  $index Token index.
	 */
	private static function similar( string $slug, array $index ): ?int {
		$tokens = self::tokens( $slug );
		if ( count( $tokens ) < 2 ) {
			return null;
		}
		$shared = array();
		foreach ( $tokens as $t ) {
			foreach ( $index['tokens'][ $t ] ?? array() as $id ) {
				$shared[ $id ] = ( $shared[ $id ] ?? 0 ) + 1;
			}
		}
		$best  = null;
		$score = 0.0;
		foreach ( $shared as $id => $n ) {
			if ( $n < 2 ) {
				continue;
			}
			// Compare with the slug and with the title separately (mixing both dilutes the score).
			$s = 0.0;
			foreach ( $index['items'][ $id ] as $set ) {
				$common = count( array_intersect( $tokens, $set ) );
				$union  = count( array_unique( array_merge( $tokens, $set ) ) );
				if ( $common >= 2 && $union > 0 ) {
					$s = max( $s, $common / $union );
				}
			}
			if ( $s > $score ) {
				$score = $s;
				$best  = (int) $id;
			}
		}
		return $score >= 0.5 ? $best : null;
	}

	/**
	 * Words of a slug or title (normalised, short words dropped).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		$text  = PersianNormalizer::for_key( str_replace( array( '-', '_', '+', '.' ), ' ', $text ) );
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_filter( (array) $words, static fn( $w ) => mb_strlen( $w ) > 1 && ! is_numeric( $w ) ) ) );
	}

	/**
	 * System, probing and parameter addresses that do not need a decision.
	 *
	 * @param string $path Path.
	 * @param string $url  URL.
	 */
	public static function is_junk( string $path, string $url ): bool {
		if ( preg_match( '#(^|/)(wp-admin|wp-includes|wp-json|wp-content/plugins|cgi-bin|\.well-known|\.env|\.git)(/|$)|xmlrpc|wp-login|\.(php|asp|aspx|jsp|cgi|bak|sql|ini|log)$#i', $path ) ) {
			return true;
		}
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query && preg_match( '/(^|&)(s|replytocom|share|amp|add-to-cart|orderby|filter_[a-z_]+|p|page_id|attachment_id)=/i', $query ) ) {
			return true;
		}
		return mb_strlen( $path ) > 300;
	}

	/**
	 * Crawl date (Gregorian, Persian digits allowed) → Y-m-d.
	 *
	 * @param string $value Value.
	 */
	private static function date( string $value ): ?string {
		$value = trim( strtr( $value, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) ) );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			return "{$m[1]}-{$m[2]}-{$m[3]}";
		}
		$t = strtotime( $value );
		return false === $t ? null : gmdate( 'Y-m-d', $t );
	}
}
