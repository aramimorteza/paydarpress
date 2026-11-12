<?php
/**
 * Admin page: registers the PaydarPress submenu and loads the React app.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Admin;

use PaydarWP\Hub\Hub;
use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The PaydarPress admin screen. All sections live in one page with internal (hash) routing.
 */
final class AdminPage {

	public const SLUG = 'paydarpress';

	/** @var string Hook suffix returned by add_submenu_page(). */
	private $hook = '';

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Adds "پایدارپرس" under the shared "پایدار وردپرس" menu.
	 */
	public function add_menu(): void {
		$title = __( 'پایدارپرس', 'paydarpress' );

		if ( class_exists( Hub::class, false ) ) {
			$hook = add_submenu_page(
				Hub::MENU_SLUG,
				$title,
				$title,
				Capabilities::VIEW,
				self::SLUG,
				array( $this, 'render' ),
				10
			);
		} else {
			// Fallback: should not happen, the Hub ships with the plugin.
			$hook = add_menu_page( $title, $title, Capabilities::VIEW, self::SLUG, array( $this, 'render' ), 'dashicons-chart-area', 58 );
		}

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Renders the app container. The React app mounts into it.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'paydarpress' ) );
		}

		echo '<div class="wrap paydarpress-wrap">';
		echo '<div id="paydarpress-app" class="paydarpress-app">';

		if ( ! $this->build_exists() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'فایل‌های رابط کاربری پایدارپرس ساخته نشده‌اند. در پوشه افزونه دستور npm run build را اجرا کنید یا نسخه کامل افزونه را نصب کنید.', 'paydarpress' );
			echo '</p></div>';
		} else {
			echo '<p class="paydarpress-loading">' . esc_html__( 'در حال بارگذاری…', 'paydarpress' ) . '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * Loads assets only on the PaydarPress screen (and fonts everywhere if enabled).
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( (bool) Settings::get( 'ui.font_everywhere', false ) && wp_style_is( Hub::STYLE_FONTS, 'registered' ) ) {
			wp_enqueue_style( Hub::STYLE_FONTS );
			wp_add_inline_style( Hub::STYLE_FONTS, 'body, #wpadminbar *, .wp-core-ui button, input, select, textarea { font-family: "Vazirmatn", Tahoma, sans-serif; }' );
		}

		if ( '' === $this->hook || $hook_suffix !== $this->hook || ! $this->build_exists() ) {
			return;
		}

		$asset_file = PAYDARPRESS_DIR . 'assets/build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => PAYDARPRESS_VERSION,
		);

		$style_deps = array( 'wp-components' );
		if ( wp_style_is( Hub::STYLE_TOKENS, 'registered' ) ) {
			$style_deps[] = Hub::STYLE_TOKENS;
		}

		wp_enqueue_style( 'paydarpress-admin', PAYDARPRESS_URL . 'assets/build/style-index.css', $style_deps, $asset['version'] );

		// WordPress < 6.6 does not register react-jsx-runtime; provide a small shim.
		if ( in_array( 'react-jsx-runtime', $asset['dependencies'], true ) && ! wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
			wp_register_script( 'react-jsx-runtime', PAYDARPRESS_URL . 'assets/compat/react-jsx-runtime.js', array( 'react' ), PAYDARPRESS_VERSION, true );
		}

		wp_enqueue_script( 'paydarpress-admin', PAYDARPRESS_URL . 'assets/build/index.js', $asset['dependencies'], $asset['version'], true );

		wp_add_inline_script(
			'paydarpress-admin',
			'window.paydarpress = ' . wp_json_encode( $this->script_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);

		wp_set_script_translations( 'paydarpress-admin', 'paydarpress', PAYDARPRESS_DIR . 'languages' );
	}

	/**
	 * Adds a body class on the PaydarPress screen.
	 *
	 * @param string $classes Space-separated classes.
	 */
	public function body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && '' !== $this->hook && $screen->id === $this->hook ) {
			$classes .= ' paydarpress-screen';
		}

		return $classes;
	}

	/**
	 * Redirects to the welcome screen once, right after a single (non-bulk) activation.
	 */
	public function activation_redirect(): void {
		if ( ! get_transient( 'paydarpress_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'paydarpress_activation_redirect' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of core's bulk activation flag.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '#/welcome' ) );
		exit;
	}

	/**
	 * Data passed to the React app as window.paydarpress.
	 */
	private function script_data(): array {
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'paydarpress_dismissed_help', true );
		$ui_mode   = get_user_meta( $user_id, 'paydarpress_ui_mode', true );

		return array(
			'version'       => PAYDARPRESS_VERSION,
			'restNamespace' => 'paydarpress/v1',
			'adminUrl'      => admin_url(),
			'pageUrl'       => admin_url( 'admin.php?page=' . self::SLUG ),
			'siteName'      => get_bloginfo( 'name' ),
			'uiMode'        => in_array( $ui_mode, array( 'simple', 'pro' ), true ) ? $ui_mode : 'simple',
			'dismissedHelp' => is_array( $dismissed ) ? array_values( $dismissed ) : array(),
			'caps'          => array(
				'manage' => current_user_can( Capabilities::MANAGE ),
				'export' => current_user_can( Capabilities::EXPORT ),
			),
			'modules'       => array(
				'woocommerce' => class_exists( 'WooCommerce' ),
			),
			'badges'        => self::badges(),
		);
	}

	/**
	 * Counts shown next to menu items (things waiting for the user).
	 *
	 * @return array<string, int>
	 */
	public static function badges(): array {
		global $wpdb;

		$stats  = get_option( \PaydarWP\PaydarPress\Audit\AuditEngine::OPTION_STATS, array() );
		$stats  = is_array( $stats ) ? $stats : array();
		$topics = \PaydarWP\PaydarPress\Database\Schema::table( 'topics' );

		return array(
			'audit'   => (int) ( $stats['by_severity']['high'] ?? 0 ),
			'orphans' => (int) ( $stats['orphans'] ?? 0 ),
			'refresh' => (int) ( $stats['refresh'] ?? 0 ),
			'links'   => \PaydarWP\PaydarPress\Import\LinkSuggestionRepository::pending_count(),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'topics'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$topics} WHERE status = 'new'" ),
		);
	}

	/**
	 * Whether the compiled React app exists.
	 */
	private function build_exists(): bool {
		return file_exists( PAYDARPRESS_DIR . 'assets/build/index.js' );
	}
}
