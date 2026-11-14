<?php
/**
 * REST: this week's tasks.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Tasks\TaskBoard;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /tasks, POST /tasks/mark { key, action: done|later|reopen }.
 */
final class TasksController {

	/**
	 * Routes.
	 */
	public function routes(): void {
		$ns = UserPrefsController::NAMESPACE;
		register_rest_route( $ns, '/tasks', array( 'methods' => WP_REST_Server::READABLE, 'callback' => static fn() => new WP_REST_Response( self::detailed( TaskBoard::board() ) ), 'permission_callback' => static fn() => current_user_can( Capabilities::VIEW ) ) );
		register_rest_route(
			$ns,
			'/tasks/mark',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => current_user_can( Capabilities::MANAGE ),
				'args'                => array(
					'key'    => array( 'type' => 'string', 'required' => true ),
					'action' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'done', 'later', 'reopen' ) ),
				),
				'callback'            => static function ( WP_REST_Request $r ) {
					TaskBoard::mark( (string) $r['key'], (string) $r['action'] );
					return new WP_REST_Response( self::detailed( TaskBoard::board() ) );
				},
			)
		);
	}

	/**
	 * Board with card details (view link, Search Console numbers, facts).
	 *
	 * @param array $board Board.
	 */
	private static function detailed( array $board ): array {
		$board['week']  = \PaydarWP\PaydarPress\Tasks\TaskDetails::add( $board['week'] );
		return $board;
	}
}
