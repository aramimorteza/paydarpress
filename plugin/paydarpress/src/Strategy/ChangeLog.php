<?php
/**
 * Log of changes PaydarPress made (rewrites, SEO titles, category texts, merges) with the
 * Search Console metrics at that moment, and the result measured on a later import.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Gsc\GscRepository;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * A change is measured once a Search Console export covers at least MIN_DAYS after it.
 */
final class ChangeLog {

	public const MIN_DAYS = 28;

	public const KINDS = array(
		'rewrite'  => 'بازنویسی',
		'meta'     => 'عنوان و متا',
		'term'     => 'متن دسته',
		'merge'    => 'ادغام',
		'link'     => 'لینک داخلی',
		'decision' => 'تصمیم دسته و برچسب',
		'redirect' => 'ریدایرکت ۴۰۴',
		'cluster'  => 'خوشه محتوایی',
		'alt'      => 'متن جایگزین تصویر',
		'slug'     => 'تغییر آدرس',
	);

	/** Kinds Search Console cannot judge (no page traffic tied to them). */
	public const UNMEASURED = array( 'alt', 'decision', 'term' );

	/** History points are kept for this long after the change. */
	public const TRACK_DAYS = 180;

	/** Two changes on one page closer than this are "simultaneous". */
	public const OVERLAP_DAYS = 28;

	public const VERDICTS = array(
		'better'  => 'بهتر شد',
		'same'    => 'تغییر محسوسی نداشت',
		'worse'   => 'بدتر شد',
		'no_data' => 'داده کافی نیست',
		'unmeasured' => 'با سرچ کنسول سنجیده نمی‌شود',
	);

