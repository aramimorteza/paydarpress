<?php
/**
 * Checks crawler access via robots.txt and the WordPress visibility setting.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Crawl;

/**
 * Spec section 10. Results are stored in the `paydarpress_crawlers` option.
 */
final class CrawlerCheck {

	public const OPTION = 'paydarpress_crawlers';

	/**
	 * Crawlers checked, with a short Persian description of what blocking them means.
	 */
	public const BOTS = array(
		'Googlebot'       => 'جستجوی گوگل؛ مسدود بودن یعنی حذف از نتایج گوگل.',
		'Bingbot'         => 'جستجوی بینگ؛ برخی دستیارهای هوش مصنوعی از نتایج بینگ استفاده می‌کنند.',
		'OAI-SearchBot'   => 'جستجوی ChatGPT؛ برای نمایش سایت به‌عنوان منبع در پاسخ‌های ChatGPT.',
		'ChatGPT-User'    => 'وقتی کاربر ChatGPT مستقیماً صفحه‌ای از سایت را باز می‌کند.',
		'GPTBot'          => 'آموزش مدل‌های OpenAI؛ مسدود کردن آن روی جستجوی ChatGPT اثری ندارد.',
		'ClaudeBot'       => 'خزنده Anthropic (Claude).',
		'PerplexityBot'   => 'جستجوی Perplexity.',
		'Google-Extended' => 'استفاده Gemini از محتوا؛ روی رتبه در جستجوی گوگل اثری ندارد.',
	);

	/**
	 * Runs the check and stores the result.
	 *
	 * @return array{checked_at:string, source:string, site_public:bool, bots:array<string,string>}
	 */
	public static function run(): array {
		$source  = 'http';
		$content = '';

		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'sslverify'   => false,
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$content = (string) wp_remote_retrieve_body( $response );
		} else {
			// Fallback: the virtual robots.txt WordPress would serve (a physical file may differ).
			$source = 'wordpress';
			ob_start();
			do_action( 'do_robots' );
			$content = (string) ob_get_clean();
		}

		$parser = new RobotsParser( $content );
		$bots   = array();
		foreach ( array_keys( self::BOTS ) as $bot ) {
			$bots[ $bot ] = $parser->state( $bot );
		}

		$result = array(
			'checked_at'  => gmdate( 'c' ),
			'source'      => $source,
			'site_public' => '0' !== (string) get_option( 'blog_public', '1' ),
			'bots'        => $bots,
		);

		update_option( self::OPTION, $result, false );

		return $result;
	}

	/**
	 * Stored result, or null if never checked.
	 */
	public static function last(): ?array {
		$value = get_option( self::OPTION, null );

		return is_array( $value ) ? $value : null;
	}
}
