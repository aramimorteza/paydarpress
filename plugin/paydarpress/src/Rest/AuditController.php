<?php
/**
 * REST: dashboard, findings, taxonomies, orphans, refresh candidates and crawlers.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Audit\Catalog;
use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Crawl\CrawlerCheck;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\LinkSuggester;
use PaydarWP\PaydarPress\Scanner\ScanManager;
use PaydarWP\PaydarPress\Seo\SeoDetector;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * See spec section 11.
 */
final class AuditController {

	private const ACTION_LABELS = array(
		'merge'   => 'ادغام یا تفکیک',
		'rewrite' => 'بازنویسی',
		'light'   => 'بروزرسانی سبک',
		'none'    => '—',
	);

	/** @var ScanManager */
	private $scans;

	/**
	 * @param ScanManager $scans Scan manager.
	 */
	public function __construct( ScanManager $scans ) {
		$this->scans = $scans;
	}

	/**
	 * Registers routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function routes(): void {
		$ns   = UserPrefsController::NAMESPACE;
		$view = static fn() => current_user_can( Capabilities::VIEW );

		register_rest_route( $ns, '/dashboard', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'dashboard' ), 'permission_callback' => $view ) );

		register_rest_route(
			$ns,
			'/findings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'findings' ),
				'permission_callback' => $view,
				'args'                => array(
					'group'    => array( 'type' => 'string', 'default' => '' ),
					'code'     => array( 'type' => 'string', 'default' => '' ),
					'severity' => array( 'type' => 'string', 'default' => '', 'enum' => array( '', 'high', 'medium', 'low' ) ),
					'state'    => array( 'type' => 'string', 'default' => 'open', 'enum' => array( 'open', 'reviewed', 'ignored', 'resolved', 'active' ) ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/findings/(?P<id>\d+)/state',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_state' ),
				'permission_callback' => static fn() => current_user_can( Capabilities::MANAGE ),
				'args'                => array(
					'state' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'open', 'reviewed', 'ignored' ) ),
				),
			)
		);

		register_rest_route( $ns, '/taxonomies', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'taxonomies' ), 'permission_callback' => $view ) );

		register_rest_route(
			$ns,
			'/orphans',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'orphans' ),
				'permission_callback' => $view,
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/refresh-candidates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'refresh' ),
				'permission_callback' => $view,
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);

		register_rest_route( $ns, '/crawlers', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'crawlers' ), 'permission_callback' => $view ) );
		register_rest_route( $ns, '/crawlers/check', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'check_crawlers' ), 'permission_callback' => static fn() => current_user_can( Capabilities::MANAGE ) ) );
	}

	/**
	 * Dashboard data: stats, last scan, quick wins, top refresh candidates.
	 */
	public function dashboard(): WP_REST_Response {
		$stats = get_option( AuditEngine::OPTION_STATS, null );
		$scan  = $this->scans->latest_scan();

		// Quick wins: codes ordered by total weight (count × severity weight).
		$quick = array();
		if ( is_array( $stats ) ) {
			foreach ( (array) $stats['by_code'] as $code => $count ) {
				$rule = Catalog::get( (string) $code );
				if ( null === $rule ) {
					continue;
				}
				$quick[] = array(
					'code'     => $code,
					'count'    => (int) $count,
					'severity' => $rule['severity'],
					'group'    => $rule['group'],
					'title'    => $rule['title'],
					'fix'      => $rule['fix'],
					'weight'   => $count * Catalog::SEVERITY_WEIGHT[ $rule['severity'] ],
				);
			}
			usort( $quick, static fn( $a, $b ) => $b['weight'] <=> $a['weight'] );
		}

		return new WP_REST_Response(
			array(
				'stats'      => $stats,
				'groups'     => Catalog::GROUPS,
				'scan'       => null === $scan ? null : array(
					'status'      => $scan['status'],
					'finished_at' => ScanController::iso( $scan['finished_at'] ),
					'total'       => (int) $scan['total'],
				),
				'quick_wins' => array_slice( $quick, 0, 5 ),
				'refresh'    => $this->refresh_items( 1, 5 )['items'],
				'crawlers'   => CrawlerCheck::last(),
			)
		);
	}

