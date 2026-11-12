<?php
/**
 * Site profile field definitions.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors the SITE PROFILE block of ContentMaster (docs/contentmaster.md section 5) so the
 * export maps one-to-one. Spec section 9.
 */
final class Fields {

	public const INDUSTRY_MODULES = array(
		'legal'                 => 'حقوقی و وکالت',
		'product-manufacturing' => 'تولید و فروش محصول',
		'service-directory'     => 'خدمات و دایرکتوری',
		'language-learning'     => 'آموزش زبان',
		'custom'                => 'سایر (سفارشی)',
	);

	public const KNOWLEDGE_LEVELS = array(
		'beginner' => 'مبتدی',
		'mixed'    => 'متنوع',
		'expert'   => 'حرفه‌ای',
	);

	public const SENSITIVITY = array(
		'normal' => 'عادی',
		'YMYL'   => 'حساس (حقوقی، پزشکی، مالی)',
	);

	/**
	 * Field definitions: group, label, type, whether it counts toward completion, and help text.
	 *
	 * Types: text, textarea, select, bool, key_pages, cta_options, categories.
	 *
	 * @return array<string, array{group:string, label:string, type:string, required:bool, help:string, options?:array}>
	 */
	public static function all(): array {
		return array(
			// Identity (mostly detected).
			'site_name'                => array( 'group' => 'identity', 'label' => 'نام سایت', 'type' => 'text', 'required' => false, 'help' => 'از تنظیمات وردپرس خوانده می‌شود.' ),
			'domain'                   => array( 'group' => 'identity', 'label' => 'دامنه', 'type' => 'text', 'required' => false, 'help' => '' ),
			'language'                 => array( 'group' => 'identity', 'label' => 'زبان سایت', 'type' => 'text', 'required' => false, 'help' => '' ),
			'seo_plugin'               => array( 'group' => 'identity', 'label' => 'افزونه سئو', 'type' => 'text', 'required' => false, 'help' => '' ),
			'page_builder'             => array( 'group' => 'identity', 'label' => 'صفحه‌ساز', 'type' => 'text', 'required' => false, 'help' => '' ),
			'woocommerce'              => array( 'group' => 'identity', 'label' => 'فروشگاه ووکامرس', 'type' => 'bool', 'required' => false, 'help' => '' ),
			'industry_module'          => array( 'group' => 'business', 'label' => 'حوزه کسب‌وکار', 'type' => 'select', 'required' => true, 'help' => 'قوانین تولید محتوا بر اساس این حوزه تنظیم می‌شوند.', 'options' => self::INDUSTRY_MODULES ),
			'business_offer'           => array( 'group' => 'business', 'label' => 'چه چیزی ارائه می‌دهید؟', 'type' => 'textarea', 'required' => true, 'help' => 'مثال: خدمات وکالت در دعاوی خانواده، کیفری و ملکی در کرج.' ),
			'geographic_scope'         => array( 'group' => 'business', 'label' => 'محدوده جغرافیایی', 'type' => 'text', 'required' => true, 'help' => 'مثال: کرج، گوهردشت و عظیمیه — یا «سراسر ایران».' ),
			'primary_content_goal'     => array( 'group' => 'business', 'label' => 'هدف اصلی محتوا', 'type' => 'text', 'required' => true, 'help' => 'مثال: رزرو مشاوره، تماس تلفنی، فروش محصول، ثبت‌نام.' ),
			// Audience.
			'primary_audience'         => array( 'group' => 'audience', 'label' => 'مخاطب اصلی', 'type' => 'textarea', 'required' => true, 'help' => 'مثال: زنان و مردانی که درگیر طلاق یا مهریه هستند و می‌خواهند حقوقشان را بدانند.' ),
			'audience_problems'        => array( 'group' => 'audience', 'label' => 'مسائل و سؤال‌های رایج مخاطب', 'type' => 'textarea', 'required' => false, 'help' => 'مثال: هزینه وکیل چقدر است؟ طلاق توافقی چقدر طول می‌کشد؟' ),
			'knowledge_level'          => array( 'group' => 'audience', 'label' => 'سطح دانش مخاطب', 'type' => 'select', 'required' => false, 'help' => '', 'options' => self::KNOWLEDGE_LEVELS ),
			'tone_of_voice'            => array( 'group' => 'audience', 'label' => 'لحن محتوا', 'type' => 'text', 'required' => true, 'help' => 'مثال: رسمی و قابل اعتماد، اما ساده و قابل فهم برای عموم.' ),
			// Structure.
			'key_pages'                => array( 'group' => 'structure', 'label' => 'صفحات کلیدی', 'type' => 'key_pages', 'required' => true, 'help' => 'صفحاتی که مقالات باید به آن‌ها لینک بدهند (خدمات، تماس، مشاوره…).' ),
			'cta_options'              => array( 'group' => 'structure', 'label' => 'دعوت به اقدام (CTA)', 'type' => 'cta_options', 'required' => true, 'help' => 'قدم بعدی خواننده پس از مقاله.' ),
			'categories_scope'         => array( 'group' => 'structure', 'label' => 'محدوده موضوعی دسته‌ها', 'type' => 'categories', 'required' => true, 'help' => 'هر دسته چه موضوعاتی را پوشش می‌دهد.' ),
			// SEO.
			'primary_keyword_patterns' => array( 'group' => 'seo', 'label' => 'الگوهای جستجوی رایج', 'type' => 'textarea', 'required' => false, 'help' => 'مثال: «هزینه [X] در کرج»، «وکیل [X] کرج»، «[X] چیست».' ),
			'approved_tags'            => array( 'group' => 'seo', 'label' => 'برچسب‌های مجاز', 'type' => 'textarea', 'required' => false, 'help' => 'اگر خالی بماند، از دسته‌ها و برچسب‌های موجود سایت استفاده می‌شود.' ),
			// Rules.
			'fact_sensitivity'         => array( 'group' => 'rules', 'label' => 'حساسیت محتوا', 'type' => 'select', 'required' => true, 'help' => 'محتوای حقوقی، پزشکی و مالی حساس است و قوانین سخت‌گیرانه‌تری دارد.', 'options' => self::SENSITIVITY ),
			'forbidden_topics'         => array( 'group' => 'rules', 'label' => 'موضوعات ممنوع', 'type' => 'textarea', 'required' => true, 'help' => 'مثال: خدماتی که ارائه نمی‌دهیم، تضمین نتیجه پرونده. اگر ندارید بنویسید «ندارد».' ),
			'industry_rules'           => array( 'group' => 'rules', 'label' => 'قوانین اختصاصی حوزه', 'type' => 'textarea', 'required' => false, 'help' => 'مثال: هیچ‌وقت مبلغ دقیق دستمزد ذکر نشود.' ),
			'brand_glossary'           => array( 'group' => 'rules', 'label' => 'واژه‌نامه برند', 'type' => 'textarea', 'required' => false, 'help' => 'اصطلاحات ترجیحی و ممنوع. مثال: «وکیل پایه یک دادگستری» به‌جای «وکیل».' ),
			'competitors'              => array( 'group' => 'rules', 'label' => 'رقبا و سایت‌های مرجع', 'type' => 'textarea', 'required' => false, 'help' => '' ),
		);
	}

	/**
	 * Group labels in display order.
	 */
	public static function groups(): array {
		return array(
			'identity'  => 'اطلاعات سایت',
			'business'  => 'کسب‌وکار',
			'audience'  => 'مخاطب و لحن',
			'structure' => 'ساختار سایت',
			'seo'       => 'سئو',
			'rules'     => 'قوانین محتوا',
		);
	}
}
