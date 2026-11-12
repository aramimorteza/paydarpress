<?php
/**
 * Runs all audit rules and synchronizes findings.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Audit;

use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Crawl\CrawlerCheck;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Seo\SeoDetector;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use PaydarWP\PaydarPress\Text\YearDetector;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules are pure reads of the inventory, link graph, taxonomies, users and settings
 * (spec section 7). No external requests except the optional robots.txt check.
 */
final class AuditEngine {

	public const OPTION_STATS = 'paydarpress_stats';

	/** Crawlers whose blocking hides the site from search (training-only bots are excluded). */
	private const SEARCH_BOTS = array( 'Googlebot', 'Bingbot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'PerplexityBot' );

	/**
	 * Findings of this run: "CODE|kind|id" => JSON details ('' when none). Kept as strings, not
	 * nested arrays, so sites with thousands of posts fit in a 64 MB memory limit.
	 *
	 * @var array<string, string>
	 */
	private $found = array();

	/** @var array<int, string> Space-separated codes per content ID (for refresh scores). */
	private $codes_by_content = array();

	/** @var array */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = (array) Settings::get( 'audit', array() );
	}

	/**
	 * Runs the audit.
	 *
	 * @param bool $check_crawlers Whether to fetch robots.txt (full audits only).
	 * @return array Stats.
	 */
	public function run( bool $check_crawlers = false ): array {
		$this->found            = array();
		$this->codes_by_content = array();

		$rows = $this->content_rows();

		$this->content_rules( $rows );
		$this->link_rules();
		$this->taxonomy_rules();
		$this->author_rules();
		$this->site_rules( $check_crawlers );

		$this->sync();
		$this->update_refresh_scores( $rows );

		return self::build_stats( count( array_filter( $rows, static fn( $r ) => 'publish' === $r['status'] ) ) );
	}

	/**
	 * Refresh score (0–100) of one content row. Spec section 7.5.
	 *
	 * @param array    $row   Inventory row.
	 * @param string[] $codes Open finding codes of this content.
	 * @param int      $stale_months Months after which content is stale.
	 */
	public static function refresh_score( array $row, array $codes, int $stale_months ): int {
		$weights = array(
			'OUTDATED_YEAR'           => 30,
			'THIN_CONTENT'            => 15,
			'DUPLICATE_FOCUS_KEYWORD' => 15,
			'NO_DIRECT_ANSWER'        => 5,
			'NO_INTERNAL_LINKS'       => 5,
			'BROKEN_INTERNAL_LINK'    => 5,
			'NO_H2'                   => 5,
		);

		$score = 0;
		foreach ( $weights as $code => $weight ) {
			if ( in_array( $code, $codes, true ) ) {
				$score += $weight;
			}
		}

		if ( in_array( 'IMG_NO_FEATURED', $codes, true ) || in_array( 'IMG_MISSING_ALT', $codes, true ) ) {
			$score += 5;
		}

		$modified = ! empty( $row['modified_at'] ) ? strtotime( $row['modified_at'] . ' UTC' ) : false;
		if ( false !== $modified && $modified < strtotime( '-' . max( 1, $stale_months ) . ' months' ) ) {
			$score += 20;
		}

		return min( 100, $score );
	}

	/**
	 * Health score 0–100 from the weighted findings per published item. Decays gradually so
	 * a site with many issues still shows progress as they get fixed:
	 * average weight 0 → 100, 2.5 → 73, 5 → 54, 12.5 → 21.
	 *
	 * @param float $weight    Sum of severity weights of open findings.
	 * @param int   $published Published content items.
	 */
	public static function health_score( float $weight, int $published ): ?int {
		if ( $published <= 0 ) {
			return null;
		}

		return (int) round( 100 * exp( -( $weight / $published ) / 8 ) );
	}