	/**
	 * Paginated findings with explanations.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function findings( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$table = Schema::table( 'audit_findings' );
		$where = array();
		$args  = array();

		$state = (string) $request->get_param( 'state' );
		if ( 'active' === $state ) {
			$where[] = "state IN ('open', 'reviewed')";
		} else {
			$where[] = 'state = %s';
			$args[]  = $state;
		}

		$codes = array();
		$group = (string) $request->get_param( 'group' );
		if ( '' !== $group ) {
			foreach ( Catalog::rules() as $code => $rule ) {
				if ( $rule[0] === $group ) {
					$codes[] = $code;
				}
			}
		}
		$code = (string) $request->get_param( 'code' );
		if ( '' !== $code ) {
			$codes = array( $code );
		}
		if ( array() !== $codes ) {
			$where[] = 'code IN (' . implode( ',', array_fill( 0, count( $codes ), '%s' ) ) . ')';
			$args    = array_merge( $args, $codes );
		}

		$severity = (string) $request->get_param( 'severity' );
		if ( '' !== $severity ) {
			$where[] = 'severity = %s';
			$args[]  = $severity;
		}

		$per_page  = (int) $request->get_param( 'per_page' );
		$page      = (int) $request->get_param( 'page' );
		$where_sql = implode( ' AND ', $where );
		$order     = "CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END, code, id";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );

		$state_counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT state, COUNT(*) AS n FROM {$table} GROUP BY state", ARRAY_A ) as $row ) {
			$state_counts[ $row['state'] ] = (int) $row['n'];
		}
		// phpcs:enable

		return new WP_REST_Response(
			array(
				'items'       => array_map( array( $this, 'format_finding' ), (array) $rows ),
				'total'       => $total,
				'page'        => $page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
				'states'      => $state_counts,
				'groups'      => Catalog::GROUPS,
			)
		);
	}

	/**
	 * Changes a finding's state (reviewed / ignored / open).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_state( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request['id'];
		$state = (string) $request->get_param( 'state' );
		$table = Schema::table( 'audit_findings' );

		$updated = $wpdb->update( $table, array( 'state' => $state ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $updated ) {
			return new WP_Error( 'paydarpress_db', 'تغییر وضعیت ذخیره نشد.', array( 'status' => 500 ) );
		}

		$labels = array(
			'open'     => 'باز',
			'reviewed' => 'بررسی‌شده',
			'ignored'  => 'نادیده گرفته‌شده',
		);
		Activity::log( 'finding.state', sprintf( 'وضعیت یک یافته به «%s» تغییر کرد.', $labels[ $state ] ), 'finding', $id );

		return new WP_REST_Response( array( 'stats' => AuditEngine::build_stats() ) );
	}

	/**
	 * Categories and tags with counts and findings.
	 */
	public function taxonomies(): WP_REST_Response {
		global $wpdb;

		$table = Schema::table( 'audit_findings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT object_id, code FROM {$table} WHERE object_kind = 'term' AND state IN ('open', 'reviewed')", ARRAY_A );

		$codes = array();
		foreach ( (array) $rows as $row ) {
			$codes[ (int) $row['object_id'] ][] = $row['code'];
		}

		$adapter = SeoDetector::adapter();
		$build   = function ( array $taxonomies ) use ( $codes, $adapter ): array {
			$taxonomies = array_values( array_filter( $taxonomies, 'taxonomy_exists' ) );
			if ( array() === $taxonomies ) {
				return array();
			}
			$terms = get_terms( array( 'taxonomy' => $taxonomies, 'hide_empty' => false, 'number' => 0, 'orderby' => 'count', 'order' => 'DESC' ) );
			$out   = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$term_codes = $codes[ $term->term_id ] ?? array();
				$out[]      = array(
					'id'          => $term->term_id,
					'name'        => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'taxonomy'    => $term->taxonomy,
					'count'       => (int) $term->count,
					'description' => mb_strlen( trim( wp_strip_all_tags( $term->description ) ) ),
					'noindex'     => $adapter->term_noindex( $term ),
					'url'         => (string) get_term_link( $term ),
					'edit_url'    => (string) get_edit_term_link( $term->term_id, $term->taxonomy ),
					'findings'    => array_map( array( $this, 'rule_brief' ), $term_codes ),
				);
			}
			return $out;
		};

		return new WP_REST_Response(
			array(
				'categories' => $build( array( 'category', 'product_cat' ) ),
				'tags'       => $build( array( 'post_tag', 'product_tag' ) ),
			)
		);
	}

