<?php
/**
 * Extracts structure from post HTML (Gutenberg or classic editor).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Extractors;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Parses HTML with DOMDocument. Independent of WordPress so it can be unit tested.
 */
final class HtmlExtractor {

	private const BLOCK_TAGS = 'p|div|h[1-6]|li|ul|ol|table|tr|td|th|blockquote|section|article|figure|figcaption|pre|br|hr';

	/**
	 * Extracts text, headings, links, images and intro paragraphs.
	 *
	 * @param string $html Raw post content (may contain block comments and shortcodes).
	 */
	public function extract( string $html ): Extracted {
		$result = new Extracted();

		$html = $this->clean( $html );
		if ( '' === trim( $html ) ) {
			return $result;
		}

		$dom = $this->load( $html );
		if ( null === $dom ) {
			$result->text = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/<[^>]+>/', ' ', $html ) ) );
			return $result;
		}

		$xpath = new DOMXPath( $dom );

		// Remove non-content nodes.
		foreach ( $xpath->query( '//script|//style|//noscript|//template' ) as $node ) {
			$node->parentNode->removeChild( $node );
		}

		$seen_h2 = false;

		foreach ( $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6|//p|//a|//img' ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $node->tagName );

			if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
				$text = $this->text_of( $node );
				if ( '' !== $text ) {
					$result->headings[] = array(
						'level' => (int) $m[1],
						'text'  => $text,
					);
				}
				if ( '2' === $m[1] ) {
					$seen_h2 = true;
				}
				continue;
			}

			if ( 'p' === $tag ) {
				if ( ! $seen_h2 ) {
					$text = $this->text_of( $node );
					if ( '' !== $text ) {
						$result->intro_paragraphs[] = $text;
					}
				}
				continue;
			}

			if ( 'a' === $tag ) {
				$href = trim( $node->getAttribute( 'href' ) );
				if ( '' === $href || $this->is_non_link( $href ) ) {
					continue;
				}
				$result->links[] = array(
					'url'    => $href,
					'anchor' => mb_substr( $this->text_of( $node ), 0, 255 ),
				);
				continue;
			}

			if ( 'img' === $tag ) {
				$src = trim( $node->getAttribute( 'src' ) );
				if ( '' === $src ) {
					$src = trim( $node->getAttribute( 'data-src' ) );
				}
				if ( '' === $src ) {
					continue;
				}
				$attachment_id = null;
				if ( preg_match( '/\bwp-image-(\d+)\b/', $node->getAttribute( 'class' ), $cm ) ) {
					$attachment_id = (int) $cm[1];
				} elseif ( '' !== $node->getAttribute( 'data-id' ) && ctype_digit( $node->getAttribute( 'data-id' ) ) ) {
					$attachment_id = (int) $node->getAttribute( 'data-id' );
				}
				$result->images[] = array(
					'src'           => $src,
					'alt'           => $node->hasAttribute( 'alt' ) ? trim( $node->getAttribute( 'alt' ) ) : null,
					'attachment_id' => $attachment_id,
				);
			}
		}

		$body         = $dom->getElementsByTagName( 'body' )->item( 0 );
		$result->text = null !== $body ? $this->text_of( $body ) : '';

		return $result;
	}

	/**
	 * Removes block comments and shortcode brackets (keeping enclosed text) and adds line breaks
	 * after block-level tags so text from adjacent blocks does not merge.
	 *
	 * @param string $html Raw content.
	 */
	private function clean( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = (string) preg_replace( '/\[\/?[a-zA-Z0-9_\-]+(?:\s[^\]]*)?\]/u', ' ', $html );
		$html = (string) preg_replace( '#(</(?:' . self::BLOCK_TAGS . ')>|<(?:br|hr)\s*/?>)#i', "$1\n", $html );

		return $html;
	}

	/**
	 * Loads HTML into a DOMDocument with UTF-8 handling.
	 *
	 * @param string $html Cleaned HTML.
	 */
	private function load( string $html ): ?DOMDocument {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$ok       = $dom->loadHTML( '<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $ok ? $dom : null;
	}

	/**
	 * Normalized whitespace text of a node.
	 *
	 * @param DOMNode $node Node.
	 */
	private function text_of( DOMNode $node ): string {
		return trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', (string) preg_replace( '/\s*\n\s*/u', "\n", $node->textContent ) ) );
	}

	/**
	 * Links that are not navigation targets.
	 *
	 * @param string $href Link href.
	 */
	private function is_non_link( string $href ): bool {
		return (bool) preg_match( '/^(#|mailto:|tel:|javascript:|sms:|whatsapp:|data:)/i', $href );
	}
}

