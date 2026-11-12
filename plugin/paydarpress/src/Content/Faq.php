<?php
/**
 * Frequently asked questions inside the article: core "Details" blocks (an accordion that
 * needs no SEO plugin) plus FAQPage structured data printed by PaydarPress.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Content;


defined( 'ABSPATH' ) || exit;

/**
 * The FAQ section is wrapped in a Group block with the class `pp-faq`; structured data is
 * printed only while that section is still in the content.
 */
final class Faq {

	public const CSS_CLASS = 'pp-faq';

	/** Headings that mean the HTML already has its own FAQ section. */
	private const HEADING = '#<h[2-4][^>]*>[^<]*(پرسش|سوال|سؤال)[\s\x{200C}]*(ها|های)?[^<]*متداول#iu';

	/**
	 * Hooks for public pages (one cached meta read on single posts only).
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'schema' ), 30 );
	}

	/**
	 * Whether the HTML already contains a FAQ section.
	 *
	 * @param string $html HTML.
	 */
	public static function has_section( string $html ): bool {
		return false !== strpos( $html, self::CSS_CLASS ) || 1 === preg_match( self::HEADING, $html );
	}

	/**
	 * Block markup for the FAQ section.
	 *
	 * @param array  $faq   Items with q, a.
	 * @param string $title Section heading.
	 */
	public static function blocks( array $faq, string $title = 'پرسش‌های متداول' ): string {
		$items = '';
		foreach ( $faq as $f ) {
			$q = trim( (string) ( $f['q'] ?? '' ) );
			$a = trim( (string) ( $f['a'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$paras = '';
			foreach ( preg_split( "/\n\s*\n/u", $a ) as $p ) {
				$paras .= "<!-- wp:paragraph -->\n<p>" . nl2br( esc_html( trim( $p ) ), false ) . "</p>\n<!-- /wp:paragraph -->\n";
			}
			$items .= "<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>" . esc_html( $q ) . "</summary>{$paras}</details>\n<!-- /wp:details -->\n\n";
		}
		if ( '' === $items ) {
			return '';
		}
		return "\n\n<!-- wp:group {\"className\":\"" . self::CSS_CLASS . "\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group " . self::CSS_CLASS . "\">"
			. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $title ) . "</h2>\n<!-- /wp:heading -->\n\n"
			. $items
			. "</div>\n<!-- /wp:group -->";
	}

	/**
	 * Questions and answers of the FAQ section in the content.
	 *
	 * @param string $content Post content.
	 * @return array<int, array{q: string, a: string}>
	 */
	public static function parse( string $content ): array {
		$start = strpos( $content, self::CSS_CLASS );
		if ( false === $start ) {
			return array();
		}
		$out = array();
		if ( preg_match_all( '#<details\b[^>]*>\s*<summary[^>]*>(.*?)</summary>(.*?)</details>#isu', substr( $content, $start ), $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $item ) {
				$q = trim( html_entity_decode( wp_strip_all_tags( $item[1] ), ENT_QUOTES, 'UTF-8' ) );
				$a = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', '', $item[2] ) ), ENT_QUOTES, 'UTF-8' ) ) );
				if ( '' !== $q && '' !== $a ) {
					$out[] = array( 'q' => $q, 'a' => $a );
				}
			}
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * FAQPage JSON-LD on single views of content that still has the FAQ section.
	 */
	public function schema(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || false === strpos( (string) $post->post_content, self::CSS_CLASS ) ) {
			return;
		}
		// Read the questions from the content itself, so edits in the editor are reflected.
		$entities = array();
		foreach ( self::parse( (string) $post->post_content ) as $f ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $f['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $f['a'],
				),
			);
		}
		if ( $entities ) {
			echo "\n<script type=\"application/ld+json\">" . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
		}
	}
}