	/**
	 * Orphan content with link suggestions.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function orphans( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$table    = Schema::table( 'audit_findings' );
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$codes    = "('ORPHAN_POST', 'ORPHAN_PAGE', 'ORPHAN_PRODUCT')";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE code IN {$codes} AND state IN ('open', 'reviewed')" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE code IN {$codes} AND state IN ('open', 'reviewed') ORDER BY code, object_id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		// phpcs:enable

		$suggester = new LinkSuggester();
		$items     = array();
		foreach ( (array) $rows as $row ) {
			$item                = $this->format_finding( $row );
			$item['suggestions'] = $suggester->for_target( (int) $row['object_id'] );
			$item['anchor']      = $suggester->anchor_for( (int) $row['object_id'] );
			$items[]             = $item;
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => $total,
				'page'        => $page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Refresh candidates.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function refresh( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->refresh_items( (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) ) );
	}

	/**
	 * Crawler check results.
	 */
	public function crawlers(): WP_REST_Response {
		return new WP_REST_Response( $this->crawler_payload() );
	}

	/**
	 * Runs the crawler check now and re-audits site rules.
	 */
	public function check_crawlers(): WP_REST_Response {
		CrawlerCheck::run();
		( new AuditEngine() )->run( false );

		return new WP_REST_Response( $this->crawler_payload() );
	}

	/**
	 * Crawler results with descriptions.
	 */
	private function crawler_payload(): array {
		$last = CrawlerCheck::last();
		$bots = array();

		foreach ( CrawlerCheck::BOTS as $name => $description ) {
			$bots[] = array(
				'name'        => $name,
				'description' => $description,
				'state'       => $last['bots'][ $name ] ?? null,
			);
		}

		return array(
			'checked_at'  => $last['checked_at'] ?? null,
			'source'      => $last['source'] ?? null,
			'site_public' => '0' !== (string) get_option( 'blog_public', '1' ),
			'robots_url'  => home_url( '/robots.txt' ),
			'bots'        => $bots,
		);
	}

