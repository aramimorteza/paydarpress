<?php
/**
 * REST: internal link approval queue.
 *
 *  GET  /links                 ?status&page&per_page                     (view)
 *  POST /links                 { source_id, target_id, anchor }           (manage)
 *  POST /links/generate        suggestions for orphan content             (manage)
 *  POST /links/bulk            { ids[], action: apply|reject }            (manage)
 *  POST /links/{id}/{action}   apply|reject|undo|done|restore             (manage)
 *  POST /links/{id}/anchor     { anchor }                                 (manage)
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Import\LinkSuggestionRepository;
use PaydarWP\PaydarPress\Links\LinkInserter;
use PaydarWP\PaydarPress\Links\LinkQueue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links controller.
 */
final class LinksController {

	public const REST_NAMESPACE = 'paydarpress/v1';

	/** @var LinkQueue */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param LinkQueue $queue Queue.
	 */
	public function __construct( LinkQueue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Routes.
	 */
	public function routes(): void {
		$manage = static fn() => current_user_can( Capabilities::MANAGE );
		$view   = static fn() => current_user_can( Capabilities::VIEW );

		register_rest_route(
			self::REST_NAMESPACE,
			'/links',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $view,
					'args'                => array(
						'status'   => array(
							'type'    => 'string',
							'default' => 'pending',
							'enum'    => array_merge( array( '' ), LinkQueue::STATUSES ),
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $manage,
					'args'                => array(
						'source_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'target_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'anchor'    => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route( self::REST_NAMESPACE, '/links/generate', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'generate' ), 'permission_callback' => $manage ) );
		register_rest_route(
			self::REST_NAMESPACE,
			'/links/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $manage,
				'args'                => array(
					'ids'    => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'apply', 'reject' ),
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/links/(?P<id>\d+)/(?P<action>apply|reject|undo|done|restore)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'action' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/links/(?P<id>\d+)/anchor',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'anchor' ),
				'permission_callback' => $manage,
				'args'                => array(
					'anchor' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * List.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->queue->list( (string) $request->get_param( 'status' ), (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) ) );
	}

	/**
	 * Adds a suggestion (from the orphan content page). The anchor must appear in the source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$source = get_post( (int) $request->get_param( 'source_id' ) );
		$target = get_post( (int) $request->get_param( 'target_id' ) );
		$anchor = mb_substr( trim( wp_strip_all_tags( (string) $request->get_param( 'anchor' ) ) ), 0, 255 );

		if ( ! $source || ! $target || '' === $anchor ) {
			return new WP_Error( 'paydarpress_links_invalid', 'نوشته مبدأ، مقصد یا متن لنگر نامعتبر است.', array( 'status' => 400 ) );
		}

		// Prefer a phrase that really appears in the source over the suggested keyword/title.
		$found = null !== LinkInserter::locate( (string) $source->post_content, $anchor );
		if ( ! $found ) {
			$better = $this->queue->find_anchor( $source->ID, $target->ID );
			if ( null !== $better ) {
				$anchor = $better;
				$found  = true;
			}
		}

		$id = LinkSuggestionRepository::insert( $source->ID, $target->ID, $anchor, '', 'manual', 0 );

		return new WP_REST_Response(
			array(
				'id'     => $id,
				'anchor' => $anchor,
				'found'  => $found,
			)
		);
	}

	/**
	 * Generates suggestions for orphan content.
	 */
	public function generate(): WP_REST_Response {
		return new WP_REST_Response( $this->queue->generate_for_orphans() );
	}

	/**
	 * Bulk apply / reject (at most 30 per request).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk( WP_REST_Request $request ): WP_REST_Response {
		$items = array();
		foreach ( array_slice( array_map( 'intval', (array) $request->get_param( 'ids' ) ), 0, 30 ) as $id ) {
			$items[] = 'apply' === $request->get_param( 'action' ) ? $this->queue->apply( $id ) : $this->queue->set_status( $id, 'rejected' );
		}

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * One action.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function action( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];

		switch ( (string) $request['action'] ) {
			case 'apply':
				$item = $this->queue->apply( $id );
				break;
			case 'undo':
				$item = $this->queue->undo( $id );
				break;
			case 'done':
				$item = $this->queue->set_status( $id, 'applied' );
				break;
			case 'restore':
				$item = $this->queue->set_status( $id, 'pending' );
				break;
			default:
				$item = $this->queue->set_status( $id, 'rejected' );
		}

		return new WP_REST_Response( $item );
	}

	/**
	 * Changes the anchor.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function anchor( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->queue->update_anchor( (int) $request['id'], (string) $request->get_param( 'anchor' ) ) );
	}
}
