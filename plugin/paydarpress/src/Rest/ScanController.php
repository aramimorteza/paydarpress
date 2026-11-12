<?php
/**
 * REST: start, cancel and monitor scans.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Queue\Queue;
use PaydarWP\PaydarPress\Scanner\ScanManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpoints:
 *  POST /paydarpress/v1/scan
 *  POST /paydarpress/v1/scan/cancel
 *  GET  /paydarpress/v1/scan/status?tick=1
 */
final class ScanController {

	/** @var ScanManager */
	private $scans;

	/**
	 * @param ScanManager $scans Scan manager.
	 */
	public function __construct( ScanManager $scans ) {
		$this->scans = $scans;
	}

	/**
	 * Registers routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function routes(): void {
		register_rest_route(
			UserPrefsController::NAMESPACE,
			'/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => static fn() => current_user_can( Capabilities::MANAGE ),
			)
		);

		register_rest_route(
			UserPrefsController::NAMESPACE,
			'/scan/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => static fn() => current_user_can( Capabilities::MANAGE ),
			)
		);

		register_rest_route(
			UserPrefsController::NAMESPACE,
			'/scan/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => static fn() => current_user_can( Capabilities::VIEW ),
				'args'                => array(
					'tick' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Starts a full scan.
	 */
	public function start(): WP_REST_Response {
		$this->scans->start();

		// Process the first batch right away so the user sees progress immediately.
		Queue::run_due( 5 );

		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * Cancels the running scan.
	 */
	public function cancel(): WP_REST_Response {
		$this->scans->cancel();

		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * Scan status. With tick=1 (managers only) it also processes due queue items, so scans
	 * finish even when WP-Cron does not run.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		$latest = $this->scans->latest_scan();

		if ( $request->get_param( 'tick' ) && null !== $latest && 'running' === $latest['status'] && current_user_can( Capabilities::MANAGE ) ) {
			Queue::run_due( 8 );
		}

		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * Response body.
	 */
	private function payload(): array {
		$scan = $this->scans->latest_scan();

		if ( null === $scan ) {
			return array( 'scan' => null );
		}

		$total     = (int) $scan['total'];
		$processed = (int) $scan['processed'];

		return array(
			'scan' => array(
				'id'          => (int) $scan['id'],
				'type'        => $scan['type'],
				'status'      => $scan['status'],
				'total'       => $total,
				'processed'   => $processed,
				'percent'     => $total > 0 ? (int) floor( $processed * 100 / $total ) : ( 'completed' === $scan['status'] ? 100 : 0 ),
				'stage'       => 'running' === $scan['status'] && 'menus_done' === $scan['error'] ? 'links' : ( 'running' === $scan['status'] ? 'content' : null ),
				'started_at'  => self::iso( $scan['started_at'] ),
				'finished_at' => self::iso( $scan['finished_at'] ),
				'error'       => 'failed' === $scan['status'] ? $scan['error'] : null,
			),
		);
	}

	/**
	 * GMT MySQL datetime → ISO 8601.
	 *
	 * @param mixed $datetime Datetime or null.
	 */
	public static function iso( $datetime ): ?string {
		return empty( $datetime ) ? null : gmdate( 'c', (int) strtotime( $datetime . ' UTC' ) );
	}
}
