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
						foreach ( \PaydarWP\PaydarPress\Profile\ProfileRepository::key_page_ids() as $pid ) {
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
					$scope = 'all' === $request->get_param( 'scope' ) ? 'all' : 'weak';
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
		register_rest_route( $ns, '/changes', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => $view, 'callback' => static fn() => new WP_REST_Response( array( 'items' => ChangeLog::recent(), 'min_days' => ChangeLog::MIN_DAYS ) ) ) );
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