	/**
	 * Refresh candidates page.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Items per page.
	 */
	private function refresh_items( int $page, int $per_page ): array {
		global $wpdb;

		$inventory = Schema::table( 'inventory' );
		$findings  = Schema::table( 'audit_findings' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inventory} WHERE status = 'publish' AND refresh_score > 0" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, refresh_score, word_count, modified_at, url FROM {$inventory} WHERE status = 'publish' AND refresh_score > 0 ORDER BY refresh_score DESC, modified_at ASC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		// phpcs:enable

		$ids   = array_map( static fn( $r ) => (int) $r['object_id'], (array) $rows );
		$codes = array();
		if ( array() !== $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( "SELECT object_id, code FROM {$findings} WHERE object_kind = 'content' AND state IN ('open', 'reviewed') AND object_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ) as $f ) {
				$codes[ (int) $f['object_id'] ][] = $f['code'];
			}
		}

		$stale = (int) Settings::get( 'audit.stale_months', 18 );
		$items = array();
		foreach ( (array) $rows as $row ) {
			$id         = (int) $row['object_id'];
			$item_codes = $codes[ $id ] ?? array();
			$score      = (int) $row['refresh_score'];
			$action     = AuditEngine::refresh_action( $score, $item_codes );
			$reasons    = array();

			foreach ( array( 'OUTDATED_YEAR', 'THIN_CONTENT', 'DUPLICATE_FOCUS_KEYWORD', 'NO_DIRECT_ANSWER', 'NO_INTERNAL_LINKS', 'BROKEN_INTERNAL_LINK', 'NO_H2', 'IMG_NO_FEATURED', 'IMG_MISSING_ALT' ) as $code ) {
				if ( in_array( $code, $item_codes, true ) ) {
					$reasons[] = Catalog::get( $code )['title'];
				}
			}
			$modified = ! empty( $row['modified_at'] ) ? strtotime( $row['modified_at'] . ' UTC' ) : false;
			if ( false !== $modified && $modified < strtotime( '-' . $stale . ' months' ) ) {
				/* translators: %d: months. */
				$reasons[] = sprintf( 'بیش از %s ماه بدون ویرایش', PersianNormalizer::fa_number( $stale ) );
			}

			$items[] = array(
				'id'           => $id,
				'title'        => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'          => $row['url'],
				'edit_url'     => (string) get_edit_post_link( $id, 'raw' ),
				'score'        => $score,
				'action'       => $action,
				'action_label' => self::ACTION_LABELS[ $action ],
				'reasons'      => $reasons,
				'words'        => (int) $row['word_count'],
				'modified_at'  => ScanController::iso( $row['modified_at'] ),
			);
		}

		return array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
		);
	}

	/**
	 * API representation of a finding, with the object it refers to.
	 *
	 * @param array $row DB row.
	 */
	private function format_finding( array $row ): array {
		$rule = Catalog::get( (string) $row['code'] ) ?? array(
			'group'    => 'site',
			'severity' => $row['severity'],
			'title'    => $row['code'],
			'why'      => '',
			'fix'      => '',
		);
		$data = json_decode( (string) $row['data'], true );
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'         => (int) $row['id'],
			'code'       => $row['code'],
			'severity'   => $row['severity'],
			'state'      => $row['state'],
			'group'      => $rule['group'],
			'title'      => $rule['title'],
			'why'        => $rule['why'],
			'fix'        => $rule['fix'],
			'object'     => $this->object_info( (string) $row['object_kind'], (int) $row['object_id'] ),
			'details'    => $this->details( (string) $row['code'], $data ),
			'first_seen' => ScanController::iso( $row['first_seen'] ),
		);
	}

	/**
	 * Title and links of the object a finding refers to.
	 *
	 * @param string $kind Object kind.
	 * @param int    $id   Object ID.
	 */
	private function object_info( string $kind, int $id ): array {
		switch ( $kind ) {
			case 'content':
				return array(
					'kind'     => 'content',
					'id'       => $id,
					'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
					'type'     => (string) get_post_type( $id ),
					'url'      => (string) get_permalink( $id ),
					'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
				);
			case 'term':
				$term = get_term( $id );
				return array(
					'kind'     => 'term',
					'id'       => $id,
					'title'    => $term instanceof WP_Term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : '',
					'type'     => $term instanceof WP_Term ? $term->taxonomy : '',
					'url'      => $term instanceof WP_Term ? (string) get_term_link( $term ) : '',
					'edit_url' => $term instanceof WP_Term ? (string) get_edit_term_link( $id, $term->taxonomy ) : '',
				);
			case 'author':
				$user = get_userdata( $id );
				return array(
					'kind'     => 'author',
					'id'       => $id,
					'title'    => $user ? $user->display_name : '',
					'type'     => 'user',
					'url'      => $user ? (string) get_author_posts_url( $id ) : '',
					'edit_url' => (string) get_edit_user_link( $id ),
				);
			default:
				return array(
					'kind'     => 'site',
					'id'       => 0,
					'title'    => 'کل سایت',
					'type'     => 'site',
					'url'      => home_url( '/' ),
					'edit_url' => '',
				);
		}
	}

	/**
	 * Human-readable details for a finding's data.
	 *
	 * @param string $code Code.
	 * @param array  $data Data.
	 * @return string[]
	 */
	private function details( string $code, array $data ): array {
		$lines = array();
		$fa    = static fn( $n ) => PersianNormalizer::fa_number( $n );

		switch ( $code ) {
			case 'BROKEN_INTERNAL_LINK':
			case 'REDIRECTED_INTERNAL_LINK':
				foreach ( (array) ( $data['links'] ?? array() ) as $link ) {
					$lines[] = sprintf( '«%s» ← %s', $link['anchor'] ?: 'بدون متن', rawurldecode( (string) $link['url'] ) );
				}
				break;
			case 'DUPLICATE_FOCUS_KEYWORD':
				$titles  = array_map( static fn( $id ) => html_entity_decode( get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' ), (array) ( $data['others'] ?? array() ) );
				$lines[] = sprintf( 'کلمه کلیدی «%s» در: %s', $data['keyword'] ?? '', implode( '، ', $titles ) );
				break;
			case 'OUTDATED_YEAR':
				$lines[] = 'سال‌های ذکرشده: ' . implode( '، ', array_map( $fa, (array) ( $data['years'] ?? array() ) ) );
				break;
			case 'THIN_CONTENT':
				$lines[] = sprintf( '%s کلمه (حداقل پیشنهادی: %s)', $fa( $data['words'] ?? 0 ), $fa( $data['minimum'] ?? 0 ) );
				break;
			case 'WEAK_CATEGORY':
				$lines[] = sprintf( '%s مطلب (حداقل پیشنهادی: %s)', $fa( $data['count'] ?? 0 ), $fa( $data['minimum'] ?? 0 ) );
				break;
			case 'WEAK_INBOUND':
				$lines[] = sprintf( '%s لینک ورودی (حداقل پیشنهادی: %s)', $fa( $data['internal_in'] ?? 0 ), $fa( $data['minimum'] ?? 0 ) );
				break;
			case 'SINGLE_CATEGORY_OVERLOAD':
				$lines[] = sprintf( '%s از %s نوشته در این دسته است.', $fa( $data['count'] ?? 0 ), $fa( $data['total'] ?? 0 ) );
				break;
			case 'TAG_OVERUSE':
				$lines[] = sprintf( '%s برچسب (حداکثر پیشنهادی: %s)', $fa( $data['count'] ?? 0 ), $fa( $data['maximum'] ?? 0 ) );
				break;
			case 'IMG_MISSING_ALT':
			case 'IMG_GENERIC_ALT':
				$lines[] = sprintf( '%s تصویر', $fa( $data['count'] ?? 0 ) );
				break;
			case 'SIMILAR_CATEGORIES':
			case 'NEAR_DUPLICATE_TAGS':
			case 'TAG_EQUALS_CATEGORY':
				$other = get_term( (int) ( $data['similar_to'] ?? $data['duplicate_of'] ?? $data['category'] ?? 0 ) );
				if ( $other instanceof WP_Term ) {
					$lines[] = sprintf( 'مشابه با: %s', html_entity_decode( $other->name, ENT_QUOTES, 'UTF-8' ) );
				}
				break;
			case 'INDEXED_THIN_ARCHIVE':
				$names = array();
				foreach ( array_slice( (array) ( $data['terms'] ?? array() ), 0, 12 ) as $term_id ) {
					$term = get_term( (int) $term_id );
					if ( $term instanceof WP_Term ) {
						$names[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
					}
				}
				$lines[] = sprintf( '%s آرشیو، از جمله: %s', $fa( $data['count'] ?? 0 ), implode( '، ', $names ) );
				break;
			case 'CRAWLER_BLOCKED':
				$lines[] = 'مسدود: ' . implode( '، ', (array) ( $data['bots'] ?? array() ) );
				break;
			case 'AUTHOR_NO_BIO':
			case 'ADMIN_AS_AUTHOR':
				$lines[] = sprintf( '%s نوشته منتشرشده', $fa( $data['posts'] ?? 0 ) );
				break;
			case 'MENU_ONLY':
				$lines[] = 'فقط از منو لینک گرفته است.';
				break;
		}

		return $lines;
	}

	/**
	 * Short rule info for lists.
	 *
	 * @param string $code Code.
	 */
	private function rule_brief( string $code ): array {
		$rule = Catalog::get( $code );

		return array(
			'code'     => $code,
			'title'    => $rule['title'] ?? $code,
			'severity' => $rule['severity'] ?? 'low',
		);
	}
}
