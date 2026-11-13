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
