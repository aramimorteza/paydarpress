<?php
/**
 * Training and support, license and updates.
 *
 *  GET  /support                  brand, links, products (view)
 *  GET  /support/report?site=0|1  technical report text (manage)
 *  GET  /license                  license and update state (manage)
 *  POST /license/activate         { key } (manage)
 *  POST /license/deactivate       (manage)
 *  POST /license/check            (manage)
 *  POST /license/relay            { relay } (manage)
 *  POST /license/update-check     fetches the latest version now (manage)
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Commerce\Brand;
use PaydarWP\PaydarPress\Commerce\License;
use PaydarWP\PaydarPress\Commerce\SupportReport;
use PaydarWP\PaydarPress\Commerce\Updater;
use PaydarWP\PaydarPress\Core\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Support routes.
 */
final class SupportController {

	public const NAMESPACE = 'paydarpress/v1';

	/**
	 * Registers routes.
	 */
	public function routes(): void {
		$ns     = self::NAMESPACE;
		$view   = static fn() => current_user_can( Capabilities::VIEW );
		$manage = static fn() => current_user_can( Capabilities::MANAGE );
		$wrap   = static function ( callable $fn ) {
			return static function ( WP_REST_Request $r ) use ( $fn ) {
				try {
					return new WP_REST_Response( $fn( $r ) );
				} catch ( \RuntimeException | \InvalidArgumentException $e ) {
					return new \WP_Error( 'paydarpress_license', $e->getMessage(), array( 'status' => 400 ) );
				}
			};
		};

		register_rest_route(
			$ns,
			'/support',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $view,
				'callback'            => static fn() => new WP_REST_Response(
					array(
						'brand'    => Brand::info(),
						'products' => Brand::products(),
						'version'  => PAYDARPRESS_VERSION,
					)
				),
			)
		);
		register_rest_route( $ns, '/support/report', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => $manage, 'callback' => static fn( $r ) => new WP_REST_Response( array( 'text' => SupportReport::text( (bool) $r->get_param( 'site' ) ) ) ) ) );
		register_rest_route( $ns, '/license', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => $manage, 'callback' => static fn() => new WP_REST_Response( License::view() ) ) );
		register_rest_route( $ns, '/license/activate', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn( $r ) => License::activate( (string) $r->get_param( 'key' ) ) ) ) );
		register_rest_route( $ns, '/license/deactivate', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn() => License::deactivate() ) ) );
		register_rest_route( $ns, '/license/check', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn() => License::check() ) ) );
		register_rest_route( $ns, '/license/relay', array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $manage, 'callback' => $wrap( static fn( $r ) => License::set_relay( (string) $r->get_param( 'relay' ) ) ) ) );
		register_rest_route(
			$ns,
			'/license/update-check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => $wrap(
					static function () {
						Updater::fetch( true );
						// Let WordPress rebuild its own update list with the fresh answer.
						delete_site_transient( 'update_plugins' );
						return License::view();
					}
				),
			)
		);
	}
}
