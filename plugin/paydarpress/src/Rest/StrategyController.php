<?php
/**
 * REST: change log with results, redirects, merging articles.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Strategy\ChangeLog;
use PaydarWP\PaydarPress\Strategy\Redirects;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /changes; GET|POST /redirects; POST /redirects/delete; POST /merge.
 */
final class StrategyController {

	/**
	 * Routes.
	 */
	public function routes(): void {
		$ns     = UserPrefsController::NAMESPACE;
		$view   = static fn() => current_user_can( Capabilities::VIEW );
		$manage = static fn() => current_user_can( Capabilities::MANAGE );

		$cl = \PaydarWP\PaydarPress\Strategy\Clusters::class;
		$wrap = static function ( callable $fn ) {
			return static function ( $request ) use ( $fn ) {
				try {
					return new WP_REST_Response( $fn( $request ) );
				} catch ( \RuntimeException $e ) {
					return new \WP_Error( 'paydarpress_cluster', $e->getMessage(), array( 'status' => 400 ) );
				}
			};
		};
		register_rest_route(
			$ns,
			'/clusters',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $view,
					'callback'            => static function () use ( $cl ) {
						$cats = array();
						foreach ( (array) get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 300 ) ) as $t ) {
							if ( $t instanceof \WP_Term ) {
								$cats[] = array( 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) );
							}
						}
						$pages = array();
						// Cluster key pages are posts/pages (the cluster table stores a post id).
						foreach ( array_filter( \PaydarWP\PaydarPress\Profile\ProfileRepository::key_page_ids(), static fn( $pid ) => $pid > 0 ) as $pid ) {
							$pages[] = array( 'id' => $pid, 'title' => html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ) );
						}
						return new WP_REST_Response(
							array(
								'items'       => $cl::all(),
								'suggestions' => $cl::suggestions(),
								'categories'  => $cats,
								'key_pages'   => $pages,
							)
						);
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $manage,
					'callback'            => $wrap( static fn( $r ) => $cl::get( $cl::save( (array) $r->get_json_params() ) ) ),
				),
			)
		);
		register_rest_route( $ns, '/clusters/accept', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn( $r ) => $cl::get( $cl::accept( (array) $r->get_json_params() ) ) ) ) );
		register_rest_route( $ns, '/clusters/(?P<id>\\d+)/delete', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static function ( $r ) use ( $cl ) { $cl::delete( (int) $r['id'] ); return array( 'ok' => true ); } ) ) );
		register_rest_route( $ns, '/clusters/(?P<id>\\d+)/posts', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static function ( $r ) use ( $cl ) { $cl::add_post( (int) $r['id'], (int) $r->get_param( 'post_id' ), (string) $r->get_param( 'subtopic' ) ); return $cl::get( (int) $r['id'] ); } ) ) );
		register_rest_route( $ns, '/clusters/(?P<id>\\d+)/pillar', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static function ( $r ) use ( $cl ) { $cl::set_pillar( (int) $r['id'], (int) $r->get_param( 'post_id' ) ); return $cl::get( (int) $r['id'] ); } ) ) );
		register_rest_route( $ns, '/clusters/(?P<id>\\d+)/links', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn( $r ) => array( 'queued' => $cl::queue_links( (int) $r['id'] ) ) ) ) );
		register_rest_route( $ns, '/clusters/remove-post/(?P<post>\\d+)', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static function ( $r ) use ( $cl ) { $cl::remove_post( (int) $r['post'] ); return array( 'ok' => true ); } ) ) );
		$td = \PaydarWP\PaydarPress\Strategy\TermDecisions::class;
		register_rest_route(
			$ns,
			'/decisions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $view,
				'callback'            => static function ( $r ) use ( $td ) {
					$tax  = sanitize_key( (string) $r->get_param( 'taxonomy' ) );
					$page = $td::page( $tax, max( 1, (int) $r->get_param( 'page' ) ) );
					$only = (int) $r->get_param( 'term' );
					if ( $only > 0 ) {
						// Opened from Today: that card first.
						$reason = '';
						foreach ( $td::pending() as $p ) {
							if ( $p['id'] === $only ) {
								$reason = $p['reason'];
							}
						}
						// A card already decided (e.g. a stale task link) is not shown again.
						$card          = '' !== $reason ? $td::card( $only, $reason ) : null;
						$page['items'] = array_values( array_merge( $card ? array( $card ) : array(), array_filter( $page['items'], static fn( $i ) => $i['id'] !== $only ) ) );
					}
					return new WP_REST_Response(
						$page + array(
							'taxonomies' => \PaydarWP\PaydarPress\Profile\TaxonomyMap::taxonomies(),
							'grown'      => $td::GROWN,
						)
					);
				},
			)
		);
		register_rest_route( $ns, '/decisions/(?P<id>\\d+)/(?P<op>keep|merge|delete|later|remove_posts|hide)', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn( $r ) => $td::decide( (int) $r['id'], (string) $r['op'], array( 'into' => (int) $r->get_param( 'into' ), 'posts' => (array) $r->get_param( 'posts' ) ) ) ) ) );
		register_rest_route(
			$ns,
			'/decisions/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => $wrap(
					static function ( $r ) use ( $td ) {
						$op = (string) $r->get_param( 'op' );
						if ( ! in_array( $op, array( 'delete', 'later', 'keep' ), true ) ) {
							throw new \RuntimeException( 'اقدام گروهی نامعتبر.' );
						}
						$n = 0;
						foreach ( array_slice( array_map( 'intval', (array) $r->get_param( 'ids' ) ), 0, 500 ) as $id ) {
							try {
								$td::decide( $id, $op );
								++$n;
							} catch ( \RuntimeException $e ) {
								continue;
							}
						}
						return array( 'done' => $n );
					}
				),
			)
		);
		register_rest_route(
			$ns,
			'/classify',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $view,
				'callback'            => static function ( $request ) {
					$kinds = \PaydarWP\PaydarPress\Strategy\Classifier::kinds();
					$kind  = (string) $request->get_param( 'kind' );
					$kind  = isset( $kinds[ $kind ] ) ? $kind : (string) array_key_first( $kinds );
					$scope = in_array( $request->get_param( 'scope' ), array( 'all', 'cleanup' ), true ) ? (string) $request->get_param( 'scope' ) : 'weak';
					return new WP_REST_Response(
						\PaydarWP\PaydarPress\Strategy\Classifier::plan( $kind, $scope ) + array(
							'kinds'  => array_map( static fn( $k ) => $k['label'], $kinds ),
							'ai'     => null !== \PaydarWP\PaydarPress\Ai\Credentials::connection(),
							'active' => array_values( array_filter( \PaydarWP\PaydarPress\Ai\AiJobs::recent_batches( 3 ), static fn( $b ) => in_array( $b['status'], array( 'running', 'ready' ), true ) ) ),
						)
					);
				},
			)
		);
		register_rest_route(
			$ns,
			'/classify/undo/(?P<id>[a-zA-Z0-9]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => static function ( $request ) {
					try {
						$n = \PaydarWP\PaydarPress\Strategy\Classifier::undo( (string) $request['id'] );
					} catch ( \RuntimeException $e ) {
						return new \WP_Error( 'paydarpress_classify', $e->getMessage(), array( 'status' => 400 ) );
					}
					return new WP_REST_Response( array( 'restored' => $n ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/broken',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $view,
				'callback'            => static function ( $r ) {
					$only  = (int) $r->get_param( 'post' );
					$posts = $only > 0 ? array( $only => 0 ) : \PaydarWP\PaydarPress\Links\LinkFixer::posts( 200 );
					$items = array();
					$by    = array();
					foreach ( array_keys( $posts ) as $pid ) {
						$problems = \PaydarWP\PaydarPress\Links\LinkFixer::problems( (int) $pid );
						if ( ! $problems ) {
							continue;
						}
						$items[] = array(
							'id'       => (int) $pid,
							'title'    => html_entity_decode( get_the_title( (int) $pid ), ENT_QUOTES, 'UTF-8' ),
							'url'      => (string) get_permalink( (int) $pid ),
							'edit_url' => (string) get_edit_post_link( (int) $pid, 'raw' ),
							'problems' => $problems,
						);
						foreach ( $problems as $p ) {
							if ( in_array( $p['kind'], array( 'missing', 'unpublished' ), true ) ) {
								$by[ $p['url'] ] = ( $by[ $p['url'] ] ?? array( 'url' => $p['url'], 'label' => $p['label'], 'pages' => 0, 'fix' => $p['fix'] ) );
								++$by[ $p['url'] ]['pages'];
							}
						}
					}
					return new WP_REST_Response(
						array(
							'items'    => $items,
							// Deleted addresses linked from 2+ pages: one redirect repairs them all.
							'groups'   => array_values( array_filter( $by, static fn( $g ) => $g['pages'] >= 2 ) ),
							'external' => \PaydarWP\PaydarPress\Links\ExternalLinks::status(),
						)
					);
				},
			)
		);
		register_rest_route(
			$ns,
			'/broken/fix',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => $wrap( static fn( $r ) => \PaydarWP\PaydarPress\Links\LinkFixer::fix( (int) $r->get_param( 'post' ), (string) $r->get_param( 'url' ), (string) $r->get_param( 'action' ), (int) $r->get_param( 'target' ) ) ),
			)
		);
		register_rest_route( $ns, '/broken/external-run', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn() => array( 'run' => \PaydarWP\PaydarPress\Links\ExternalLinks::maybe_run( true ), 'status' => \PaydarWP\PaydarPress\Links\ExternalLinks::status() ) ) ) );
		// Improvement program.
		register_rest_route(
			$ns,
			'/progress',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $view,
				'callback'            => static function () {
					$now = \PaydarWP\PaydarPress\Improve\Progress::now();
					return new WP_REST_Response(
						array(
							'now'      => $now,
							'history'  => \PaydarWP\PaydarPress\Improve\Progress::history(),
							'tags'     => \PaydarWP\PaydarPress\Improve\TagProgram::state() + array(
								'suggested' => \PaydarWP\PaydarPress\Improve\TagProgram::suggested(),
								'groups'    => array_slice( \PaydarWP\PaydarPress\Improve\TagProgram::groups(), 0, 10 ),
							),
							'slugs'    => \PaydarWP\PaydarPress\Improve\SlugProgram::state(),
							'settings' => array(
								'weekly_minutes' => (int) \PaydarWP\PaydarPress\Core\Settings::get( 'improve.weekly_minutes', 120 ),
								'slugs_per_week' => (int) \PaydarWP\PaydarPress\Core\Settings::get( 'improve.slugs_per_week', 10 ),
								'brake_drop'     => (int) \PaydarWP\PaydarPress\Core\Settings::get( 'improve.brake_drop', 20 ),
							),
						)
					);
				},
			)
		);
		register_rest_route( $ns, '/progress/slugs/apply', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn( $r ) => \PaydarWP\PaydarPress\Improve\SlugProgram::apply( (array) $r->get_param( 'items' ), (bool) $r->get_param( 'busy_ok' ) ) ) ) );
		register_rest_route( $ns, '/progress/slugs/plan', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn( $r ) => array( 'stored' => \PaydarWP\PaydarPress\Improve\SlugProgram::store( array( (int) $r->get_param( 'id' ) => (string) $r->get_param( 'slug' ) ) ) ) ) ) );
		register_rest_route(
			$ns,
			'/progress/slugs/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => $wrap(
					static function () {
						\PaydarWP\PaydarPress\Improve\SlugProgram::resume();
						return array( 'ok' => true );
					}
				),
			)
		);
		register_rest_route(
			$ns,
			'/progress/tags/create',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => $wrap(
					static function ( $r ) {
						$tax  = 'product_tag' === $r->get_param( 'taxonomy' ) && taxonomy_exists( 'product_tag' ) ? 'product_tag' : 'post_tag';
						$name = sanitize_text_field( (string) $r->get_param( 'name' ) );
						if ( '' === $name || mb_strlen( $name ) > 60 ) {
							throw new \RuntimeException( 'نام برچسب معتبر نیست.' );
						}
						$t = term_exists( $name, $tax );
						$t = $t ? $t : wp_insert_term( $name, $tax );
						if ( is_wp_error( $t ) ) {
							throw new \RuntimeException( $t->get_error_message() );
						}
						\PaydarWP\PaydarPress\Core\Activity::log( 'improve.tag_created', 'برچسب «' . $name . '» از جست‌وجوهای گوگل ساخته شد.', 'term', (int) $t['term_id'] );
						return array( 'ok' => true, 'message' => 'برچسب «' . $name . '» ساخته شد. با «طبقه‌بندی محتوای موجود» به مطالب مناسب داده می‌شود.' );
					}
				),
			)
		);
		register_rest_route(
			$ns,
			'/progress/tags/sweep',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => $wrap( static fn() => array( 'groups' => count( \PaydarWP\PaydarPress\Improve\TagProgram::sweep() ) ) ),
			)
		);
		$filters = static fn( $r ) => array(
			'kind'    => (string) $r->get_param( 'kind' ),
			'verdict' => (string) $r->get_param( 'verdict' ),
			'days'    => (int) $r->get_param( 'days' ),
		);
		register_rest_route( $ns, '/changes', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => $view, 'callback' => static fn( $r ) => new WP_REST_Response( ChangeLog::report( $filters( $r ) ) ) ) );
		register_rest_route( $ns, '/changes/export', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => $view, 'callback' => static fn( $r ) => new WP_REST_Response( array( 'csv' => ChangeLog::csv( $filters( $r ) ), 'name' => 'paydarpress-changes-' . gmdate( 'Y-m-d' ) . '.csv' ) ) ) );
		register_rest_route(
			$ns,
			'/redirects',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $view,
					'callback'            => static fn() => new WP_REST_Response( array( 'items' => Redirects::all(), 'owner' => \PaydarWP\PaydarPress\Strategy\RedirectOwner::detect() ) ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $manage,
					'args'                => array(
						'source' => array( 'type' => 'string', 'required' => true ),
						'target' => array( 'type' => 'string', 'required' => true ),
					),
					'callback'            => static function ( WP_REST_Request $r ) {
						try {
							$target = (string) $r['target'];
							$id     = url_to_postid( $target );
							Redirects::add( (string) $r['source'], $target, $id ?: null, 'manual' );
						} catch ( RuntimeException $e ) {
							return new WP_Error( 'paydarpress_redirect', $e->getMessage(), array( 'status' => 400 ) );
						}
						return new WP_REST_Response( array( 'items' => Redirects::all(), 'owner' => \PaydarWP\PaydarPress\Strategy\RedirectOwner::detect() ) );
					},
				),
			)
		);
		register_rest_route(
			$ns,
			'/redirects/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
				'callback'            => static function ( WP_REST_Request $r ) {
					Redirects::delete( (int) $r['id'] );
					return new WP_REST_Response( array( 'items' => Redirects::all(), 'owner' => \PaydarWP\PaydarPress\Strategy\RedirectOwner::detect() ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/merge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'args'                => array(
					'from' => array( 'type' => 'integer', 'required' => true ),
					'into' => array( 'type' => 'integer', 'required' => true ),
				),
				'callback'            => static function ( WP_REST_Request $r ) {
					try {
						return new WP_REST_Response( Redirects::merge( (int) $r['from'], (int) $r['into'] ) );
					} catch ( RuntimeException $e ) {
						return new WP_Error( 'paydarpress_merge', $e->getMessage(), array( 'status' => 400 ) );
					}
				},
			)
		);
	}
}
