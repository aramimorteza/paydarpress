<?php
/**
 * Detects and infers profile fields from the site itself (no AI). Spec section 9.1.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Profile;

use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Scanner\MenuScanner;
use PaydarWP\PaydarPress\Seo\SeoDetector;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All rules are deterministic and explainable; the user confirms every inferred value.
 */
final class ProfileInference {

	private const KEY_PAGE_WORDS = array( 'contact', 'about', 'services', 'service', 'pricing', 'price', 'consultation', 'booking', 'appointment', 'تماس', 'درباره', 'خدمات', 'تعرفه', 'قیمت', 'مشاوره', 'رزرو', 'نوبت', 'ارتباط' );

	private const CTA_WORDS = array( 'contact', 'consultation', 'booking', 'appointment', 'تماس', 'مشاوره', 'رزرو', 'نوبت', 'ارتباط', 'درخواست', 'سفارش' );

	private const CITIES = array( 'تهران', 'کرج', 'مشهد', 'اصفهان', 'شیراز', 'تبریز', 'قم', 'اهواز', 'کرمانشاه', 'ارومیه', 'رشت', 'زاهدان', 'کرمان', 'همدان', 'یزد', 'اردبیل', 'بندرعباس', 'اراک', 'زنجان', 'قزوین', 'سنندج', 'خرم‌آباد', 'گرگان', 'ساری', 'بجنورد', 'بوشهر', 'بیرجند', 'ایلام', 'شهرکرد', 'سمنان', 'یاسوج', 'کاشان', 'شهریار', 'اسلامشهر', 'ورامین', 'پاکدشت', 'شهر ری', 'قرچک', 'نظرآباد', 'هشتگرد', 'فردیس', 'گوهردشت', 'عظیمیه', 'مهرشهر', 'پردیس', 'دماوند' );

	private const MEDICAL_WORDS  = array( 'پزشک', 'دکتر', 'کلینیک', 'درمان', 'دندان', 'بیمارستان', 'داروخانه', 'سلامت', 'پوست', 'زیبایی' );

	/**
	 * Runs detection and inference and stores the result.
	 *
	 * @return array Updated profile.
	 */
	public static function run(): array {
		list( $detected, $inferred ) = self::compute();

		return ProfileRepository::apply_inference( $detected, $inferred );
	}

	/**
	 * Computes detected and inferred values without saving.
	 *
	 * @return array{0:array, 1:array}
	 */
	public static function compute(): array {
		$seo_labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
			'none'     => 'ندارد',
		);

		$detected = array(
			'site_name'    => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'domain'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'language'     => get_locale(),
			'seo_plugin'   => $seo_labels[ SeoDetector::adapter()->key() ] ?? 'ندارد',
			'page_builder' => self::page_builder(),
			'woocommerce'  => class_exists( 'WooCommerce' ),
		);

		$haystack = PersianNormalizer::normalize( implode( ' ', array_merge( array( get_bloginfo( 'name' ), get_bloginfo( 'description' ) ), self::category_names() ) ) );
		$industry = Industries::detect( $haystack, (bool) $detected['woocommerce'] );
		$profile  = Industries::all()[ $industry ];

		$key_pages = self::key_pages();

		$inferred = array(
			'industry_module'      => $industry,
			'fact_sensitivity'     => ( $profile['ymyl'] || self::contains_any( $haystack, self::MEDICAL_WORDS ) ) ? 'YMYL' : 'normal',
			'geographic_scope'     => implode( '، ', self::cities( $haystack ) ),
			'primary_content_goal' => $profile['goal'],
			'business_offer'       => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
			'knowledge_level'      => $profile['knowledge'],
			'industry_rules'       => $profile['rules'],
			'primary_keyword_patterns' => $profile['patterns'],
			'key_pages'            => $key_pages,
			'cta_options'          => self::cta_options( $key_pages ),
			'categories_scope'     => self::categories(),
		);

