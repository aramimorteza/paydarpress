<?php
/**
 * Makes key pages a matter of confirming suggestions instead of filling a form:
 * finds likely key pages with the reason, fills their fields, warns about poor choices
 * (blog archive, home, cart, a single article…) and says what each choice will do.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Links;

use PaydarWP\PaydarPress\Profile\ProfileRepository;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Suggestions, guards and impact for key pages.
 */
final class KeyPageAdvisor {

	public const MIN = 3;
	public const MAX = 6;
	public const TOO_MANY = 8;

	/** Words that suggest a page where visitors act. */
	private const ACTION_WORDS = array( 'contact', 'services', 'service', 'pricing', 'price', 'consultation', 'booking', 'order', 'wholesale', 'samples', 'portfolio', 'تماس', 'خدمات', 'تعرفه', 'قیمت', 'مشاوره', 'رزرو', 'نوبت', 'سفارش', 'عمده', 'نمونه کار', 'استعلام' );

	/** Words too common to tie a category to a page. */
	private const STOP = array( 'و', 'در', 'به', 'از', 'با', 'برای', 'های', 'ها', 'صفحه', 'آنیل', 'خرید', 'فروش' );

	/**
	 * Recommended layout per industry.
	 */
	private const TEMPLATES = array(
		'ecommerce-retail'      => 'دسته‌های اصلی محصول (پرفروش‌ترین‌ها) + صفحه تماس یا راهنمای خرید',
		'product-manufacturing' => 'دسته‌های اصلی محصول + صفحه سفارش یا تماس + نمونه‌کارها',
		'b2b-industrial'        => 'صفحه‌های محصول یا خدمت اصلی + استعلام قیمت',
		'legal'                 => 'صفحه‌های خدمات اصلی (هر حوزه دعوی) + رزرو مشاوره',
		'medical-health'        => 'صفحه‌های خدمات و درمان اصلی + نوبت‌دهی',
		'beauty-wellness'       => 'صفحه‌های خدمات اصلی + رزرو نوبت',
		'finance-insurance'     => 'صفحه‌های محصول یا خدمت اصلی + مشاوره',
		'real-estate'           => 'صفحه‌های فایل‌ها یا خدمات اصلی + تماس با مشاور',
		'construction-home'     => 'صفحه‌های خدمات اصلی + استعلام قیمت + نمونه‌کارها',
		'local-services'        => 'صفحه‌های خدمات + تعرفه + تماس',
		'education'             => 'صفحه‌های دوره اصلی + ثبت‌نام یا مشاوره',
		'language-learning'     => 'صفحه‌های دوره یا کلاس اصلی + تعیین سطح یا ثبت‌نام',
		'technology-software'   => 'صفحه محصول + قیمت‌ها + درخواست دمو',
		'tourism-travel'        => 'صفحه‌های تور یا مقصد اصلی + رزرو',
		'food-restaurant'       => 'منو + سفارش آنلاین یا رزرو',
		'automotive'            => 'صفحه‌های خدمات یا محصول اصلی + نوبت یا تماس',
	);

	/**
	 * Advice for the editor: suggestions not yet chosen, guards and impact of the chosen ones.
	 */
	public static function advice(): array {
		$chosen = KeyPageLinks::pages();
		$guards = array();
		$impact = array();
		foreach ( $chosen as $id => $page ) {
			$g = self::guard( (int) $id );
			if ( '' !== $g ) {
				$guards[ $id ] = $g;
			}
			$impact[ $id ] = self::impact( $page );
		}
		$module = (string) ProfileRepository::value( 'industry_module' );
		$count  = count( $chosen );
		return array(
			'candidates' => self::candidates( array_map( 'intval', array_keys( $chosen ) ) ),
			'guards'     => $guards,
			'impact'     => $impact,
			'template'   => self::TEMPLATES[ $module ] ?? 'صفحه‌های خدمت یا محصول اصلی + صفحه تماس',
			'total_posts' => (int) wp_count_posts( 'post' )->publish,
			'min'        => self::MIN,
			'max'        => self::MAX,
			'count_note' => $count > self::TOO_MANY
				? sprintf( '%s صفحه کلیدی زیاد است: هر مقاله حداکثر ۲ صفحه کلیدی لینک می‌دهد، پس لینک‌ها پخش و کم‌اثر می‌شوند. ۳ تا ۶ صفحه مهم‌تر را نگه دارید.', PersianNormalizer::fa_number( $count ) )
				: ( $count > 0 && $count < self::MIN ? 'معمولاً ۳ تا ۶ صفحه کلیدی کافی است؛ پیشنهادهای بالا را ببینید.' : '' ),
		);
	}

