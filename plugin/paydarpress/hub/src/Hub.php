<?php
/**
 * PaydarWP Hub: shared top-level admin menu, fonts and design tokens for all PaydarWP products.
 *
 * @package PaydarWP\Hub
 */

declare(strict_types=1);

namespace PaydarWP\Hub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared services for PaydarWP products.
 *
 * Products interact with the Hub only through:
 *  - the `paydarwp_hub_products` filter (to appear on the Hub overview page),
 *  - Hub::MENU_SLUG (as the parent slug for add_submenu_page()),
 *  - the style handles Hub::STYLE_FONTS and Hub::STYLE_TOKENS.
 */
final class Hub {

	public const MENU_SLUG    = 'paydarwp';
	public const STYLE_FONTS  = 'paydarwp-hub-fonts';
	public const STYLE_TOKENS = 'paydarwp-hub-tokens';
	public const STYLE_ADMIN  = 'paydarwp-hub-admin';

	/** @var string */
	private static $version = '';

	/** @var string */
	private static $dir = '';

	/** @var string */
	private static $url = '';

	/**
	 * Initializes the Hub. Called once by hub.php with the newest registered copy.
	 *
	 * @param string $version Hub version.
	 * @param string $dir     Absolute path of the Hub copy (with trailing slash).
	 * @param string $url     URL of the Hub copy (with trailing slash).
	 */
	public static function init( string $version, string $dir, string $url ): void {
		if ( '' !== self::$version ) {
			return;
		}

		self::$version = $version;
		self::$dir     = $dir;
		self::$url     = $url;

		// Priority 5 so the parent menu exists before products add submenus (default priority 10).
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'register_styles' ), 1 );
		add_action( 'admin_menu', array( self::class, 'remove_duplicate_submenu' ), 999 );
	}

	/**
	 * Version of the loaded Hub copy.
	 */
	public static function version(): string {
		return self::$version;
	}

	/**
	 * URL of the loaded Hub copy.
	 */
	public static function url(): string {
		return self::$url;
	}

	/**
	 * Registers the shared top-level "پایدار وردپرس" menu.
	 */
	public static function register_menu(): void {
		$capability = (string) apply_filters( 'paydarwp_hub_menu_capability', 'edit_posts' );

		add_menu_page(
			__( 'پایدار وردپرس', 'paydarwp-hub' ),
			__( 'پایدار وردپرس', 'paydarwp-hub' ),
			$capability,
			self::MENU_SLUG,
			array( self::class, 'render_overview' ),
			self::menu_icon(),
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'محصولات پایدار وردپرس', 'paydarwp-hub' ),
			__( 'همه محصولات', 'paydarwp-hub' ),
			$capability,
			self::MENU_SLUG,
			array( self::class, 'render_overview' ),
			100
		);
	}

	/**
	 * Moves the "همه محصولات" item to the end so products appear first in the submenu.
	 */
	public static function remove_duplicate_submenu(): void {
		global $submenu;

		if ( empty( $submenu[ self::MENU_SLUG ] ) || ! is_array( $submenu[ self::MENU_SLUG ] ) ) {
			return;
		}

		$items    = $submenu[ self::MENU_SLUG ];
		$overview = null;

		foreach ( $items as $index => $item ) {
			if ( isset( $item[2] ) && self::MENU_SLUG === $item[2] ) {
				$overview = $item;
				unset( $items[ $index ] );
				break;
			}
		}

		if ( null !== $overview ) {
			$items[] = $overview;
		}

		$submenu[ self::MENU_SLUG ] = array_values( $items ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Registers shared style handles. Products enqueue them only on their own pages.
	 */
	public static function register_styles(): void {
		wp_register_style( self::STYLE_FONTS, self::$url . 'assets/css/fonts.css', array(), self::$version );
		wp_register_style( self::STYLE_TOKENS, self::$url . 'assets/css/tokens.css', array( self::STYLE_FONTS ), self::$version );
		wp_register_style( self::STYLE_ADMIN, self::$url . 'assets/css/hub-admin.css', array( self::STYLE_TOKENS ), self::$version );
	}

	/**
	 * Renders the Hub overview page listing active PaydarWP products.
	 */
	public static function render_overview(): void {
		wp_enqueue_style( self::STYLE_ADMIN );

		/**
		 * Filters the list of active PaydarWP products shown on the Hub page.
		 *
		 * Each item: [ 'name' => string, 'description' => string, 'version' => string, 'url' => string ].
		 */
		$products = (array) apply_filters( 'paydarwp_hub_products', array() );

		echo '<div class="wrap paydarwp-hub" dir="rtl">';
		echo '<h1>' . esc_html__( 'پایدار وردپرس', 'paydarwp-hub' ) . '</h1>';
		echo '<p class="paydarwp-hub__lead">' . esc_html__( 'محصولات فعال پایدار وردپرس روی این سایت:', 'paydarwp-hub' ) . '</p>';
		echo '<div class="paydarwp-hub__grid">';

		foreach ( $products as $product ) {
			$name        = isset( $product['name'] ) ? (string) $product['name'] : '';
			$description = isset( $product['description'] ) ? (string) $product['description'] : '';
			$version     = isset( $product['version'] ) ? (string) $product['version'] : '';
			$url         = isset( $product['url'] ) ? (string) $product['url'] : '';

			echo '<div class="paydarwp-hub__card">';
			echo '<h2>' . esc_html( $name ) . '</h2>';
			echo '<p>' . esc_html( $description ) . '</p>';
			if ( '' !== $version ) {
				/* translators: %s: product version. */
				echo '<p class="paydarwp-hub__meta">' . esc_html( sprintf( __( 'نسخه %s', 'paydarwp-hub' ), $version ) ) . '</p>';
			}
			if ( '' !== $url ) {
				echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'ورود', 'paydarwp-hub' ) . '</a>';
			}
			echo '</div>';
		}

		echo '</div></div>';
	}

	/**
	 * Inline SVG menu icon (base64 data URI, as required by add_menu_page).
	 */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5 2 5.5v9l8 4 8-4v-9l-8-4Zm0 2.2 5.6 2.8L10 9.3 4.4 6.5 10 3.7ZM4 8.1l5 2.5v6.1l-5-2.5V8.1Zm7 8.6v-6.1l5-2.5v6.1l-5 2.5Z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
