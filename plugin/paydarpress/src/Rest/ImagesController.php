<?php
/**
 * REST: WebP conversion, duplicate images and careful bulk conversion.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Images\BulkConverter;
use PaydarWP\PaydarPress\Images\ImageHash;
use PaydarWP\PaydarPress\Images\WebpConverter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /images; POST /images/hash-scan; POST /images/bulk { action: start|stop|restore|delete_originals }.
 */
final class ImagesController {

	/**
	 * Routes.
	 */
	public function routes(): void {
		$ns     = UserPrefsController::NAMESPACE;
		$manage = static fn() => current_user_can( Capabilities::MANAGE );
		register_rest_route( $ns, '/images', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get' ), 'permission_callback' => static fn() => current_user_can( Capabilities::VIEW ) ) );
		register_rest_route(
			$ns,
			'/images/hash-scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => function () {
					ImageHash::start();
					return new WP_REST_Response( $this->payload() );
				},
			)
		);
		register_rest_route(
			$ns,
			'/images/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => current_user_can( Capabilities::MANAGE ) && current_user_can( 'upload_files' ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'start', 'stop', 'restore', 'delete_originals' ),
					),
				),
				'callback'            => array( $this, 'bulk' ),
			)
		);
	}

	/**
	 * State.
	 */
	public function get(): WP_REST_Response {
		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * Bulk actions.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk( WP_REST_Request $request ) {
		$action = (string) $request['action'];
		if ( 'start' === $action ) {
			if ( ! WebpConverter::supported() ) {
				return new WP_Error( 'paydarpress_webp', 'سرور سایت ساخت WebP را پشتیبانی نمی‌کند (GD یا Imagick با WebP). از پشتیبانی هاست بخواهید آن را فعال کند.', array( 'status' => 400 ) );
			}
			BulkConverter::start();
		} elseif ( 'stop' === $action ) {
			BulkConverter::stop();
		} elseif ( 'restore' === $action ) {
			BulkConverter::restore_all();
		} else {
			BulkConverter::delete_originals();
		}
		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * Payload.
	 */
	private function payload(): array {
		$stats  = get_option( WebpConverter::STATS, array() );
		$groups = array();
		foreach ( ImageHash::groups( 20 ) as $group ) {
			$groups[] = array_map(
				static fn( $id ) => array(
					'id'       => $id,
					'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
					'thumb'    => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
					'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
					'used_in'  => (int) wp_get_post_parent_id( $id ),
				),
				$group
			);
		}
		return array(
			'supported' => WebpConverter::supported(),
			'settings'  => array(
				'convert_webp'    => (bool) Settings::get( 'images.convert_webp', true ),
				'delete_original' => (bool) Settings::get( 'images.delete_original', true ),
				'max_dimension'   => (int) Settings::get( 'images.max_dimension', 1920 ),
				'webp_quality'    => (int) Settings::get( 'images.webp_quality', 80 ),
			),
			'webp'      => array(
				'files'  => (int) ( $stats['files'] ?? 0 ),
				'before' => (int) ( $stats['before'] ?? 0 ),
				'after'  => (int) ( $stats['after'] ?? 0 ),
			),
			'hash'      => ImageHash::progress(),
			'groups'    => $groups,
			'bulk'      => BulkConverter::report(),
			'alt'       => self::alt_stats(),
			'inline'    => array(
				'per'   => (int) \PaydarWP\PaydarPress\Core\Settings::get( 'images.words_per_inline', 400 ),
				'max'   => (int) \PaydarWP\PaydarPress\Core\Settings::get( 'images.inline_max', 5 ),
				'none'  => self::count_code( 'IMG_NO_INLINE' ),
				'few'   => self::count_code( 'IMG_FEW_INLINE' ),
				'many'  => self::count_code( 'IMG_TOO_MANY_INLINE' ),
			),
		);
	}

	/**
	 * Posts with missing / generic alt texts (from the last audit).
	 */
	private static function alt_stats(): array {
		return array(
			'missing' => self::count_code( 'IMG_MISSING_ALT' ),
			'generic' => self::count_code( 'IMG_GENERIC_ALT' ),
		);
	}

	/**
	 * Open findings of one code.
	 *
	 * @param string $code Code.
	 */
	private static function count_code( string $code ): int {
		$stats = get_option( \PaydarWP\PaydarPress\Audit\AuditEngine::OPTION_STATS, array() );
		return (int) ( is_array( $stats ) ? ( $stats['by_code'][ $code ] ?? 0 ) : 0 );
	}
}
