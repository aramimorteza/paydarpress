<?php
/**
 * Finds an anchor phrase in post HTML and wraps its first linkable occurrence in a link.
 * WordPress-independent.
 *
 * Only text nodes are searched (never tag attributes, HTML comments or Gutenberg block
 * comments), and never inside existing links, headings, code, buttons, captions or
 * shortcodes. Matching tolerates Persian/Arabic letter variants (ي/ی, ك/ک, ة/ه…), Persian
 * and Latin digits, and space versus half-space, and respects word boundaries so a link
 * is never placed on part of a word.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Links;

use PaydarWP\PaydarPress\Text\PersianNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link inserter.
 */
final class LinkInserter {

	/** Elements whose text must not receive a link. */
	private const FORBIDDEN = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'button', 'label', 'select', 'option', 'textarea', 'figcaption', 'iframe', 'svg', 'title', 'kbd', 'cite' );

	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	private const VARIANTS = array(
		'ی' => '[یيىئ]',
		'ک' => '[کك]',
		'ه' => '[هةۀ]',
		'ا' => '[اأإٱ]',
		'و' => '[وؤ]',
	);

	/**
	 * Finds the first linkable occurrence of a phrase.
	 *
	 * @param string $html   Post HTML.
	 * @param string $phrase Anchor phrase.
	 * @return array{offset:int, length:int, text:string, node:string, node_offset:int}|null Byte offsets in $html.
	 */
	public static function locate( string $html, string $phrase ): ?array {
		$pattern = self::pattern( $phrase );
		if ( null === $pattern ) {
			return null;
		}

		$parts = preg_split( '/(<!--.*?-->|<[^>]*>)/su', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE );
		if ( false === $parts ) {
			return null;
		}

		$depth = 0;
		$open  = array();

		foreach ( $parts as list( $part, $offset ) ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( str_starts_with( $part, '<!--' ) || ! preg_match( '#^<\s*(/)?\s*([a-zA-Z][a-zA-Z0-9-]*)#', $part, $tag ) ) {
					continue;
				}
				$name = strtolower( $tag[2] );
				if ( ! in_array( $name, self::FORBIDDEN, true ) || in_array( $name, self::VOID, true ) || str_ends_with( rtrim( $part, " \t\n>" ), '/' ) ) {
					continue;
				}
				if ( '/' === $tag[1] ) {
					if ( ! empty( $open[ $name ] ) ) {
						--$open[ $name ];
						--$depth;
					}
				} else {
					$open[ $name ] = ( $open[ $name ] ?? 0 ) + 1;
					++$depth;
				}
				continue;
			}

			if ( $depth > 0 ) {
				continue;
			}

			// Shortcodes are masked with same-length filler so their text never matches.
			$searchable = (string) preg_replace_callback( '/\[[^\[\]]*\]/u', static fn( $m ) => str_repeat( "\x01", strlen( $m[0] ) ), $part );

			if ( preg_match( $pattern, $searchable, $m, PREG_OFFSET_CAPTURE ) ) {
				return array(
					'offset'      => $offset + $m[0][1],
					'length'      => strlen( $m[0][0] ),
					'text'        => substr( $part, $m[0][1], strlen( $m[0][0] ) ),
					'node'        => $part,
					'node_offset' => $m[0][1],
				);
			}
		}

		return null;
	}

	/**
	 * Inserts a link around the first linkable occurrence of the phrase.
	 *
	 * @param string $html   Post HTML.
	 * @param string $phrase Anchor phrase.
	 * @param string $url    Escaped URL.
	 * @return array{html:string, inserted:string, text:string}|null Null when the phrase is not found.
	 */
	public static function insert( string $html, string $phrase, string $url ): ?array {
		$found = self::locate( $html, $phrase );
		if ( null === $found ) {
			return null;
		}

		$inserted = '<a href="' . $url . '">' . $found['text'] . '</a>';

		return array(
			'html'     => substr_replace( $html, $inserted, $found['offset'], $found['length'] ),
			'inserted' => $inserted,
			'text'     => $found['text'],
		);
	}

	/**
	 * Removes a link that PaydarPress inserted (exact markup), keeping its text.
	 *
	 * @param string $html     Post HTML.
	 * @param string $inserted Inserted markup.
	 * @param string $text     Original text.
	 * @return string|null New HTML, or null when the link is no longer there (edited by hand).
	 */
	public static function remove( string $html, string $inserted, string $text ): ?string {
		$pos = strpos( $html, $inserted );
		if ( false === $pos ) {
			return null;
		}

		return substr_replace( $html, $text, $pos, strlen( $inserted ) );
	}

	/**
	 * Text around the first linkable occurrence (for the review screen).
	 *
	 * @param string $html   Post HTML.
	 * @param string $phrase Anchor phrase.
	 * @param int    $radius Characters on each side.
	 * @return array{before:string, match:string, after:string}|null
	 */
	public static function context( string $html, string $phrase, int $radius = 90 ): ?array {
		$found = self::locate( $html, $phrase );
		if ( null === $found ) {
			return null;
		}

		$before = self::plain( substr( $html, 0, $found['offset'] ) );
		$after  = self::plain( substr( $html, $found['offset'] + $found['length'] ) );

		return array(
			'before' => ( mb_strlen( $before ) > $radius ? '…' : '' ) . mb_substr( $before, -$radius ),
			'match'  => html_entity_decode( $found['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'after'  => mb_substr( $after, 0, $radius ) . ( mb_strlen( $after ) > $radius ? '…' : '' ),
		);
	}

	/**
	 * Regex for a phrase, tolerant to letter variants, digits and spacing, with word boundaries.
	 *
	 * @param string $phrase Phrase.
	 */
	public static function pattern( string $phrase ): ?string {
		$phrase = trim( (string) preg_replace( '/\s+/u', ' ', PersianNormalizer::normalize( strip_tags( $phrase ) ) ) );
		if ( mb_strlen( $phrase ) < 2 ) {
			return null;
		}

		$space  = '(?:\s|&nbsp;|\x{00A0}|\x{200C})+';
		$joiner = '(?:\s|&nbsp;|\x{00A0}|\x{200C})?';
		$digits = array( '0' => '[0۰٠]', '1' => '[1۱١]', '2' => '[2۲٢]', '3' => '[3۳٣]', '4' => '[4۴٤]', '5' => '[5۵٥]', '6' => '[6۶٦]', '7' => '[7۷٧]', '8' => '[8۸٨]', '9' => '[9۹٩]' );

		$out = '';
		foreach ( preg_split( '//u', $phrase, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			if ( ' ' === $char ) {
				$out .= $space;
			} elseif ( "\u{200C}" === $char ) {
				$out .= $joiner;
			} elseif ( isset( self::VARIANTS[ $char ] ) ) {
				$out .= self::VARIANTS[ $char ];
			} elseif ( isset( $digits[ $char ] ) ) {
				$out .= $digits[ $char ];
			} else {
				$out .= preg_quote( $char, '/' );
			}
		}

		// Optional diacritics between letters are not supported; they are rare in body text.
		return '/(?<![\p{L}\p{M}\p{N}\x{200C}])' . $out . '(?![\p{L}\p{M}\p{N}\x{200C}])/iu';
	}

	/**
	 * Plain text of an HTML fragment.
	 *
	 * @param string $html HTML.
	 */
	private static function plain( string $html ): string {
		$text = (string) preg_replace( '/<!--.*?-->|<[^>]*>|\[[^\[\]]*\]/su', ' ', $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
