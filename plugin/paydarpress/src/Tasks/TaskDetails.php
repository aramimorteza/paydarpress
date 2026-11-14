<?php
/**
 * Extra facts for task cards: a typed "view" link, Search Console numbers of the page,
 * queries that probably belong to it, a filtered Search Console link and content facts.
 *
 * The standard Search Console export has separate page and query tables, so the query ↔ page
 * pairing is an estimate and is labelled "probable" in the UI.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Tasks;

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Gsc\GscRepository;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Card details.
 */
final class TaskDetails {

	/** Prefixes of task keys whose id is a post / product / page. */
	private const POST_KEYS = array( 'refresh', 'meta', 'support', 'product', 'orphan' );

	/** Prefixes of task keys whose id is a term. */
	private const TERM_KEYS = array( 'category', 'term', 'shopcat', 'termtext' );

	/** A query is an "opportunity" in this position range with enough impressions. */
	private const OPP_FROM = 8;
	private const OPP_TO   = 20;
	private const OPP_MIN  = 50;

	/** Words too common to tie a query to a page. */
	private const STOP = array( 'در', 'به', 'از', 'با', 'و', 'برای', 'که', 'این', 'است', 'چیست', 'چگونه', 'چطور', 'کدام', 'های', 'ها', 'یا', 'تا', 'را', 'هم', 'بهترین', 'راهنمای', 'کامل' );

	/**
	 * Page metrics (one query per request).
	 *
	 * @var array|null
	 */
	private static $metrics = null;

	/**
	 * Top queries (one query per request).
	 *
	 * @var array|null
	 */
	private static $queries = null;

	/**
	 * Adds `details` to each task.
	 *
	 * @param array $tasks Tasks.
	 */
	public static function add( array $tasks ): array {
		return array_map( static fn( $t ) => $t + array( 'details' => self::for_task( $t ) ), $tasks );
	}

	/**
	 * Details of one task (empty when it is not about one page).
	 *
	 * @param array $task Task.
	 */
	public static function for_task( array $task ): array {
		$parts  = explode( ':', (string) $task['key'] );
		$prefix = $parts[0];
		$id     = (int) end( $parts );
		$ai     = is_array( $task['ai'] ?? null ) ? $task['ai'] : array();

		if ( in_array( $prefix, self::TERM_KEYS, true ) && $id > 0 ) {
			return self::for_term( $id );
		}
		if ( 'cluster' === $prefix && $id < 0 ) {
			// Key page that is a category archive.
			return self::for_term( -$id );
		}
		$post_id = 0;
		if ( in_array( $prefix, self::POST_KEYS, true ) && $id > 0 ) {
			$post_id = $id;
		} elseif ( ! empty( $ai['url'] ) && in_array( $prefix, array( 'cluster' ), true ) ) {
			$post_id = (int) url_to_postid( (string) $ai['url'] );
		}
		return $post_id > 0 ? self::for_post( $post_id ) : array();
	}

	/**
	 * Post / product / page details.
	 *
	 * @param int $id Post.
	 */
	public static function for_post( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		$key_ids = array_map( 'intval', array_keys( \PaydarWP\PaydarPress\Links\KeyPageLinks::pages() ) );
		$type    = in_array( $id, $key_ids, true ) ? 'key' : $post->post_type;
		$labels  = array(
			'key'     => 'مشاهده صفحه کلیدی',
			'product' => 'مشاهده محصول',
			'post'    => 'مشاهده مقاله',
			'page'    => 'مشاهده صفحه',
		);
		$live = 'publish' === $post->post_status;
		$url  = $live ? (string) get_permalink( $post ) : (string) get_preview_post_link( $post );

		$out = array(
			'view' => array(
				'label' => $live ? ( $labels[ $type ] ?? 'مشاهده' ) : 'پیش‌نمایش',
				'url'   => $url,
				'title' => rawurldecode( $url ),
			),
		);

		if ( $live ) {
			$m = self::metrics()[ $id ] ?? null;
			if ( null !== $m ) {
				$out['gsc'] = array(
					'position'      => $m['position'],
					'impressions'   => $m['impressions'],
					'clicks'        => $m['clicks'],
					'ctr'           => round( $m['ctr'] * 100, 1 ),
					'prev_position' => $m['prev_position'],
					// Lower is better: a positive delta means the page moved up.
					'delta'         => null !== $m['prev_position'] ? round( $m['prev_position'] - $m['position'], 1 ) : null,
				);
			}
			$q = self::probable( $id );
			if ( $q ) {
				$out['queries'] = $q;
			}
			$out['gsc_link'] = self::gsc_link( $url );
		}

		$facts = self::facts( $id );
		if ( $facts ) {
			$out['facts'] = $facts;
		}
		return $out;
	}

