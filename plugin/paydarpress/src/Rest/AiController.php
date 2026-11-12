<?php
/**
 * REST endpoints for the AI connection and usage.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Ai\AiClient;
use PaydarWP\PaydarPress\Ai\Credentials;
use PaydarWP\PaydarPress\Ai\PromptBuilder;
use PaydarWP\PaydarPress\Ai\Usage;
use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Core\Settings;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints (manage capability only; the key never leaves the server):
 *  GET  /ai             connection (masked), limits, usage this month, recent requests
 *  POST /ai             { provider, base_url, model, key? }
 *  POST /ai/test        sends a tiny request
 *  POST /ai/models      lists the service's models
 *  POST /ai/clear-key
 */
final class AiController {

	/**
	 * Route definitions.
	 */
	public function routes(): void {
		$ns     = UserPrefsController::NAMESPACE;
		$manage = static fn() => current_user_can( Capabilities::MANAGE );

		register_rest_route(
			$ns,
			'/ai',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route( $ns, '/ai/test', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'test' ), 'permission_callback' => $manage ) );
		register_rest_route( $ns, '/ai/models', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'models' ), 'permission_callback' => $manage ) );
		register_rest_route(
			$ns,
			'/prompt',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'prompt' ),
				'permission_callback' => static fn() => current_user_can( Capabilities::EXPORT ),
				'args'                => array(
					'task' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => PromptBuilder::TASKS,
					),
					'args' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route( $ns, '/ai/clear-key', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'clear_key' ), 'permission_callback' => $manage ) );
	}

	/**
	 * Current state.
	 */
	public function get(): WP_REST_Response {
		return new WP_REST_Response( self::payload() );
	}

	/**
	 * State shared by several pages.
	 */
	public static function payload(): array {
		return array(
			'connection' => Credentials::public_view(),
			'limits'     => array(
				'articles' => (int) Settings::get( 'ai.monthly_articles', 30 ),
				'requests' => (int) Settings::get( 'ai.monthly_requests', 300 ),
			),
			'month'      => Usage::month(),
			'recent'     => Usage::recent( 15 ),
		);
	}

	/**
	 * Saves the connection.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		try {
			Credentials::save( array_intersect_key( $params, array_flip( array( 'provider', 'base_url', 'model', 'key' ) ) ) );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'paydarpress_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		Activity::log( 'settings.ai_saved', 'اتصال هوش مصنوعی ذخیره شد' . ( '' !== trim( (string) ( $params['key'] ?? '' ) ) ? ' (کلید جدید).' : '.' ) );
		return new WP_REST_Response( self::payload() );
	}

	/**
	 * Sends a tiny request to check key, address and model.
	 */
	public function test() {
		$client = new AiClient();
		if ( ! $client->ready() ) {
			return new WP_Error( 'paydarpress_ai_missing', 'ابتدا آدرس، کلید و مدل را ذخیره کنید.', array( 'status' => 400 ) );
		}
		try {
			$r = $client->complete( 'test', 'You are a connectivity check. Reply in Persian.', 'فقط بنویس: «اتصال برقرار است»', array( 'max_tokens' => 40 ) );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'paydarpress_ai_failed', $e->getMessage(), array( 'status' => 502 ) );
		}
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'reply'  => mb_substr( trim( wp_strip_all_tags( $r['text'] ) ), 0, 120 ),
				'model'  => $r['model'],
				'tokens' => $r['input_tokens'] + $r['output_tokens'],
				'state'  => self::payload(),
			)
		);
	}

	/**
	 * Lists models.
	 */
	public function models() {
		try {
			return new WP_REST_Response( array( 'models' => ( new AiClient( self::listing_connection() ) )->models() ) );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'paydarpress_ai_failed', $e->getMessage(), array( 'status' => 502 ) );
		}
	}

	/**
	 * Builds a ready prompt (manual mode) and the short ContentMaster command for a task.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function prompt( WP_REST_Request $request ) {
		try {
			$p = PromptBuilder::build( (string) $request->get_param( 'task' ), (array) $request->get_param( 'args' ) );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'paydarpress_prompt', $e->getMessage(), array( 'status' => 400 ) );
		}
		return new WP_REST_Response(
			array(
				'title'    => $p['title'],
				'prompt'   => $p['full'],
				'command'  => $p['command'],
				'chars'    => $p['chars'],
				'ai_ready' => current_user_can( Capabilities::MANAGE ) && null !== Credentials::connection(),
			)
		);
	}

	/**
	 * Removes the stored key.
	 */
	public function clear_key(): WP_REST_Response {
		Credentials::clear_key();
		Activity::log( 'settings.ai_key_removed', 'کلید API هوش مصنوعی حذف شد.' );
		return new WP_REST_Response( self::payload() );
	}

	/**
	 * Connection usable for listing models before a model is chosen.
	 */
	private static function listing_connection(): ?array {
		$conn = Credentials::connection();
		if ( null !== $conn ) {
			return $conn;
		}
		$stored = get_option( Credentials::OPTION, array() );
		$key    = is_array( $stored ) && ! empty( $stored['key'] ) ? Credentials::decrypt( (string) $stored['key'] ) : '';
		if ( '' === $key ) {
			return null;
		}
		$provider = isset( Credentials::PROVIDERS[ $stored['provider'] ?? '' ] ) ? $stored['provider'] : 'openai';
		return array(
			'provider' => $provider,
			'base_url' => ! empty( $stored['base_url'] ) ? (string) $stored['base_url'] : Credentials::DEFAULT_URLS[ $provider ],
			'model'    => '',
			'key'      => $key,
		);
	}
}