	/**
	 * Why a page is a poor key page ('' = fine).
	 *
	 * @param int $id Key page id (negative = category archive).
	 */
	public static function guard( int $id ): string {
		if ( $id < 0 ) {
			$t = get_term( -$id );
			if ( $t instanceof \WP_Term && 'product_cat' === $t->taxonomy && (int) get_option( 'default_product_cat' ) === (int) $t->term_id ) {
				return 'دسته پیش‌فرض محصولات است (محصولات بدون دسته)؛ صفحه فروش واقعی نیست.';
			}
			return '';
		}
		if ( (int) get_option( 'page_for_posts' ) === $id ) {
			return 'آرشیو وبلاگ است: خواننده مقاله همین حالا در وبلاگ است و رفتن به فهرست مقاله‌ها او را به خرید یا تماس نزدیک نمی‌کند. این مورد را حذف کنید و صفحه فروش، خدمت یا تماس را بگذارید.';
		}
		if ( (int) get_option( 'page_on_front' ) === $id ) {
			return 'صفحه اصلی است: از منو و لوگو لینک می‌گیرد و لینک مقاله‌ها به آن کمکی به فروش نمی‌کند. صفحه‌های مشخص‌تر را انتخاب کنید.';
		}
		foreach ( array( 'cart' => 'سبد خرید', 'checkout' => 'تسویه حساب', 'myaccount' => 'حساب کاربری', 'terms' => 'قوانین فروشگاه' ) as $wc => $label ) {
			if ( (int) get_option( 'woocommerce_' . $wc . '_page_id' ) === $id ) {
				return 'صفحه سیستمی «' . $label . '» است و نباید از مقاله‌ها لینک بگیرد.';
			}
		}
		if ( (int) get_option( 'wp_page_for_privacy_policy' ) === $id ) {
			return 'صفحه حریم خصوصی است؛ صفحه فروش یا خدمت نیست.';
		}
		if ( 'post' === get_post_type( $id ) ) {
			return 'یک مقاله است. صفحه کلیدی باید صفحه فروش، خدمت یا تماس باشد؛ اگر این مقاله راهنمای اصلی یک موضوع است، آن را ستون یک خوشه محتوایی کنید.';
		}
		return '';
	}

	/**
	 * What a key page's settings will do: how many articles can link to it, and a sample.
	 *
	 * @param array $page Key page entry.
	 */
	public static function impact( array $page ): array {
		$cats  = array_map( 'intval', (array) ( $page['link_categories'] ?? array() ) );
		$count = 0;
		if ( $cats ) {
			foreach ( $cats as $c ) {
				$t      = get_term( $c, 'category' );
				$count += $t instanceof \WP_Term ? (int) $t->count : 0;
			}
		} else {
			$count = (int) wp_count_posts( 'post' )->publish;
		}
		$anchor = (string) ( ( $page['anchors'] ?? array() )[0] ?? '' );
		return array(
			'posts'  => $count,
			'scope'  => $cats ? sprintf( 'از %s دسته', PersianNormalizer::fa_number( count( $cats ) ) ) : 'از همه مقاله‌ها',
			'sample' => '' !== $anchor ? '…اگر به دنبال ' . $anchor . ' هستید، …' : '',
		);
	}

	/**
	 * Likely key pages not chosen yet, each with reasons and filled fields.
	 *
	 * @param int[] $chosen Current key page ids.
	 */
	public static function candidates( array $chosen = array() ): array {
		$skip   = array_flip( array_merge( $chosen, \PaydarWP\PaydarPress\Audit\AuditEngine::system_page_ids() ) );
		$found  = array();
		$reason = static function ( int $id, string $why ) use ( &$found ) {
			$found[ $id ]   = $found[ $id ] ?? array();
			$found[ $id ][] = $why;
		};

		// Pages in the main menu.
		$menu = \PaydarWP\PaydarPress\Scanner\MenuScanner::primary_menu_id();
		if ( null !== $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				$id = (int) $item->object_id;
				if ( 'post_type' === $item->type && 'page' === $item->object && $id > 0 && 'publish' === get_post_status( $id ) ) {
					$reason( $id, 'در منوی اصلی' );
				} elseif ( 'taxonomy' === $item->type && 'product_cat' === $item->object && $id > 0 ) {
					$reason( -$id, 'در منوی اصلی' );
				}
			}
		}

