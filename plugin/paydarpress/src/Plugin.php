<?php
/**
 * Plugin entry point.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress;

use PaydarWP\PaydarPress\Admin\AdminPage;
use PaydarWP\PaydarPress\Database\Installer;
use PaydarWP\PaydarPress\Rest\InventoryController;
use PaydarWP\PaydarPress\Rest\ScanController;
use PaydarWP\PaydarPress\Rest\UserPrefsController;
use PaydarWP\PaydarPress\Scanner\ScanManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots PaydarPress services.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks. Runs on `plugins_loaded`.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'paydarpress', false, dirname( plugin_basename( PAYDARPRESS_FILE ) ) . '/languages' );

		// Runs pending migrations after updates (activation hook does not run on plugin updates).
		Installer::maybe_upgrade();

		$scans = new ScanManager();
		$scans->register();

		( new UserPrefsController() )->register();
		( new ScanController( $scans ) )->register();
		( new InventoryController() )->register();

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}

		add_filter( 'paydarwp_hub_products', array( $this, 'register_hub_product' ) );
	}

	/**
	 * Adds PaydarPress to the PaydarWP Hub overview page.
	 *
	 * @param array $products Registered products.
	 * @return array
	 */
	public function register_hub_product( array $products ): array {
		$products[] = array(
			'name'        => __( 'پایدارپرس', 'paydarpress' ),
			'description' => __( 'شناخت سایت، ممیزی سلامت محتوا و مدیریت هوشمند محتوا.', 'paydarpress' ),
			'version'     => PAYDARPRESS_VERSION,
			'url'         => admin_url( 'admin.php?page=' . AdminPage::SLUG ),
		);

		return $products;
	}
}
