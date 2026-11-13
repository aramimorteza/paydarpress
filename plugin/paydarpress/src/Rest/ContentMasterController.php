<?php
/**
 * REST: ContentMaster import and suggested topics.
 *
 *  POST /cm/preview          { text } or multipart "file" (.json/.txt)     (manage)
 *  POST /cm/commit           { token, topics[], articles[], link_suggestions[] } (manage)
 *  GET  /topics              ?status&page&per_page                          (view)
 *  POST /topics/{id}         { status }                                     (manage)
 *  POST /topics/{id}/delete                                                 (manage)
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Import\ImportParser;
use PaydarWP\PaydarPress\Import\ImportService;
use PaydarWP\PaydarPress\Import\LinkSuggestionRepository;
use PaydarWP\PaydarPress\Import\TopicRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ContentMaster controller.
 */
final class ContentMasterController {

	public const NAMESPACE = 'paydarpress/v1';

	/**
	 * Routes.
	 */
	public function routes(): void {
		$manage = static fn() => current_user_can( Capabilities::MANAGE );
		$view   = static fn() => current_user_can( Capabilities::VIEW );
		$ids    = array(
			'type'    => 'array',
			'items'   => array( 'type' => 'integer' ),
			'default' => array(),
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\\d+)/reviewed',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reviewed' ),
				'permission_callback' => static fn( $request ) => current_user_can( 'edit_post', (int) $request['id'] ),
			)
		);
		register_rest_route( self::NAMESPACE, '/cm/preview', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'preview' ), 'permission_callback' => $manage ) );
		register_rest_route(
			self::NAMESPACE,
			'/cm/commit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'commit' ),
				'permission_callback' => $manage,
				'args'                => array(
					'token'            => array(
						'type'     => 'string',
						'required' => true,
					),
					'topics'           => $ids,
					'articles'         => $ids,
					'link_suggestions' => $ids,
					'term_descriptions' => $ids,
					'seo_updates'      => $ids,
					'structure_suggestions' => $ids,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/topics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'topics' ),
				'permission_callback' => $view,
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), TopicRepository::STATUSES ),
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/topics/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_topic' ),
				'permission_callback' => $manage,
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'new', 'accepted', 'rejected' ),
					),
				),
			)
		);
		register_rest_route( self::NAMESPACE, '/topics/(?P<id>\d+)/delete', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'delete_topic' ), 'permission_callback' => $manage ) );
	}

	/**
	 * Preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		$text  = (string) $request->get_param( 'text' );
		$files = $request->get_file_params();

		if ( '' === trim( $text ) && isset( $files['file']['tmp_name'] ) && is_uploaded_file( (string) $files['file']['tmp_name'] ) ) {
			if ( (int) $files['file']['size'] > ImportParser::MAX_BYTES ) {
				return new WP_Error( 'paydarpress_cm_size', 'حجم فایل بیش از ۵ مگابایت است.', array( 'status' => 413 ) );
			}
			$text = (string) file_get_contents( (string) $files['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'paydarpress_cm_empty', 'متن خروجی ContentMaster را بچسبانید یا فایل آن را انتخاب کنید.', array( 'status' => 400 ) );
		}

		try {
			return new WP_REST_Response( ( new ImportService() )->preview( $text ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'paydarpress_cm_invalid', $e->getMessage(), array( 'status' => 422 ) );
		}
	}

	/**
	 * Commit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function commit( WP_REST_Request $request ) {
		try {
			$result = ( new ImportService() )->commit(
				(string) $request->get_param( 'token' ),
				array(
					'topics'           => (array) $request->get_param( 'topics' ),
					'articles'         => (array) $request->get_param( 'articles' ),
					'link_suggestions' => (array) $request->get_param( 'link_suggestions' ),
					'term_descriptions' => (array) $request->get_param( 'term_descriptions' ),
					'seo_updates'      => (array) $request->get_param( 'seo_updates' ),
					'structure_suggestions' => (array) $request->get_param( 'structure_suggestions' ),
				)
			);
		} catch ( Throwable $e ) {
			return new WP_Error( 'paydarpress_cm_commit', $e->getMessage(), array( 'status' => 409 ) );
		}

		$result['pending_links'] = LinkSuggestionRepository::pending_count();

		return new WP_REST_Response( $result );
	}

	/**
	 * Topic list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function topics( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$list     = TopicRepository::list( (string) $request->get_param( 'status' ), $page, $per_page );

		return new WP_REST_Response(
			$list + array(
				'page'        => $page,
				'total_pages' => (int) ceil( $list['total'] / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Accept / reject / reopen a topic.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_topic( WP_REST_Request $request ): WP_REST_Response {
		TopicRepository::update( (int) $request['id'], array( 'status' => (string) $request->get_param( 'status' ) ) );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Deletes a topic.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_topic( WP_REST_Request $request ): WP_REST_Response {
		TopicRepository::delete( (int) $request['id'] );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Confirms that a person reviewed an AI-written draft.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reviewed( WP_REST_Request $request ): WP_REST_Response {
		\PaydarWP\PaydarPress\Policy\ReviewGate::confirm( (int) $request['id'] );
		return new WP_REST_Response( array( 'ok' => true ) );
	}
}