		// Pages whose name says visitors act there.
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids' ) ) as $id ) {
			$text = PersianNormalizer::normalize( rawurldecode( (string) get_post_field( 'post_name', $id ) ) . ' ' . get_the_title( $id ) );
			foreach ( self::ACTION_WORDS as $w ) {
				if ( false !== mb_strpos( $text, $w ) ) {
					$reason( (int) $id, 'صفحه اقدام (' . $w . ')' );
					break;
				}
			}
		}

		// Shops: product categories with the most products.
		if ( taxonomy_exists( 'product_cat' ) ) {
			$default = (int) get_option( 'default_product_cat' );
			$terms   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 6 ) );
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				if ( (int) $t->term_id !== $default && (int) $t->count >= 3 ) {
					$reason( -(int) $t->term_id, sprintf( 'دسته محصول با %s محصول', PersianNormalizer::fa_number( (int) $t->count ) ) );
				}
			}
		}

		// Pages with the most Google impressions (not articles).
		$metrics = \PaydarWP\PaydarPress\Gsc\GscRepository::page_metrics();
		uasort( $metrics, static fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );
		$n = 0;
		foreach ( $metrics as $id => $m ) {
			if ( 'page' === get_post_type( (int) $id ) && $m['impressions'] >= 100 ) {
				$reason( (int) $id, sprintf( '%s نمایش در گوگل', PersianNormalizer::fa_number( (int) $m['impressions'] ) ) );
				if ( ++$n >= 3 ) {
					break;
				}
			}
		}

		$out = array();
		foreach ( $found as $id => $reasons ) {
			if ( isset( $skip[ $id ] ) || '' !== self::guard( (int) $id ) || ! KeyPageLinks::is_live( (int) $id ) ) {
				continue;
			}
			$out[] = self::fill( (int) $id, array_values( array_unique( $reasons ) ) );
		}
		usort( $out, static fn( $a, $b ) => array( count( $b['reasons'] ), $b['score'] ) <=> array( count( $a['reasons'] ), $a['score'] ) );
		return array_slice( $out, 0, 8 );
	}

	/**
	 * A suggestion with every field filled.
	 *
	 * @param int      $id      Key page id.
	 * @param string[] $reasons Why.
	 */
	public static function fill( int $id, array $reasons ): array {
		$title = KeyPageLinks::title_of( $id );
		$type  = $id < 0 ? 'category' : ProfileRepository::guess_page_type( get_post( $id ) );

		// Main keyword: the page's strongest probable query, else its title.
		$keyword = $title;
		$score   = 0;
		if ( $id > 0 ) {
			$q = \PaydarWP\PaydarPress\Tasks\TaskDetails::probable( $id );
			if ( $q ) {
				$keyword = $q[0]['query'];
				$score   = $q[0]['impressions'];
			}
		} else {
			$t     = get_term( -$id );
			$score = $t instanceof \WP_Term ? (int) $t->count : 0;
		}

		// Contact / about pages: linked from every article; others from matching categories.
		$cats = array();
		if ( ! in_array( $type, array( 'contact', 'about' ), true ) ) {
			$words = array_filter( explode( ' ', PersianNormalizer::for_key( $title . ' ' . $keyword ) ), static fn( $w ) => mb_strlen( $w ) > 2 && ! in_array( $w, self::STOP, true ) );
			foreach ( (array) get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 200 ) ) as $c ) {
				if ( ! $c instanceof \WP_Term || (int) get_option( 'default_category' ) === (int) $c->term_id ) {
					continue;
				}
				$name = PersianNormalizer::for_key( html_entity_decode( $c->name, ENT_QUOTES, 'UTF-8' ) );
				foreach ( $words as $w ) {
					if ( false !== mb_strpos( ' ' . $name . ' ', ' ' . $w . ' ' ) ) {
						$cats[] = (int) $c->term_id;
						break;
					}
				}
			}
		}

		$anchors = array_map( static fn( $a ) => $a['anchor'], KeyPageLinks::suggest( $id ) );
		if ( $keyword !== $title && ! in_array( $keyword, $anchors, true ) ) {
			array_splice( $anchors, 1, 0, array( $keyword ) );
		}

		return array(
			'id'              => $id,
			'name'            => $title,
			'url'             => KeyPageLinks::url_of( $id ),
			'kind'            => $id < 0 ? 'term' : 'post',
			'page_type'       => $type,
			'main_keyword'    => $keyword,
			'link_categories' => array_slice( array_values( array_unique( $cats ) ), 0, 6 ),
			'anchors'         => array_slice( $anchors, 0, 5 ),
			'priority'        => count( $reasons ) > 1 ? 'high' : 'normal',
			'reasons'         => $reasons,
			'detected_by'     => 'suggested',
			'score'           => $score,
		);
	}
}