	/**
	 * Records a change with why it was made: the open audit problems of the item and its
	 * Search Console numbers at that moment.
	 *
	 * @param string $kind    Kind (see KINDS).
	 * @param int    $id      Post (or term) id.
	 * @param string $summary Short description.
	 * @param string $object  post|term.
	 * @param string $reason  Why (empty = built from open findings and numbers).
	 */
	public static function record( string $kind, int $id, string $summary, string $object = 'post', string $reason = '' ): void {
		global $wpdb;
		$metrics = 'post' === $object ? ( GscRepository::page_metrics()[ $id ] ?? null ) : null;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'changes' ),
			array(
				'object_kind'    => $object,
				'object_id'      => $id,
				'kind'           => sanitize_key( $kind ),
				'summary'        => mb_substr( $summary, 0, 255 ),
				'before_metrics' => null === $metrics ? null : wp_json_encode( self::slim( $metrics ) ),
				'reason'         => mb_substr( '' !== $reason ? $reason : self::reason_for( $id, $object, $metrics ), 0, 1000 ),
				'measurable'     => in_array( $kind, self::UNMEASURED, true ) || 'post' !== $object ? 0 : 1,
				'user_id'        => get_current_user_id(),
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * «Why» at the moment of the change.
	 *
	 * @param int        $id      Object.
	 * @param string     $object  post|term.
	 * @param array|null $metrics Search Console numbers.
	 */
	public static function reason_for( int $id, string $object, ?array $metrics ): string {
		global $wpdb;
		$parts = array();
		$table = Schema::table( 'audit_findings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$codes = $wpdb->get_col( $wpdb->prepare( "SELECT code FROM {$table} WHERE object_kind = %s AND object_id = %d AND state = 'open' ORDER BY CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END LIMIT 3", 'post' === $object ? 'content' : 'term', $id ) );
		$titles = array();
		foreach ( (array) $codes as $code ) {
			$rule = \PaydarWP\PaydarPress\Audit\Catalog::get( (string) $code );
			if ( $rule ) {
				$titles[] = $rule['title'];
			}
		}
		if ( $titles ) {
			$parts[] = 'مشکلات باز: ' . implode( '، ', $titles );
		}
		if ( null !== $metrics && $metrics['impressions'] > 0 ) {
			$parts[] = sprintf( 'رتبه %s، %s نمایش، %s کلیک، نرخ کلیک %s٪', PersianNormalizer::fa_number( $metrics['position'] ), PersianNormalizer::fa_number( $metrics['impressions'] ), PersianNormalizer::fa_number( $metrics['clicks'] ), PersianNormalizer::fa_number( round( $metrics['ctr'] * 100, 1 ) ) );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * After each Search Console import: adds a point to every tracked change's history and
	 * (re)judges changes old enough to measure. The verdict follows the latest numbers.
	 *
	 * @return int Changes judged.
	 */
	public static function measure(): int {
		global $wpdb;
		$import = GscRepository::latest_import();
		$end    = $import['summary']['period']['end'] ?? null;
		if ( ! $end ) {
			return 0;
		}
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( $end . ' 23:59:59' ) - self::MIN_DAYS * DAY_IN_SECONDS );
		$since   = gmdate( 'Y-m-d H:i:s', time() - self::TRACK_DAYS * DAY_IN_SECONDS );
		$table   = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_id, object_kind, before_metrics, history, measurable, created_at FROM {$table} WHERE created_at >= %s OR measured_at IS NULL", $since ), ARRAY_A );
		$metrics = GscRepository::page_metrics();
		$n       = 0;
		foreach ( (array) $rows as $r ) {
			$now     = 'post' === $r['object_kind'] ? ( $metrics[ (int) $r['object_id'] ] ?? null ) : null;
			$history = json_decode( (string) $r['history'], true );
			$history = is_array( $history ) ? $history : array();
			if ( null !== $now && ( ! $history || end( $history )['d'] !== $end ) ) {
				$history[] = array( 'd' => $end ) + self::slim( $now );
				$history   = array_slice( $history, -12 );
			}
			$fields = array( 'history' => $history ? wp_json_encode( $history ) : null );
			if ( $r['created_at'] <= $cutoff ) {
				$before                  = json_decode( (string) $r['before_metrics'], true );
				$fields['after_metrics'] = null === $now ? null : wp_json_encode( self::slim( $now ) );
				$fields['verdict']       = (int) $r['measurable'] ? self::verdict( is_array( $before ) ? $before : null, $now ) : 'unmeasured';
				$fields['measured_at']   = current_time( 'mysql', true );
				++$n;
			}
			$wpdb->update( $table, $fields, array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $n;
	}

	/**
	 * Better / same / worse from clicks, CTR and position.
	 *
	 * @param array|null $b Before.
	 * @param array|null $a After.
	 */
	public static function verdict( ?array $b, ?array $a ): string {
		if ( null === $b || null === $a || ( ( $b['impressions'] ?? 0 ) < 20 && ( $a['impressions'] ?? 0 ) < 20 ) ) {
			return 'no_data';
		}
		$score = 0;
		$bc    = (int) ( $b['clicks'] ?? 0 );
		$ac    = (int) ( $a['clicks'] ?? 0 );
		if ( $ac >= $bc * 1.2 && $ac - $bc >= 3 ) {
			++$score;
		} elseif ( $ac <= $bc * 0.8 && $bc - $ac >= 3 ) {
			--$score;
		}
		$bp = (float) ( $b['position'] ?? 0 );
		$ap = (float) ( $a['position'] ?? 0 );
		if ( $bp > 0 && $ap > 0 ) {
			if ( $ap <= $bp - 1 ) {
				++$score;
			} elseif ( $ap >= $bp + 1 ) {
				--$score;
			}
		}
		return $score > 0 ? 'better' : ( $score < 0 ? 'worse' : 'same' );
	}

	/**
	 * Changes for the report, with filters, current numbers, history, overlaps and a summary.
	 *
	 * @param array $f { kind, verdict, days, limit }.
	 */
	public static function report( array $f = array() ): array {
		global $wpdb;
		$table = Schema::table( 'changes' );
		$where = array( '1=1' );
		$args  = array();
		$kind  = sanitize_key( (string) ( $f['kind'] ?? '' ) );
		if ( '' !== $kind && isset( self::KINDS[ $kind ] ) ) {
			$where[] = 'kind = %s';
			$args[]  = $kind;
		}
		$days = (int) ( $f['days'] ?? 0 );
		if ( $days > 0 ) {
			$where[] = 'created_at >= %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		}
		$verdict = sanitize_key( (string) ( $f['verdict'] ?? '' ) );
		if ( 'pending' === $verdict ) {
			$where[] = 'verdict IS NULL';
		} elseif ( '' !== $verdict && isset( self::VERDICTS[ $verdict ] ) ) {
			$where[] = 'verdict = %s';
			$args[]  = $verdict;
		}
		$limit = max( 1, min( 1000, (int) ( $f['limit'] ?? 200 ) ) );
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT ' . $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows    = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$metrics = GscRepository::page_metrics();

		// Overlaps need every change of the same objects, not only the filtered ones.
		$ids  = array_values( array_unique( array_map( static fn( $r ) => (int) $r['object_id'], (array) $rows ) ) );
		$all  = array();
		if ( $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( "SELECT id, object_id, object_kind, created_at FROM {$table} WHERE object_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ) as $o ) {
				$all[ $o['object_kind'] . ':' . $o['object_id'] ][] = $o;
			}
		}

		$items = array();
		$sum   = array( 'total' => 0, 'better' => 0, 'same' => 0, 'worse' => 0, 'no_data' => 0, 'unmeasured' => 0, 'pending' => 0, 'clicks' => 0 );
		foreach ( (array) $rows as $r ) {
			$id     = (int) $r['object_id'];
			$term   = 'term' === $r['object_kind'] ? get_term( $id ) : null;
			$post   = 'post' === $r['object_kind'] ? get_post( $id ) : null;
			$before = json_decode( (string) $r['before_metrics'], true );
			$now    = 'post' === $r['object_kind'] && isset( $metrics[ $id ] ) ? self::slim( $metrics[ $id ] ) : null;
			$t      = strtotime( $r['created_at'] . ' UTC' );
			$near   = array_filter(
				$all[ $r['object_kind'] . ':' . $id ] ?? array(),
				static fn( $o ) => (int) $o['id'] !== (int) $r['id'] && abs( strtotime( $o['created_at'] . ' UTC' ) - $t ) < self::OVERLAP_DAYS * DAY_IN_SECONDS
			);
			$title = $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : ( $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '(حذف‌شده)' );
			$url   = $term instanceof \WP_Term ? get_term_link( $term ) : ( $post && 'publish' === $post->post_status ? get_permalink( $post ) : '' );

			$revert = '';
			if ( 'rewrite' === $r['kind'] && $post && 'worse' === $r['verdict'] ) {
				// Revisions are newest first; the one saved by the change holds the new text, the
				// next older one the text before it.
				$found = false;
				foreach ( wp_get_post_revisions( $id, array( 'posts_per_page' => 30 ) ) as $rev ) {
					if ( strtotime( $rev->post_date_gmt . ' UTC' ) <= $t + 60 ) {
						if ( $found ) {
							$revert = admin_url( 'revision.php?revision=' . $rev->ID );
							break;
						}
						$found = true;
					}
				}
			}

			$items[] = array(
				'id'            => (int) $r['id'],
				'kind'          => $r['kind'],
				'kind_label'    => self::KINDS[ $r['kind'] ] ?? $r['kind'],
				'title'         => $title,
				'url'           => is_string( $url ) ? $url : '',
				'edit_url'      => $term instanceof \WP_Term ? (string) get_edit_term_link( $id, $term->taxonomy ) : ( $post ? (string) get_edit_post_link( $id, 'raw' ) : '' ),
				'summary'       => $r['summary'],
				'reason'        => (string) $r['reason'],
				'before'        => is_array( $before ) ? $before : null,
				'after'         => json_decode( (string) $r['after_metrics'], true ),
				'now'           => $now,
				'history'       => json_decode( (string) $r['history'], true ) ?: array(),
				'measurable'    => (bool) (int) $r['measurable'],
				'verdict'       => $r['verdict'],
				'verdict_label' => $r['verdict'] ? ( self::VERDICTS[ $r['verdict'] ] ?? '' ) : null,
				'overlap'       => count( $near ),
				'revert_url'    => $revert,
				'created_at'    => gmdate( 'c', (int) $t ),
				'measure_after' => gmdate( 'c', (int) $t + self::MIN_DAYS * DAY_IN_SECONDS ),
			);

			++$sum['total'];
			$v = $r['verdict'] ? $r['verdict'] : 'pending';
			$sum[ $v ] = ( $sum[ $v ] ?? 0 ) + 1;
			if ( is_array( $before ) && null !== $now && in_array( $r['verdict'], array( 'better', 'same', 'worse' ), true ) ) {
				$sum['clicks'] += (int) $now['clicks'] - (int) $before['clicks'];
			}
		}

		return array(
			'items'    => $items,
			'summary'  => $sum,
			'kinds'    => self::KINDS,
			'verdicts' => self::VERDICTS,
			'min_days' => self::MIN_DAYS,
		);
	}

	/**
	 * Recent changes (kept for older callers).
	 *
	 * @param int $limit Limit.
	 */
	public static function recent( int $limit = 100 ): array {
		return self::report( array( 'limit' => $limit ) )['items'];
	}

	/**
	 * The report as CSV (UTF-8 with BOM, for Excel). Only URLs, kinds, reasons and numbers.
	 *
	 * @param array $f Filters.
	 */
	public static function csv( array $f = array() ): string {
		$rows = self::report( $f + array( 'limit' => 1000 ) )['items'];
		$out  = fopen( 'php://temp', 'r+' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'تاریخ', 'نوع تغییر', 'عنوان', 'آدرس', 'شرح', 'دلیل', 'رتبه قبل', 'کلیک قبل', 'نمایش قبل', 'رتبه فعلی', 'کلیک فعلی', 'نمایش فعلی', 'نتیجه', 'تغییرات هم‌زمان' ) );
		foreach ( $rows as $r ) {
			$b = $r['before'] ?? array();
			$n = $r['now'] ?? array();
			fputcsv(
				$out,
				array(
					substr( $r['created_at'], 0, 10 ),
					$r['kind_label'],
					$r['title'],
					rawurldecode( $r['url'] ),
					$r['summary'],
					$r['reason'],
					$b['position'] ?? '',
					$b['clicks'] ?? '',
					$b['impressions'] ?? '',
					$n['position'] ?? '',
					$n['clicks'] ?? '',
					$n['impressions'] ?? '',
					$r['verdict_label'] ?? 'هنوز زود است',
					$r['overlap'],
				)
			);
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	/**
	 * Only the numbers that matter.
	 *
	 * @param array $m Metrics.
	 */
	private static function slim( array $m ): array {
		return array(
			'clicks'      => (int) ( $m['clicks'] ?? 0 ),
			'impressions' => (int) ( $m['impressions'] ?? 0 ),
			'ctr'         => (float) ( $m['ctr'] ?? 0 ),
			'position'    => (float) ( $m['position'] ?? 0 ),
		);
	}
}