	/**
	 * Suggested action for a refresh score. Spec section 7.5.
	 *
	 * @param int      $score Refresh score.
	 * @param string[] $codes Finding codes.
	 * @return string `merge`, `rewrite`, `light` or `none`.
	 */
	public static function refresh_action( int $score, array $codes ): string {
		if ( in_array( 'DUPLICATE_FOCUS_KEYWORD', $codes, true ) ) {
			return 'merge';
		}
		if ( $score >= 50 ) {
			return 'rewrite';
		}

		return $score >= 20 ? 'light' : 'none';
	}

	/**
	 * Content IDs that are system pages and must not be reported as orphans.
	 *
	 * @return int[]
	 */
	public static function system_page_ids(): array {
		$ids = array(
			(int) get_option( 'page_on_front' ),
			(int) get_option( 'page_for_posts' ),
			(int) get_option( 'wp_page_for_privacy_policy' ),
		);

		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $wc_page ) {
			$ids[] = (int) get_option( 'woocommerce_' . $wc_page . '_page_id' );
		}

		$ids = array_merge( $ids, array_map( 'intval', (array) Settings::get( 'audit.orphan_exclude_ids', array() ) ) );

		return array_values( array_filter( array_unique( $ids ) ) );
	}

	// ---------------------------------------------------------------------
	// Rules.
	// ---------------------------------------------------------------------

	/**
	 * Inventory-based rules.
	 *
	 * @param array $rows Inventory rows.
	 */
	private function content_rules( array $rows ): void {
		$system      = array_flip( self::system_page_ids() );
		$seo_active  = 'none' !== SeoDetector::adapter()->key();
		$years       = new YearDetector();
		$key_pages   = array_flip( array_map( 'intval', (array) apply_filters( 'paydarpress/key_page_ids', array() ) ) );
		$thin        = (int) ( $this->settings['thin_words'] ?? 300 );
		$weak_min    = (int) ( $this->settings['weak_inbound_min'] ?? 3 );
		$inline_min  = (int) ( $this->settings['inline_images_min_words'] ?? 800 );
		$by_keyword  = array();

		foreach ( $rows as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}

			$id        = (int) $row['object_id'];
			$type      = $row['object_type'];
			$indexable = '0' === (string) $row['is_noindex'];
			$is_system = isset( $system[ $id ] );
			$in        = (int) $row['internal_in'];
			$menu      = (int) $row['menu_in'];
			$words     = (int) $row['word_count'];

			// Links.
			if ( $indexable && ! $is_system ) {
				if ( 'post' === $type && 0 === $in ) {
					$this->add( 'ORPHAN_POST', 'content', $id, $menu > 0 ? array( 'menu_in' => $menu ) : null );
				} elseif ( 'page' === $type && 0 === $in && 0 === $menu ) {
					$this->add( 'ORPHAN_PAGE', 'content', $id );
				} elseif ( 'product' === $type && 0 === $in ) {
					$this->add( 'ORPHAN_PRODUCT', 'content', $id );
				}

				if ( 0 === $in && $menu > 0 && 'post' !== $type ) {
					$this->add( 'MENU_ONLY', 'content', $id, array( 'menu_in' => $menu ) );
				}

				$important = '1' === (string) $row['is_cornerstone'] || isset( $key_pages[ $id ] );
				if ( $important && $in > 0 && $in < $weak_min ) {
					$this->add( 'WEAK_INBOUND', 'content', $id, array( 'internal_in' => $in, 'minimum' => $weak_min ) );
				}
			}

			if ( 'post' === $type && 0 === (int) $row['internal_out'] ) {
				$this->add( 'NO_INTERNAL_LINKS', 'content', $id );
			}

			// Content and SEO (posts and products; pages such as "contact" are exempt).
			if ( in_array( $type, array( 'post', 'product' ), true ) && $indexable ) {
				if ( $seo_active && '1' === (string) $row['no_keyword'] ) {
					$this->add( 'NO_FOCUS_KEYWORD', 'content', $id );
				}
				if ( $seo_active && '1' === (string) $row['no_meta'] ) {
					$this->add( 'NO_META_DESCRIPTION', 'content', $id );
				}
				if ( null !== $row['focus_keyword_norm'] && '' !== $row['focus_keyword_norm'] ) {
					$by_keyword[ $row['focus_keyword_norm'] ][] = $id;
				}
			}

			if ( 'page' !== $type || ! $is_system ) {
				$found_years = json_decode( (string) $row['years_mentioned'], true );
				$outdated    = $years->outdated( is_array( $found_years ) ? array_map( 'intval', $found_years ) : array() );
				if ( array() !== $outdated ) {
					$this->add( 'OUTDATED_YEAR', 'content', $id, array( 'years' => $outdated ) );
				}
			}

			if ( 'post' === $type ) {
				if ( $words < $thin ) {
					$this->add( 'THIN_CONTENT', 'content', $id, array( 'words' => $words, 'minimum' => $thin ) );
				}
				if ( $words >= 300 && 0 === (int) $row['h2_count'] ) {
					$this->add( 'NO_H2', 'content', $id );
				}
				if ( $words >= 300 && '0' === (string) $row['has_direct_answer'] ) {
					$this->add( 'NO_DIRECT_ANSWER', 'content', $id );
				}
				if ( '0' === (string) $row['has_featured_image'] ) {
					$this->add( 'IMG_NO_FEATURED', 'content', $id );
				}
				if ( $words >= $inline_min && 0 === (int) $row['inline_images'] ) {
					$this->add( 'IMG_NO_INLINE', 'content', $id, array( 'words' => $words ) );
				}
			}

			// Images (all content types).
			$missing_alt = (int) $row['inline_images_no_alt'] + ( ( '1' === (string) $row['has_featured_image'] && '0' === (string) $row['featured_alt_ok'] ) ? 1 : 0 );
			if ( $missing_alt > 0 ) {
				$this->add( 'IMG_MISSING_ALT', 'content', $id, array( 'count' => $missing_alt ) );
			}
			if ( (int) $row['images_generic_alt'] > 0 ) {
				$this->add( 'IMG_GENERIC_ALT', 'content', $id, array( 'count' => (int) $row['images_generic_alt'] ) );
			}
		}

		foreach ( $by_keyword as $keyword => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			foreach ( $ids as $id ) {
				$this->add(
					'DUPLICATE_FOCUS_KEYWORD',
					'content',
					$id,
					array(
						'keyword' => $keyword,
						'others'  => array_values( array_diff( $ids, array( $id ) ) ),
					)
				);
			}
		}
	}

	/**
	 * Broken and redirected internal links, one finding per source content.
	 */
	private function link_rules(): void {
		global $wpdb;

		$links     = Schema::table( 'links' );
		$inventory = Schema::table( 'inventory' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT l.source_id, l.target_state, l.target_url, l.anchor FROM {$links} l
			INNER JOIN {$inventory} i ON i.object_id = l.source_id
			WHERE l.source_type = 'content' AND l.link_type = 'internal' AND i.status = 'publish'
				AND l.target_state IN ('missing', 'unpublished', 'redirect')
			ORDER BY l.source_id, l.id",
			ARRAY_A
		);

		$broken     = array();
		$redirected = array();

		foreach ( (array) $rows as $row ) {
			$item = array(
				'url'    => $row['target_url'],
				'anchor' => $row['anchor'],
				'state'  => $row['target_state'],
			);
			if ( 'redirect' === $row['target_state'] ) {
				$redirected[ (int) $row['source_id'] ][] = $item;
			} else {
				$broken[ (int) $row['source_id'] ][] = $item;
			}
		}

		foreach ( $broken as $id => $items ) {
			$this->add( 'BROKEN_INTERNAL_LINK', 'content', $id, array( 'links' => array_slice( $items, 0, 20 ), 'count' => count( $items ) ) );
		}
		foreach ( $redirected as $id => $items ) {
			$this->add( 'REDIRECTED_INTERNAL_LINK', 'content', $id, array( 'links' => array_slice( $items, 0, 20 ), 'count' => count( $items ) ) );
		}
	}

	/**
	 * Category and tag rules.
	 */
	private function taxonomy_rules(): void {
		$cat_taxonomies = array_values( array_filter( array( 'category', 'product_cat' ), 'taxonomy_exists' ) );
		$tag_taxonomies = array_values( array_filter( array( 'post_tag', 'product_tag' ), 'taxonomy_exists' ) );
		$weak_min       = (int) ( $this->settings['weak_category_min'] ?? 3 );
		$default_cat    = (int) get_option( 'default_category' );
		$adapter        = SeoDetector::adapter();
		$thin_archives  = array();

		$categories = $this->terms( $cat_taxonomies );
		$tags       = $this->terms( $tag_taxonomies );

		// Categories.
		$cat_keys = array();
		foreach ( $categories as $term ) {
			$count = (int) $term->count;

			if ( 0 === $count && (int) $term->term_id !== $default_cat ) {
				$this->add( 'EMPTY_CATEGORY', 'term', $term->term_id );
				$thin_archives[] = $term;
			} elseif ( $count > 0 && $count < $weak_min ) {
				$this->add( 'WEAK_CATEGORY', 'term', $term->term_id, array( 'count' => $count, 'minimum' => $weak_min ) );
				$thin_archives[] = $term;
			}

			if ( $count > 0 && mb_strlen( trim( wp_strip_all_tags( $term->description ) ) ) < 50 ) {
				$this->add( 'EMPTY_CATEGORY_DESC', 'term', $term->term_id );
			}

			$cat_keys[ $term->taxonomy ][ $term->term_id ] = PersianNormalizer::compact( $term->name );
		}

		// Overload: one category holds more than 60% of published posts (at least 10 posts).
		$published_posts = (int) wp_count_posts( 'post' )->publish;
		if ( $published_posts >= 10 ) {
			foreach ( $categories as $term ) {
				if ( 'category' === $term->taxonomy && (int) $term->count / $published_posts > 0.6 ) {
					$this->add( 'SINGLE_CATEGORY_OVERLOAD', 'term', $term->term_id, array( 'count' => (int) $term->count, 'total' => $published_posts ) );
				}
			}
		}

		// Similar categories (same taxonomy): equal names or one contains the other.
		$counts = array();
		foreach ( $categories as $term ) {
			$counts[ $term->term_id ] = (int) $term->count;
		}
		foreach ( $cat_keys as $keys ) {
			$ids = array_keys( $keys );
			$n   = count( $ids );
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$a = $keys[ $ids[ $i ] ];
					$b = $keys[ $ids[ $j ] ];
					if ( '' === $a || '' === $b ) {
						continue;
					}
					$similar = $a === $b || ( mb_strlen( $a ) >= 4 && mb_strlen( $b ) >= 4 && ( false !== mb_strpos( $a, $b ) || false !== mb_strpos( $b, $a ) ) );
					if ( $similar ) {
						$smaller = $counts[ $ids[ $i ] ] <= $counts[ $ids[ $j ] ] ? $ids[ $i ] : $ids[ $j ];
						$other   = $smaller === $ids[ $i ] ? $ids[ $j ] : $ids[ $i ];
						$this->add( 'SIMILAR_CATEGORIES', 'term', $smaller, array( 'similar_to' => $other ) );
					}
				}
			}
		}

		// Tags.
		$all_cat_keys = array();
		foreach ( $cat_keys as $keys ) {
			foreach ( $keys as $id => $key ) {
				$all_cat_keys[ $key ] = $id;
			}
		}

		$tag_groups = array();
		foreach ( $tags as $term ) {
			$count = (int) $term->count;
			$key   = PersianNormalizer::compact( $term->name );

			if ( 0 === $count ) {
				$this->add( 'EMPTY_TAG', 'term', $term->term_id );
				$thin_archives[] = $term;
			} elseif ( 1 === $count ) {
				$this->add( 'THIN_TAG', 'term', $term->term_id );
				$thin_archives[] = $term;
			}

			if ( '' !== $key && isset( $all_cat_keys[ $key ] ) ) {
				$this->add( 'TAG_EQUALS_CATEGORY', 'term', $term->term_id, array( 'category' => $all_cat_keys[ $key ] ) );
			}

			if ( '' !== $key ) {
				$tag_groups[ $term->taxonomy . '|' . $key ][] = $term;
			}
		}

		foreach ( $tag_groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}
			usort( $group, static fn( $a, $b ) => (int) $b->count <=> (int) $a->count );
			$keep = array_shift( $group );
			foreach ( $group as $term ) {
				$this->add( 'NEAR_DUPLICATE_TAGS', 'term', $term->term_id, array( 'duplicate_of' => $keep->term_id ) );
			}
		}

		// Posts with too many tags.
		$this->tag_overuse_rule( $tag_taxonomies );

		// Thin archives that search engines may index: one site-level finding.
		$indexed = array();
		foreach ( $thin_archives as $term ) {
			if ( ! $adapter->term_noindex( $term ) ) {
				$indexed[] = $term->term_id;
			}
		}
		if ( array() !== $indexed ) {
			$this->add( 'INDEXED_THIN_ARCHIVE', 'site', 0, array( 'count' => count( $indexed ), 'terms' => array_slice( $indexed, 0, 200 ) ) );
		}
	}

	/**
	 * TAG_OVERUSE: published posts with more tags than the limit.
	 *
	 * @param string[] $tag_taxonomies Tag taxonomies.
	 */
	private function tag_overuse_rule( array $tag_taxonomies ): void {
		global $wpdb;

		if ( array() === $tag_taxonomies ) {
			return;
		}

		$max = (int) ( $this->settings['tag_overuse_max'] ?? 8 );
		$in  = implode( ',', array_map( static fn( $t ) => "'" . esc_sql( $t ) . "'", $tag_taxonomies ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id AS id, COUNT(*) AS n FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy IN ({$in}) AND p.post_status = 'publish'
				GROUP BY tr.object_id HAVING COUNT(*) > %d",
				$max
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$this->add( 'TAG_OVERUSE', 'content', (int) $row['id'], array( 'count' => (int) $row['n'], 'maximum' => $max ) );
		}
	}

	/**
	 * Author rules for users with published content.
	 */
	private function author_rules(): void {
		$counts = count_many_users_posts( get_users( array( 'fields' => 'ID', 'number' => 500, 'capability' => 'edit_posts' ) ), 'post', true );

		foreach ( $counts as $user_id => $count ) {
			if ( (int) $count < 1 ) {
				continue;
			}
			$user = get_userdata( (int) $user_id );
			if ( ! $user ) {
				continue;
			}

			if ( '' === trim( (string) $user->description ) ) {
				$this->add( 'AUTHOR_NO_BIO', 'author', (int) $user_id, array( 'posts' => (int) $count ) );
			}

			$latin_login = (bool) preg_match( '/^[a-z0-9_.\-@]+$/i', $user->display_name );
			if ( 'admin' === strtolower( $user->user_login ) || ( $latin_login && $user->display_name === $user->user_login ) ) {
				$this->add( 'ADMIN_AS_AUTHOR', 'author', (int) $user_id, array( 'posts' => (int) $count ) );
			}
		}
	}

	/**
	 * Crawler access and site visibility.
	 *
	 * @param bool $check_crawlers Whether to fetch robots.txt now.
	 */
	private function site_rules( bool $check_crawlers ): void {
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$this->add( 'SITE_NOINDEX', 'site', 0 );
		}

		$result = $check_crawlers ? CrawlerCheck::run() : CrawlerCheck::last();
		if ( null === $result ) {
			return;
		}

		$blocked = array();
		foreach ( self::SEARCH_BOTS as $bot ) {
			if ( 'blocked' === ( $result['bots'][ $bot ] ?? '' ) ) {
				$blocked[] = $bot;
			}
		}

		if ( array() !== $blocked ) {
			$this->add( 'CRAWLER_BLOCKED', 'site', 0, array( 'bots' => $blocked ) );
		}
	}

	// ---------------------------------------------------------------------
	// Persistence.
	// ---------------------------------------------------------------------

	/**
	 * Records a finding.
	 *
	 * @param string     $code Code.
	 * @param string     $kind content|term|author|site.
	 * @param int        $id   Object ID.
	 * @param array|null $data Details.
	 */
	private function add( string $code, string $kind, int $id, ?array $data = null ): void {
		$this->found[ $code . '|' . $kind . '|' . $id ] = null === $data ? '' : (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE );

		if ( 'content' === $kind ) {
			$this->codes_by_content[ $id ] = ( $this->codes_by_content[ $id ] ?? '' ) . $code . ' ';
		}
	}

	/**
	 * Inserts new findings, refreshes existing ones and resolves those that disappeared.
	 * Ignored findings stay ignored while the condition persists; resolved ones reopen.
	 */
	private function sync(): void {
		global $wpdb;

		$table = Schema::table( 'audit_findings' );
		$now   = current_time( 'mysql', true );

		$insert  = array();
		$touch   = array();
		$reopen  = array();
		$resolve = array();
		$seen    = array();
		$last_id = 0;

		// Existing findings are read in chunks and compared one by one (memory stays flat).
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$chunk = $wpdb->get_results( $wpdb->prepare( "SELECT id, code, object_kind, object_id, state, data FROM {$table} WHERE id > %d ORDER BY id LIMIT 2000", $last_id ), ARRAY_A );

			foreach ( (array) $chunk as $row ) {
				$id      = (int) $row['id'];
				$last_id = $id;
				$key     = $row['code'] . '|' . $row['object_kind'] . '|' . $row['object_id'];

				if ( ! isset( $this->found[ $key ] ) ) {
					if ( 'resolved' !== $row['state'] ) {
						$resolve[] = $id;
					}
					continue;
				}

				$seen[ $key ] = true;
				if ( 'resolved' === $row['state'] ) {
					$reopen[] = $id;
				} else {
					$touch[] = $id;
				}

				$data = $this->found[ $key ];
				if ( (string) $row['data'] !== $data ) {
					$wpdb->update( $table, array( 'data' => '' === $data ? null : $data ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		} while ( is_array( $chunk ) && 2000 === count( $chunk ) );
		unset( $chunk );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $this->found as $key => $data ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			list( $code, $kind, $object_id ) = explode( '|', $key );
			$rule     = Catalog::get( $code );
			$insert[] = $wpdb->prepare( '(%s, %s, %s, %d, %s, %s, %s, %s)', $code, $rule['severity'] ?? 'low', $kind, (int) $object_id, $data, 'open', $now, $now );

			if ( count( $insert ) >= 100 ) {
				$wpdb->query( "INSERT INTO {$table} (code, severity, object_kind, object_id, data, state, first_seen, last_seen) VALUES " . implode( ',', $insert ) );
				$insert = array();
			}
		}
		if ( $insert ) {
			$wpdb->query( "INSERT INTO {$table} (code, severity, object_kind, object_id, data, state, first_seen, last_seen) VALUES " . implode( ',', $insert ) );
		}
		unset( $seen );

		foreach ( array_chunk( $touch, 500 ) as $ids ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_seen = %s WHERE id IN (" . implode( ',', $ids ) . ')', $now ) );
		}
		foreach ( array_chunk( $reopen, 500 ) as $ids ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = 'open', last_seen = %s WHERE id IN (" . implode( ',', $ids ) . ')', $now ) );
		}
		foreach ( array_chunk( $resolve, 500 ) as $ids ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = 'resolved', last_seen = %s WHERE id IN (" . implode( ',', $ids ) . ')', $now ) );
		}
		// phpcs:enable

		// Empty data strings are stored as NULL.
		$wpdb->query( "UPDATE {$table} SET data = NULL WHERE data = ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Stores refresh scores for published posts.
	 *
	 * @param array $rows Inventory rows.
	 */
	private function update_refresh_scores( array $rows ): void {
		global $wpdb;

		$table = Schema::table( 'inventory' );
		$stale = (int) ( $this->settings['stale_months'] ?? 18 );
		$cases = array();

		foreach ( $rows as $row ) {
			$id    = (int) $row['object_id'];
			$score = ( 'publish' === $row['status'] && 'post' === $row['object_type'] )
				? self::refresh_score( $row, explode( ' ', trim( $this->codes_by_content[ $id ] ?? '' ) ), $stale )
				: 0;

			if ( (int) $row['refresh_score'] !== $score ) {
				$cases[ $id ] = $score;
			}
		}

		foreach ( array_chunk( $cases, 200, true ) as $chunk ) {
			$sql = '';
			foreach ( $chunk as $id => $score ) {
				$sql .= sprintf( ' WHEN %d THEN %d', $id, $score );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "UPDATE {$table} SET refresh_score = CASE object_id{$sql} ELSE refresh_score END WHERE object_id IN (" . implode( ',', array_keys( $chunk ) ) . ')' );
		}
	}

	/**
	 * Builds and stores the stats option (spec section 13.2). Public so finding state changes
	 * (ignore / reopen) can refresh the dashboard without rerunning the rules.
	 *
	 * @param int|null $published Number of published content items (computed when null).
	 */
	public static function build_stats( ?int $published = null ): array {
		global $wpdb;

		if ( null === $published ) {
			$inventory_table = Schema::table( 'inventory' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$published = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inventory_table} WHERE status = 'publish'" );
		}

		$table = Schema::table( 'audit_findings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT code, severity, COUNT(*) AS n FROM {$table} WHERE state IN ('open', 'reviewed') GROUP BY code, severity", ARRAY_A );

		$by_severity = array(
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
		);
		$by_group    = array_fill_keys( array_keys( Catalog::GROUPS ), 0 );
		$by_code     = array();
		$weight      = 0.0;

		foreach ( (array) $rows as $row ) {
			$n    = (int) $row['n'];
			$rule = Catalog::get( $row['code'] );
			if ( null === $rule ) {
				continue;
			}
			$by_severity[ $row['severity'] ] = ( $by_severity[ $row['severity'] ] ?? 0 ) + $n;
			$by_group[ $rule['group'] ]     += $n;
			$by_code[ $row['code'] ]         = $n;
			$weight                         += $n * ( Catalog::SEVERITY_WEIGHT[ $row['severity'] ] ?? 0 );
		}

		$inventory = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$refresh = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inventory} WHERE refresh_score >= 20 AND status = 'publish'" );

		$stats = array(
			'audited_at'   => gmdate( 'c' ),
			'published'    => $published,
			'health_score' => self::health_score( $weight, $published ),
			'by_severity'  => $by_severity,
			'by_group'     => $by_group,
			'by_code'      => $by_code,
			'orphans'      => (int) ( ( $by_code['ORPHAN_POST'] ?? 0 ) + ( $by_code['ORPHAN_PAGE'] ?? 0 ) + ( $by_code['ORPHAN_PRODUCT'] ?? 0 ) ),
			'refresh'      => $refresh,
		);

		update_option( self::OPTION_STATS, $stats, false );

		return $stats;
	}

	/**
	 * Inventory rows needed by the rules (without large text columns).
	 */
	private function content_rows(): array {
		global $wpdb;

		$table = Schema::table( 'inventory' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT object_id, object_type, status, focus_keyword IS NULL AS no_keyword, focus_keyword_norm, meta_description IS NULL AS no_meta,
				is_noindex, is_cornerstone, word_count, h2_count, years_mentioned, has_direct_answer,
				has_featured_image, featured_alt_ok, inline_images, inline_images_no_alt, images_generic_alt,
				internal_out, internal_in, menu_in, refresh_score, modified_at
			FROM {$table}",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Terms of the given taxonomies.
	 *
	 * @param string[] $taxonomies Taxonomies.
	 * @return WP_Term[]
	 */
	private function terms( array $taxonomies ): array {
		if ( array() === $taxonomies ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		return is_array( $terms ) ? $terms : array();
	}
}