		return array( $detected, $inferred );
	}

	/**
	 * Page builder name.
	 */
	private static function page_builder(): string {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			return 'Elementor';
		}
		if ( defined( 'WPB_VC_VERSION' ) ) {
			return 'WPBakery';
		}
		if ( class_exists( 'Classic_Editor' ) ) {
			return 'ویرایشگر کلاسیک';
		}

		return 'ویرایشگر بلوکی (گوتنبرگ)';
	}

	/**
	 * Key pages: pages in the primary menu (high priority) and pages whose slug or title
	 * contains a key word. System pages are excluded.
	 *
	 * @return array<int, array>
	 */
	private static function key_pages(): array {
		$system = array_flip( AuditEngine::system_page_ids() );
		$pages  = array();

		$menu_id = MenuScanner::primary_menu_id();
		if ( null !== $menu_id ) {
			foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
				$id = (int) $item->object_id;
				if ( 'post_type' === $item->type && 'page' === $item->object && $id > 0 && ! isset( $system[ $id ] ) && 'publish' === get_post_status( $id ) ) {
					$pages[ $id ] = array(
						'id'          => $id,
						'priority'    => 'high',
						'detected_by' => 'menu',
					);
				}
			}
		}

		$candidates = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);

		foreach ( $candidates as $page ) {
			if ( isset( $pages[ $page->ID ] ) || isset( $system[ $page->ID ] ) ) {
				continue;
			}
			$text = PersianNormalizer::normalize( rawurldecode( $page->post_name ) . ' ' . $page->post_title );
			if ( self::contains_any( $text, self::KEY_PAGE_WORDS ) ) {
				$pages[ $page->ID ] = array(
					'id'          => $page->ID,
					'priority'    => 'normal',
					'detected_by' => 'keyword',
				);
			}
		}

		return array_slice( array_values( $pages ), 0, 12 );
	}

	/**
	 * CTA options: key pages that look like contact/booking pages or contain a form.
	 *
	 * @param array $key_pages Key pages.
	 * @return array<int, array>
	 */
	private static function cta_options( array $key_pages ): array {
		$ctas = array();

		foreach ( $key_pages as $page ) {
			$post = get_post( (int) $page['id'] );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$text = PersianNormalizer::normalize( rawurldecode( $post->post_name ) . ' ' . $post->post_title );
			if ( self::contains_any( $text, self::CTA_WORDS ) || self::has_form( $post ) ) {
				$ctas[] = array(
					'name'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'url'      => (string) get_permalink( $post ),
					'use_when' => 'پایان مقالات مرتبط',
				);
			}
		}

		return array_slice( $ctas, 0, 4 );
	}

	/**
	 * Whether the page contains a contact form (CF7, WPForms, Gravity Forms, Elementor form).
	 *
	 * @param WP_Post $post Page.
	 */
	private static function has_form( WP_Post $post ): bool {
		if ( preg_match( '/\[(contact-form-7|wpforms|gravityform|formidable|ninja_form)|wp:(contact-form-7|wpforms|gravityforms)/i', $post->post_content ) ) {
			return true;
		}

		$elementor = (string) get_post_meta( $post->ID, '_elementor_data', true );

		return '' !== $elementor && false !== strpos( $elementor, '"widgetType":"form"' );
	}

	/**
	 * Categories with content (scope left for the user).
	 *
	 * @return array<int, array>
	 */
	private static function categories(): array {
		$taxonomies = array_values( array_filter( array( 'category', 'product_cat' ), 'taxonomy_exists' ) );
		$terms      = get_terms( array( 'taxonomy' => $taxonomies, 'hide_empty' => true, 'number' => 100, 'orderby' => 'count', 'order' => 'DESC' ) );
		$default    = (int) get_option( 'default_category' );
		$out        = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( (int) $term->term_id === $default && (int) $term->count <= 1 ) {
				continue;
			}
			$out[] = array(
				'id'              => (int) $term->term_id,
				'scope'           => '',
				'search_patterns' => '',
			);
		}

		return $out;
	}

	/**
	 * Category names (for keyword detection).
	 *
	 * @return string[]
	 */
	private static function category_names(): array {
		$terms = get_terms( array( 'taxonomy' => array_values( array_filter( array( 'category', 'product_cat' ), 'taxonomy_exists' ) ), 'hide_empty' => true, 'fields' => 'names', 'number' => 100 ) );

		return is_array( $terms ) ? array_map( 'strval', $terms ) : array();
	}

	/**
	 * Cities mentioned.
	 *
	 * @param string $haystack Normalized text.
	 * @return string[]
	 */
	private static function cities( string $haystack ): array {
		$found = array();
		foreach ( self::CITIES as $city ) {
			if ( false !== mb_strpos( $haystack, PersianNormalizer::normalize( $city ) ) ) {
				$found[] = $city;
			}
		}

		return $found;
	}

	/**
	 * Whether any word occurs in the normalized text.
	 *
	 * @param string   $haystack Normalized text.
	 * @param string[] $words    Words.
	 */
	private static function contains_any( string $haystack, array $words ): bool {
		foreach ( $words as $word ) {
			if ( false !== mb_strpos( $haystack, PersianNormalizer::normalize( $word ) ) ) {
				return true;
			}
		}

		return false;
	}
}
