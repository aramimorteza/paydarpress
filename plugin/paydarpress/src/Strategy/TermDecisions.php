<?php
/**
 * Decision cards for categories and tags: empty, thin, or reported as not fitting some posts
 * (classification). Each term is asked about once; the decision is kept in the taxonomy map.
 *
 * Options: keep (with a plan: tag fitting posts, topics, noindex until it has 3 posts),
 * merge into another term (posts move, archive redirects), delete (archive redirects to the
 * nearest category), later (30 days). Product categories never get article topics.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Profile\ProfileRepository;
use PaydarWP\PaydarPress\Profile\TaxonomyMap;
use PaydarWP\PaydarPress\Seo\SeoDetector;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Decisions live in the taxonomy map notes: decision = keep|later, decided_at, until.
 */
final class TermDecisions {

	/** A kept term is «grown» (indexed again, intro text) from this many items. */
	public const GROWN = 3;

	public const LATER_DAYS = 30;

	public const PER_DAY = 3;

	public const REASONS = array(
		'empty'  => 'بدون مطلب',
		'thin'   => 'کم‌مطلب',
		'misfit'    => 'به برخی مطالبش نمی‌خورد',
		'duplicate' => 'تکراری',
	);

	/**
	 * Terms waiting for a decision (without details).
	 *
	 * @param string $taxonomy Filter (empty: all).
	 * @return array<int, array{id: int, taxonomy: string, reason: string}>
	 */
	public static function pending( string $taxonomy = '' ): array {
		$notes   = TaxonomyMap::notes();
		$flags   = self::flags_by_term();
		$default = (int) get_option( 'default_category' );
		$thin    = array(
			'category'    => max( 1, (int) Settings::get( 'audit.weak_category_min', 3 ) ),
			'post_tag'    => 2,
			'product_cat' => 1,
			'product_tag' => 2,
		);
		$dups    = \PaydarWP\PaydarPress\Improve\TagProgram::merge_map();
		$targets = array_flip( array_map( static fn( $d ) => (int) $d['into']['id'], $dups ) );
		$out     = array();
		foreach ( array_keys( TaxonomyMap::taxonomies() ) as $tax ) {
			if ( '' !== $taxonomy && $tax !== $taxonomy ) {
				continue;
			}
			foreach ( (array) get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 0 ) ) as $t ) {
				if ( ! $t instanceof WP_Term || (int) $t->term_id === $default ) {
					continue;
				}
				$n        = $notes[ (int) $t->term_id ] ?? array();
				$decision = (string) ( $n['decision'] ?? '' );
				$until    = (int) ( $n['until'] ?? 0 );
				$misfit   = isset( $flags[ (int) $t->term_id ] );
				$reason   = $misfit ? 'misfit' : ( isset( $dups[ (int) $t->term_id ] ) ? 'duplicate' : ( 0 === (int) $t->count ? 'empty' : ( (int) $t->count < $thin[ $tax ] ? 'thin' : '' ) ) );
				// A merge target gets its posts from the duplicate card; no separate "thin" card.
				if ( '' === $reason || ( isset( $targets[ (int) $t->term_id ] ) && in_array( $reason, array( 'thin', 'empty' ), true ) ) ) {
					continue;
				}
				// A kept term is not asked again (only new misfit reports reopen it).
				if ( 'keep' === $decision && ! ( $misfit && (int) ( $flags[ (int) $t->term_id ]['at'] ?? 0 ) > (int) ( $n['decided_at'] ?? 0 ) ) ) {
					continue;
				}
				if ( 'later' === $decision && $until > time() ) {
					continue;
				}
				$out[] = array(
					'id'       => (int) $t->term_id,
					'taxonomy' => $tax,
					'reason'   => $reason,
					'count'    => (int) $t->count,
				);
			}
		}
		// Misfit reports first, then empty, then thin.
		$order = array( 'misfit' => 0, 'duplicate' => 1, 'empty' => 2, 'thin' => 3 );
		usort( $out, static fn( $a, $b ) => array( $order[ $a['reason'] ], $a['count'] ) <=> array( $order[ $b['reason'] ], $b['count'] ) );
		return $out;
	}

	/**
	 * Page of cards with details.
	 *
	 * @param string $taxonomy Filter.
	 * @param int    $page     Page.
	 * @param int    $per      Per page.
	 */
	public static function page( string $taxonomy = '', int $page = 1, int $per = 20 ): array {
		$all   = self::pending( $taxonomy );
		$items = array();
		foreach ( array_slice( $all, ( max( 1, $page ) - 1 ) * $per, $per ) as $p ) {
			$card = self::card( $p['id'], $p['reason'] );
			if ( $card ) {
				$items[] = $card;
			}
		}
		$counts = array();
		foreach ( $all as $p ) {
			$counts[ $p['taxonomy'] ] = ( $counts[ $p['taxonomy'] ] ?? 0 ) + 1;
		}
		return array(
			'items'  => $items,
			'total'  => count( $all ),
			'pages'  => (int) ceil( count( $all ) / $per ),
			'counts' => $counts,
		);
	}

	/**
	 * Everything the owner needs to decide about one term.
	 *
	 * @param int    $term_id Term.
	 * @param string $reason  empty|thin|misfit.
	 */
	public static function card( int $term_id, string $reason = '' ): ?array {
		$t = get_term( $term_id );
		if ( ! $t instanceof WP_Term || ! isset( TaxonomyMap::TAXONOMIES[ $t->taxonomy ] ) ) {
			return null;
		}
		$name    = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
		$demand  = self::demand( $name );
		$matches = self::matches( $t, 10 );
		$overlap = self::overlap( $t );
		$flags   = self::flags_by_term()[ $term_id ]['posts'] ?? array();
		$link    = get_term_link( $t );
		$product = 'product_cat' === $t->taxonomy || 'product_tag' === $t->taxonomy;

		// Duplicate found by the weekly sweep: the higher-value twin goes first.
		$dup = 'duplicate' === $reason ? ( \PaydarWP\PaydarPress\Improve\TagProgram::merge_map()[ $term_id ] ?? null ) : null;
		if ( $dup ) {
			$overlap = array_merge( array( array( 'id' => (int) $dup['into']['id'], 'name' => $dup['into']['name'], 'count' => (int) $dup['into']['count'] ) ), array_values( array_filter( $overlap, static fn( $o ) => (int) $o['id'] !== (int) $dup['into']['id'] ) ) );
		}

		// Rule-based recommendation (the decision is the owner's).
		if ( $dup ) {
			$rec = array( 'merge', 'تکراری «' . $dup['into']['name'] . '» است (' . $dup['why'] . ')؛ ادغام در نسخه باارزش‌تر، مطالب را یکجا جمع می‌کند و آرشیو تکراری ریدایرکت می‌شود.' );
		} elseif ( 'misfit' === $reason ) {
			$rec = array( 'remove_posts', 'به نظر هوش مصنوعی این مطالب به این مورد نمی‌خورند؛ اگر موافقید، فقط از همین مطالب برداشته شود.' );
		} elseif ( $overlap ) {
			$rec = array( 'merge', 'با «' . $overlap[0]['name'] . '» هم‌معناست؛ ادغام، آرشیوهای تکراری را یکی می‌کند.' );
		} elseif ( $demand['impressions'] > 0 || count( $matches ) >= 2 ) {
			$rec = array( 'keep', $demand['impressions'] > 0 ? 'در گوگل جست‌وجو می‌شود؛ ارزش نگه داشتن دارد.' : 'مطالب موجودی هست که به آن می‌خورند؛ با برچسب زدن آن‌ها پر می‌شود.' );
		} else {
			$rec = array( 'delete', 'نه جست‌وجو دارد و نه مطلب مناسب؛ آرشیو خالی برای کاربر و گوگل بی‌فایده است.' );
		}

		return array(
			'id'        => $term_id,
			'name'      => $name,
			'taxonomy'  => $t->taxonomy,
			'tax_label' => TaxonomyMap::TAXONOMIES[ $t->taxonomy ],
			'count'     => (int) $t->count,
			'url'       => is_string( $link ) ? $link : '',
			'reason'    => $reason,
			'reason_label' => self::REASONS[ $reason ] ?? '',
			'role'      => TaxonomyMap::role( $term_id ),
			'demand'    => $demand,
			'matches'   => $matches,
			'overlap'   => $overlap,
			'misfit'    => array_map( static fn( $p ) => array( 'id' => (int) $p['post'], 'title' => html_entity_decode( get_the_title( (int) $p['post'] ), ENT_QUOTES, 'UTF-8' ), 'reason' => $p['reason'] ), $flags ),
			'product'   => $product,
			'can_topics' => ! $product,
			'noindex_ok' => in_array( SeoDetector::adapter()->key(), array( 'yoast', 'rankmath' ), true ),
			'recommend' => array( 'action' => $rec[0], 'why' => $rec[1] ),
		);
	}

	/**
	 * Applies a decision.
	 *
	 * @param int    $term_id Term.
	 * @param string $action  keep | merge | delete | later | remove_posts | hide.
	 * @param array  $args    into (merge target id), posts (ids to tag on keep or to untag on remove_posts).
	 * @return array{ok: bool, message: string}
	 * @throws RuntimeException On invalid input.
	 */
	public static function decide( int $term_id, string $action, array $args = array() ): array {
		$t      = get_term( $term_id );
		$name   = $t instanceof WP_Term ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : '';
		$result = self::run_decision( $term_id, $action, $args );
		if ( 'later' !== $action && ! empty( $result['ok'] ) && $t instanceof WP_Term ) {
			$label = array( 'keep' => 'نگه داشته شد', 'merge' => 'ادغام شد', 'delete' => 'حذف شد', 'remove_posts' => 'از مطالب نامرتبط برداشته شد', 'hide' => 'پنهان شد' )[ $action ] ?? $action;
			ChangeLog::record( 'decision', $term_id, '«' . $name . '» ' . $label . ': ' . mb_substr( (string) $result['message'], 0, 160 ), 'term', ( TaxonomyMap::TAXONOMIES[ $t->taxonomy ] ?? '' ) . ' با ' . PersianNormalizer::fa_number( (int) $t->count ) . ' مطلب' );
		}
		return $result;
	}

	/**
	 * Carries out one decision.
	 *
	 * @param int    $term_id Term.
	 * @param string $action  Action.
	 * @param array  $args    Arguments.
	 * @throws RuntimeException On invalid input.
	 */
	private static function run_decision( int $term_id, string $action, array $args = array() ): array {
		$t = get_term( $term_id );
		if ( ! $t instanceof WP_Term || ! isset( TaxonomyMap::TAXONOMIES[ $t->taxonomy ] ) ) {
			throw new RuntimeException( 'دسته یا برچسب پیدا نشد.' );
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			throw new RuntimeException( 'اجازه مدیریت دسته‌ها و برچسب‌ها را ندارید.' );
		}
		$name = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );

		switch ( $action ) {
			case 'keep':
				$posts = array_map( 'intval', (array) ( $args['posts'] ?? array() ) );
				$added = 0;
				foreach ( $posts as $pid ) {
					if ( get_post( $pid ) && current_user_can( 'edit_post', $pid ) && ! has_term( $term_id, $t->taxonomy, $pid ) ) {
						wp_set_object_terms( $pid, array( $term_id ), $t->taxonomy, true );
						++$added;
					}
				}
				clean_term_cache( $term_id, $t->taxonomy );
				$t      = get_term( $term_id );
				$noidx  = false;
				if ( (int) $t->count < self::GROWN ) {
					$noidx = self::set_noindex( $t, true );
				}
				self::note(
					$term_id,
					array(
						'decision'   => 'keep',
						'decided_at' => time(),
						'pp_noindex' => $noidx ? 1 : 0,
						'role'       => in_array( TaxonomyMap::role( $term_id ), TaxonomyMap::ACTIVE_ROLES, true ) ? TaxonomyMap::role( $term_id ) : 'normal',
					)
				);
				self::clear_flags( $term_id );
				Activity::log( 'term.keep', sprintf( '«%s» نگه داشته شد%s.', $name, $added ? sprintf( ' و به %s مطلب اضافه شد', PersianNormalizer::fa_number( $added ) ) : '' ) );
				return array(
					'ok'      => true,
					'message' => trim(
						'نگه داشته شد.' . ( $added ? ' به ' . PersianNormalizer::fa_number( $added ) . ' مطلب اضافه شد.' : '' )
						. ( $noidx ? ' تا رسیدن به ' . PersianNormalizer::fa_number( self::GROWN ) . ' مطلب، آرشیوش noindex می‌ماند و بعد خودکار ایندکس می‌شود.' : '' )
					),
				);

			case 'later':
				self::note( $term_id, array( 'decision' => 'later', 'decided_at' => time(), 'until' => time() + self::LATER_DAYS * DAY_IN_SECONDS ) );
				return array( 'ok' => true, 'message' => PersianNormalizer::fa_number( self::LATER_DAYS ) . ' روز دیگر دوباره پرسیده می‌شود.' );

			case 'remove_posts':
				$removed = 0;
				foreach ( array_map( 'intval', (array) ( $args['posts'] ?? array() ) ) as $pid ) {
					if ( current_user_can( 'edit_post', $pid ) && has_term( $term_id, $t->taxonomy, $pid ) ) {
						wp_remove_object_terms( $pid, $term_id, $t->taxonomy );
						++$removed;
					}
				}
				self::clear_flags( $term_id );
				self::note( $term_id, array( 'decision' => 'keep', 'decided_at' => time() ) );
				return array( 'ok' => true, 'message' => sprintf( 'از %s مطلب برداشته شد.', PersianNormalizer::fa_number( $removed ) ) );

			case 'merge':
				$into = get_term( (int) ( $args['into'] ?? 0 ) );
				if ( ! $into instanceof WP_Term || (int) $into->term_id === $term_id || ! in_array( $into->taxonomy, self::compatible( $t->taxonomy ), true ) ) {
					throw new RuntimeException( 'مقصد ادغام را از همان نوع (یا دسته هم‌خانواده) انتخاب کنید.' );
				}
				$old   = get_term_link( $t );
				$posts = get_objects_in_term( $term_id, $t->taxonomy );
				foreach ( (array) $posts as $pid ) {
					wp_set_object_terms( (int) $pid, array( (int) $into->term_id ), $into->taxonomy, true );
				}
				wp_delete_term( $term_id, $t->taxonomy );
				$msg = self::redirect_archive( is_string( $old ) ? $old : '', $into );
				Activity::log( 'term.merge', sprintf( '«%s» در «%s» ادغام شد (%s مطلب).', $name, html_entity_decode( $into->name, ENT_QUOTES, 'UTF-8' ), PersianNormalizer::fa_number( count( (array) $posts ) ) ) );
				return array( 'ok' => true, 'message' => sprintf( 'در «%s» ادغام شد؛ %s مطلب منتقل شد. %s', html_entity_decode( $into->name, ENT_QUOTES, 'UTF-8' ), PersianNormalizer::fa_number( count( (array) $posts ) ), $msg ) );

			case 'delete':
				$old    = get_term_link( $t );
				$target = self::nearest_category( $t );
				wp_delete_term( $term_id, $t->taxonomy );
				$msg = $target ? self::redirect_archive( is_string( $old ) ? $old : '', $target ) : '';
				Activity::log( 'term.delete', sprintf( '«%s» حذف شد.', $name ) );
				return array( 'ok' => true, 'message' => trim( 'حذف شد. ' . $msg ) );

			case 'hide':
				// Product categories: kept for the shop, out of Google and of new content.
				$noidx = self::set_noindex( $t, true );
				self::note( $term_id, array( 'decision' => 'keep', 'decided_at' => time(), 'role' => 'nav', 'pp_noindex' => $noidx ? 1 : 0 ) );
				return array( 'ok' => true, 'message' => 'پنهان شد: نقش «فقط ناوبری» گرفت' . ( $noidx ? ' و آرشیوش noindex شد.' : '.' ) );
		}
		throw new RuntimeException( 'اقدام نامعتبر.' );
	}

	/**
	 * Kept terms that reached GROWN items are indexed again (only those PaydarPress noindexed).
	 *
	 * @return int Terms indexed again.
	 */
	public static function sync(): int {
		$n = 0;
		foreach ( TaxonomyMap::notes() as $id => $note ) {
			if ( empty( $note['pp_noindex'] ) ) {
				continue;
			}
			$t = get_term( (int) $id );
			if ( $t instanceof WP_Term && (int) $t->count >= self::GROWN ) {
				self::set_noindex( $t, false );
				self::note( (int) $id, array( 'pp_noindex' => 0 ) );
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Today: at most PER_DAY cards.
	 *
	 * @return array[] id, name, taxonomy label, reason label.
	 */
	public static function today(): array {
		$out = array();
		foreach ( array_slice( self::pending(), 0, self::PER_DAY ) as $p ) {
			$t = get_term( $p['id'] );
			if ( $t instanceof WP_Term ) {
				$out[] = array(
					'id'     => $p['id'],
					'name'   => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
					'tax'    => TaxonomyMap::TAXONOMIES[ $t->taxonomy ],
					'reason' => self::REASONS[ $p['reason'] ],
				);
			}
		}
		return $out;
	}

	/**
	 * Search demand from Search Console queries containing the term.
	 *
	 * @param string $name Term name.
	 * @return array{impressions: int, clicks: int, queries: string[]}
	 */
	private static function demand( string $name ): array {
		global $wpdb;
		$name = trim( $name );
		if ( mb_strlen( $name ) < 2 ) {
			return array( 'impressions' => 0, 'clicks' => 0, 'queries' => array() );
		}
		$like = '%' . $wpdb->esc_like( $name ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT item, impressions, clicks FROM ' . Schema::table( 'gsc' ) . " WHERE kind = 'query' AND item LIKE %s ORDER BY impressions DESC LIMIT 50", $like ), ARRAY_A );
		$imp  = 0;
		$cl   = 0;
		foreach ( (array) $rows as $r ) {
			$imp += (int) $r['impressions'];
			$cl  += (int) $r['clicks'];
		}
		return array(
			'impressions' => $imp,
			'clicks'      => $cl,
			'queries'     => array_slice( array_map( static fn( $r ) => (string) $r['item'], (array) $rows ), 0, 3 ),
		);
	}

	/**
	 * Published items of the right type that mention the term in their title or focus keyword
	 * and do not have it yet.
	 *
	 * @param WP_Term $t     Term.
	 * @param int     $limit Limit.
	 * @return array<int, array{id: int, title: string}>
	 */
	private static function matches( WP_Term $t, int $limit ): array {
		global $wpdb;
		$key = PersianNormalizer::for_key( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) );
		if ( mb_strlen( $key ) < 2 ) {
			return array();
		}
		$type = in_array( $t->taxonomy, array( 'product_cat', 'product_tag' ), true ) ? 'product' : 'post';
		$like = '%' . $wpdb->esc_like( $key ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM ' . Schema::table( 'inventory' ) . " WHERE object_type = %s AND status = 'publish' AND ( title_norm LIKE %s OR focus_keyword_norm LIKE %s ) LIMIT 60", $type, $like, $like ) );
		$out = array();
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( ! has_term( (int) $t->term_id, $t->taxonomy, $id ) ) {
				$out[] = array( 'id' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Terms with (nearly) the same name: plural/singular, one containing the other.
	 *
	 * @param WP_Term $t Term.
	 * @return array<int, array{id: int, name: string, taxonomy: string, count: int}>
	 */
	private static function overlap( WP_Term $t ): array {
		$norm = static fn( string $v ): string => (string) preg_replace( '/(\s*ها|\s*های|\s*ات)$/u', '', PersianNormalizer::compact( html_entity_decode( $v, ENT_QUOTES, 'UTF-8' ) ) );
		$key  = $norm( $t->name );
		if ( mb_strlen( $key ) < 2 ) {
			return array();
		}
		$out = array();
		foreach ( self::compatible( $t->taxonomy ) as $tax ) {
			foreach ( (array) get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 0 ) ) as $o ) {
				if ( ! $o instanceof WP_Term || (int) $o->term_id === (int) $t->term_id ) {
					continue;
				}
				$k = $norm( $o->name );
				if ( $k === $key || ( mb_strlen( $k ) >= 3 && mb_strlen( $key ) >= 3 && ( false !== mb_strpos( $k, $key ) || false !== mb_strpos( $key, $k ) ) ) ) {
					$out[] = array( 'id' => (int) $o->term_id, 'name' => html_entity_decode( $o->name, ENT_QUOTES, 'UTF-8' ), 'taxonomy' => $tax, 'count' => (int) $o->count );
				}
			}
		}
		usort( $out, static fn( $a, $b ) => $b['count'] <=> $a['count'] );
		return array_slice( $out, 0, 5 );
	}

	/**
	 * Taxonomies a term can be merged into.
	 *
	 * @param string $tax Taxonomy.
	 * @return string[]
	 */
	public static function compatible( string $tax ): array {
		$map = array(
			'category'    => array( 'category' ),
			'post_tag'    => array( 'post_tag' ),
			'product_cat' => array( 'product_cat' ),
			'product_tag' => array( 'product_tag' ),
		);
		return array_values( array_filter( $map[ $tax ] ?? array( $tax ), 'taxonomy_exists' ) );
	}

	/**
	 * Category an archive should lead to after deletion: the parent, or the category most of
	 * the tag's posts are in.
	 *
	 * @param WP_Term $t Term.
	 */
	private static function nearest_category( WP_Term $t ): ?WP_Term {
		if ( $t->parent ) {
			$p = get_term( (int) $t->parent, $t->taxonomy );
			if ( $p instanceof WP_Term ) {
				return $p;
			}
		}
		$cat_tax = in_array( $t->taxonomy, array( 'product_cat', 'product_tag' ), true ) ? 'product_cat' : 'category';
		$counts  = array();
		foreach ( (array) get_objects_in_term( (int) $t->term_id, $t->taxonomy ) as $pid ) {
			foreach ( (array) wp_get_object_terms( (int) $pid, $cat_tax, array( 'fields' => 'ids' ) ) as $cid ) {
				if ( (int) $cid !== (int) $t->term_id ) {
					$counts[ (int) $cid ] = ( $counts[ (int) $cid ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $counts );
		$first = $counts ? get_term( (int) array_key_first( $counts ), $cat_tax ) : null;
		return $first instanceof WP_Term ? $first : null;
	}

	/**
	 * Old archive → new archive (one owner per redirect; PaydarPress redirects run on 404 only).
	 *
	 * @param string  $old    Old archive URL.
	 * @param WP_Term $target Target term.
	 */
	private static function redirect_archive( string $old, WP_Term $target ): string {
		$new = get_term_link( $target );
		if ( '' === $old || ! is_string( $new ) ) {
			return '';
		}
		$owner = RedirectOwner::detect();
		if ( $owner && 'ok' === RedirectOwner::check( $old, $new ) ) {
			return 'آدرس آرشیو قبلی از قبل ریدایرکت می‌شود.';
		}
		Redirects::add( $old, $new, null, 'term' );
		return 'آدرس آرشیو قبلی با ۳۰۱ به «' . html_entity_decode( $target->name, ENT_QUOTES, 'UTF-8' ) . '» می‌رود.';
	}

	/**
	 * Sets or clears noindex of a term archive in Yoast / Rank Math.
	 *
	 * @param WP_Term $t       Term.
	 * @param bool    $noindex Noindex.
	 * @return bool Whether the SEO plugin supports it.
	 */
	public static function set_noindex( WP_Term $t, bool $noindex ): bool {
		$seo = SeoDetector::adapter()->key();
		if ( 'yoast' === $seo ) {
			$meta = get_option( 'wpseo_taxonomy_meta', array() );
			$meta = is_array( $meta ) ? $meta : array();
			if ( $noindex ) {
				$meta[ $t->taxonomy ][ $t->term_id ]['wpseo_noindex'] = 'noindex';
			} else {
				unset( $meta[ $t->taxonomy ][ $t->term_id ]['wpseo_noindex'] );
			}
			update_option( 'wpseo_taxonomy_meta', $meta );
			return true;
		}
		if ( 'rankmath' === $seo ) {
			$robots = get_term_meta( $t->term_id, 'rank_math_robots', true );
			$robots = is_array( $robots ) ? array_values( array_diff( $robots, array( 'noindex', 'index' ) ) ) : array();
			if ( $noindex ) {
				$robots[] = 'noindex';
				update_term_meta( $t->term_id, 'rank_math_robots', $robots );
			} elseif ( $robots ) {
				update_term_meta( $t->term_id, 'rank_math_robots', $robots );
			} else {
				delete_term_meta( $t->term_id, 'rank_math_robots' );
			}
			return true;
		}
		return false;
	}

	/**
	 * Merges values into a term's notes in the taxonomy map.
	 *
	 * @param int   $term_id Term.
	 * @param array $patch   Values.
	 */
	private static function note( int $term_id, array $patch ): void {
		$notes            = TaxonomyMap::notes();
		$current          = $notes[ $term_id ] ?? array( 'id' => $term_id );
		$notes[ $term_id ] = array_merge( $current, $patch );
		ProfileRepository::save_user( array( 'categories_scope' => array_values( $notes ) ) );
	}

	/**
	 * Misfit reports grouped by term.
	 *
	 * @return array<int, array{at: int, posts: array[]}>
	 */
	private static function flags_by_term(): array {
		$out = array();
		foreach ( Classifier::flags() as $f ) {
			$id                  = (int) $f['term'];
			$out[ $id ]['posts'][] = $f;
			$out[ $id ]['at']      = max( (int) ( $out[ $id ]['at'] ?? 0 ), (int) $f['at'] );
		}
		return $out;
	}

	/**
	 * Removes misfit reports of a term.
	 *
	 * @param int $term_id Term.
	 */
	private static function clear_flags( int $term_id ): void {
		$flags = array_filter( Classifier::flags(), static fn( $f ) => (int) $f['term'] !== $term_id );
		update_option( 'paydarpress_term_flags', $flags, false );
	}
}
