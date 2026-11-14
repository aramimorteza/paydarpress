<?php
/**
 * REST: per-user UI preferences (help dismissal, simple/pro mode).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoints:
 *  POST /paydarpress/v1/help/dismiss   { key }
 *  POST /paydarpress/v1/help/reset
 *  POST /paydarpress/v1/ui-mode        { mode: simple|pro }
 */
final class UserPrefsController {

	public const REST_NAMESPACE = 'paydarpress/v1';

	private const HELP_META = 'paydarpress_dismissed_help';
	private const MODE_META = 'paydarpress_ui_mode';

	/**
	 * Registers routes on rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/help/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_help' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'key' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/help/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_help' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ui-mode',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_mode' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'mode' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'simple', 'pro' ),
					),
				),
			)
		);
	}

	/**
	 * Permission: any user who can open PaydarPress.
	 */
	public function can_view(): bool {
		return current_user_can( Capabilities::VIEW );
	}

	/**
	 * Marks a help box as dismissed for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function dismiss_help( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$key     = (string) $request->get_param( 'key' );
		$list    = get_user_meta( $user_id, self::HELP_META, true );
		$list    = is_array( $list ) ? $list : array();

		if ( '' !== $key && ! in_array( $key, $list, true ) && count( $list ) < 200 ) {
			$list[] = $key;
			update_user_meta( $user_id, self::HELP_META, $list );
		}

		return new WP_REST_Response( array( 'dismissed' => array_values( $list ) ) );
	}

	/**
	 * Shows all help boxes again for the current user.
	 */
	public function reset_help(): WP_REST_Response {
		delete_user_meta( get_current_user_id(), self::HELP_META );

		return new WP_REST_Response( array( 'dismissed' => array() ) );
	}

	/**
	 * Saves simple/pro mode for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_mode( WP_REST_Request $request ): WP_REST_Response {
		$mode = (string) $request->get_param( 'mode' );
		update_user_meta( get_current_user_id(), self::MODE_META, $mode );

		return new WP_REST_Response( array( 'mode' => $mode ) );
	}
}
