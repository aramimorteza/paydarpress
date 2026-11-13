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

defined( 'ABSPATH' ) || exit;

/**
 * A change is measured once a Search Console export covers at least MIN_DAYS after it.
 */
final class ChangeLog {

	public const MIN_DAYS = 28;

	public const KINDS = array(
		'rewrite' => 'بازنویسی',
		'meta'    => 'عنوان و متا',
		'term'    => 'متن دسته',
		'merge'   => 'ادغام',
	);

	public const VERDICTS = array(
		'better'  => 'بهتر شد',
		'same'    => 'تغییر محسوسی نداشت',
		'worse'   => 'بدتر شد',
		'no_data' => 'داده کافی نیست',
	);

	/**
	 * Records a change.
	 *
	 * @param string $kind    Kind (see KINDS).
	 * @param int    $id      Post (or term) id.
	 * @param string $summary Short description.
	 * @param string $object  post|term.
	 */
	public static function record( string $kind, int $id, string $summary, string $object = 'post' ): void {
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
				'user_id'        => get_current_user_id(),
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Measures changes the latest Search Console import can judge (called after each import).
	 *
	 * @return int Changes measured.
	 */
	public static function measure(): int {
		global $wpdb;
		$import = GscRepository::latest_import();
		$end    = $import['summary']['period']['end'] ?? null;
		if ( ! $end ) {
			return 0;
		}
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( $end . ' 23:59:59' ) - self::MIN_DAYS * DAY_IN_SECONDS );
		$table   = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_id, object_kind, before_metrics FROM {$table} WHERE measured_at IS NULL AND created_at <= %s", $cutoff ), ARRAY_A );
		$metrics = GscRepository::page_metrics();
		$n       = 0;
		foreach ( (array) $rows as $r ) {
			$before = json_decode( (string) $r['before_metrics'], true );
			$after  = 'post' === $r['object_kind'] ? ( $metrics[ (int) $r['object_id'] ] ?? null ) : null;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'after_metrics' => null === $after ? null : wp_json_encode( self::slim( $after ) ),
					'verdict'       => self::verdict( is_array( $before ) ? $before : null, $after ),
					'measured_at'   => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $r['id'] )
			);
			++$n;
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
	 * Recent changes for the UI.
	 *
	 * @param int $limit Limit.
	 */
	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		$table = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return array_map(
			static function ( $r ) {
				$id   = (int) $r['object_id'];
				$term = 'term' === $r['object_kind'] ? get_term( $id ) : null;
				return array(
					'id'         => (int) $r['id'],
					'kind'       => $r['kind'],
					'kind_label' => self::KINDS[ $r['kind'] ] ?? $r['kind'],
					'title'      => $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
					'edit_url'   => $term instanceof \WP_Term ? (string) get_edit_term_link( $id, $term->taxonomy ) : (string) get_edit_post_link( $id, 'raw' ),
					'summary'    => $r['summary'],
					'before'     => json_decode( (string) $r['before_metrics'], true ),
					'after'      => json_decode( (string) $r['after_metrics'], true ),
					'verdict'    => $r['verdict'],
					'verdict_label' => $r['verdict'] ? ( self::VERDICTS[ $r['verdict'] ] ?? '' ) : null,
					'created_at' => gmdate( 'c', (int) strtotime( $r['created_at'] . ' UTC' ) ),
					'measure_after' => gmdate( 'c', (int) strtotime( $r['created_at'] . ' UTC' ) + self::MIN_DAYS * DAY_IN_SECONDS ),
				);
			},
			(array) $rows
		);
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