	/**
	 * Term archive details.
	 *
	 * @param int $id Term.
	 */
	private static function for_term( int $id ): array {
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term ) {
			return array();
		}
		$link = get_term_link( $term );
		if ( ! is_string( $link ) ) {
			return array();
		}
		$what = array(
			'category'    => 'دسته',
			'post_tag'    => 'برچسب',
			'product_cat' => 'دسته محصول',
			'product_tag' => 'برچسب محصول',
		)[ $term->taxonomy ] ?? 'آرشیو';
		return array(
			'view' => array(
				'label' => 'مشاهده آرشیو ' . $what,
				'url'   => $link,
				'title' => rawurldecode( $link ),
			),
		);
	}

	/**
	 * Content facts from the inventory: words, last update, links in, images.
	 *
	 * @param int $id Post.
	 */
	private static function facts( int $id ): array {
		global $wpdb;
		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT word_count, internal_in, internal_out, inline_images, modified_at, published_at FROM {$table} WHERE object_id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return array();
		}
		$date  = (string) ( $row['modified_at'] ?: $row['published_at'] );
		$facts = array( PersianNormalizer::fa_number( (int) $row['word_count'] ) . ' کلمه' );
		if ( '' !== $date ) {
			$days    = (int) floor( ( time() - strtotime( $date . ' UTC' ) ) / DAY_IN_SECONDS );
			$facts[] = $days > 365 ? 'آخرین به‌روزرسانی ' . PersianNormalizer::fa_number( (int) floor( $days / 365 ) ) . ' سال پیش' : ( $days > 30 ? 'آخرین به‌روزرسانی ' . PersianNormalizer::fa_number( (int) floor( $days / 30 ) ) . ' ماه پیش' : 'به‌روزرسانی اخیر' );
		}
		$facts[] = PersianNormalizer::fa_number( (int) $row['internal_in'] ) . ' لینک داخلی ورودی';
		$facts[] = PersianNormalizer::fa_number( (int) $row['inline_images'] ) . ' تصویر داخل متن';
		return $facts;
	}

	/**
	 * Queries that probably belong to a page: they contain its focus keyword, or most of
	 * their words appear in its title. Top 3 by impressions.
	 *
	 * @param int $id Post.
	 */
	public static function probable( int $id ): array {
		global $wpdb;
		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT title_norm, focus_keyword_norm FROM {$table} WHERE object_id = %d", $id ), ARRAY_A );
		$title = $row ? (string) $row['title_norm'] : PersianNormalizer::for_key( (string) get_the_title( $id ) );
		$focus = $row ? (string) $row['focus_keyword_norm'] : '';
		$list  = array_values( array_filter( explode( ' ', $title ), static fn( $w ) => mb_strlen( $w ) > 1 && ! in_array( $w, self::STOP, true ) ) );
		$words = array_flip( $list );
		// Two-word phrases of the title («طلاق توافقی»): a query containing one is about this page.
		$pairs = array();
		for ( $i = 0; $i < count( $list ) - 1; $i++ ) {
			$pairs[] = $list[ $i ] . ' ' . $list[ $i + 1 ];
		}
		if ( ! $words && '' === $focus ) {
			return array();
		}
		$out = array();
		foreach ( self::queries() as $q ) {
			$key   = PersianNormalizer::for_key( $q['item'] );
			$toks  = array_filter( explode( ' ', $key ), static fn( $w ) => mb_strlen( $w ) > 1 );
			$hit   = '' !== $focus && false !== mb_strpos( $key, $focus );
			foreach ( $hit ? array() : $pairs as $pair ) {
				if ( false !== mb_strpos( ' ' . $key . ' ', ' ' . $pair . ' ' ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit && count( $toks ) >= 2 ) {
				$in  = count( array_filter( $toks, static fn( $w ) => isset( $words[ $w ] ) ) );
				$hit = $in / count( $toks ) >= 0.75;
			}
			if ( $hit ) {
				$out[] = array(
					'query'       => $q['item'],
					'position'    => round( (float) $q['position'], 1 ),
					'impressions' => (int) $q['impressions'],
					'opportunity' => $q['position'] >= self::OPP_FROM && $q['position'] <= self::OPP_TO && $q['impressions'] >= self::OPP_MIN,
				);
				if ( count( $out ) >= 3 ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Search Console performance report filtered on one page.
	 * Assumes a domain property; Search Console asks for the property when it differs.
	 *
	 * @param string $url Page URL.
	 */
	public static function gsc_link( string $url ): string {
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return 'https://search.google.com/search-console/performance/search-analytics?resource_id=' . rawurlencode( 'sc-domain:' . $host ) . '&page=' . rawurlencode( '!' . $url );
	}

	/**
	 * Page metrics, cached for the request.
	 */
	private static function metrics(): array {
		if ( null === self::$metrics ) {
			self::$metrics = GscRepository::page_metrics();
		}
		return self::$metrics;
	}

	/**
	 * Top 500 queries by impressions, cached for the request.
	 */
	private static function queries(): array {
		if ( null === self::$queries ) {
			self::$queries = GscRepository::queries( 500 )['items'];
		}
		return self::$queries;
	}
}
