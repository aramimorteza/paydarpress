<?php
/**
 * Finds images without a useful alt text and the context each one sits in (nearest heading,
 * the text right before and after it, its caption), so the alt can be written for that image
 * in that place, and writes approved alt texts back.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Images;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Audit\AltQuality;
use WP_HTML_Tag_Processor;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Occurrence ref format: "p{post_id}-i{n}" (n-th <img> of the content, 1-based) or "p{post_id}-f"
 * (featured image). The same file used in two articles gets two alt texts, one per context.
 */
final class AltContext {

	/** Images per request (keeps the prompt and the reply small). */
	public const MAX_IMAGES = 25;

	/** Characters of context before / after an image. */
	private const CONTEXT_CHARS = 260;

	/**
	 * Images that need an alt.
	 *
	 * @param int $post_id One post, or 0 for the posts with the most missing alts.
	 * @param int $limit   Maximum images.
	 * @return array[] Occurrences.
	 */
	public static function occurrences( int $post_id = 0, int $limit = self::MAX_IMAGES ): array {
		$ids = $post_id > 0 ? array( $post_id ) : self::posts_needing_alt( 40 );
		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			foreach ( self::for_post( $post ) as $occurrence ) {
				$out[] = $occurrence;
				if ( count( $out ) >= $limit ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * Images of one post that need an alt, with their context.
	 *
	 * @param WP_Post $post Post.
	 * @return array[]
	 */
	public static function for_post( WP_Post $post ): array {
		$out   = array();
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );

		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb > 0 ) {
			$alt = trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) );
			$src = (string) wp_get_attachment_url( $thumb );
			if ( '' === $alt || AltQuality::is_generic( $alt, $src ) ) {
				$out[] = array(
					'ref'           => 'p' . $post->ID . '-f',
					'post_id'       => $post->ID,
					'post_title'    => $title,
					'kind'          => 'featured',
					'src'           => $src,
					'file'          => wp_basename( $src ),
					'attachment_id' => $thumb,
					'current_alt'   => $alt,
					'heading'       => '',
					'before'        => '',
					'after'         => self::plain_start( (string) $post->post_content ),
					'caption'       => '',
				);
			}
		}

		foreach ( self::parse( (string) $post->post_content ) as $n => $img ) {
			$alt = $img['alt'];
			if ( null === $alt && $img['attachment_id'] > 0 ) {
				$alt = trim( (string) get_post_meta( $img['attachment_id'], '_wp_attachment_image_alt', true ) );
			}
			if ( null !== $alt && '' !== $alt && ! AltQuality::is_generic( $alt, $img['src'] ) ) {
				continue;
			}
			$out[] = array(
				'ref'           => 'p' . $post->ID . '-i' . ( $n + 1 ),
				'post_id'       => $post->ID,
				'post_title'    => $title,
				'kind'          => 'inline',
				'src'           => $img['src'],
				'file'          => wp_basename( (string) wp_parse_url( $img['src'], PHP_URL_PATH ) ),
				'attachment_id' => $img['attachment_id'],
				'current_alt'   => (string) $alt,
				'heading'       => $img['heading'],
				'before'        => $img['before'],
				'after'         => $img['after'],
				'caption'       => $img['caption'],
			);
		}
		return $out;
	}

	/**
	 * Every <img> of some HTML in document order with its surroundings.
	 *
	 * @param string $html HTML (block markup is fine; comments are ignored).
	 * @return array[] src, alt (null when the attribute is absent), attachment_id, heading, before, after, caption.
	 */
	public static function parse( string $html ): array {
		if ( false === stripos( $html, '<img' ) ) {
			return array();
		}
		$doc = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"?><div id="pp-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );

		$xpath = new DOMXPath( $doc );
		$nodes = $xpath->query( '//h1|//h2|//h3|//h4|//p|//li|//blockquote|//td|//img' );
		if ( false === $nodes ) {
			return array();
		}

		// Linear sequence: headings, text blocks and images.
		$seq = array();
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $node->nodeName );
			if ( 'img' === $tag ) {
				$seq[] = array( 'img', $node );
				continue;
			}
			$text = self::squash( $node->textContent );
			if ( '' === $text ) {
				continue;
			}
			$seq[] = array( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4' ), true ) ? 'h' : 't', $text );
		}

		$images  = array();
		$heading = '';
		$last    = '';
		$count   = count( $seq );
		for ( $i = 0; $i < $count; $i++ ) {
			list( $type, $value ) = $seq[ $i ];
			if ( 'h' === $type ) {
				$heading = $value;
				$last    = '';
				continue;
			}
			if ( 't' === $type ) {
				$last = $value;
				continue;
			}
			$after = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( 't' === $seq[ $j ][0] ) {
					$after = $seq[ $j ][1];
					break;
				}
				if ( 'h' === $seq[ $j ][0] ) {
					break;
				}
			}
			/** @var DOMElement $value */
			$class    = (string) $value->getAttribute( 'class' );
			$att      = preg_match( '/wp-image-(\d+)/', $class, $m ) ? (int) $m[1] : 0;
			$images[] = array(
				'src'           => (string) $value->getAttribute( 'src' ),
				'alt'           => $value->hasAttribute( 'alt' ) ? trim( (string) $value->getAttribute( 'alt' ) ) : null,
				'attachment_id' => $att,
				'heading'       => mb_substr( $heading, 0, 160 ),
				'before'        => '' === $last ? '' : mb_substr( $last, max( 0, mb_strlen( $last ) - self::CONTEXT_CHARS ) ),
				'after'         => mb_substr( $after, 0, self::CONTEXT_CHARS ),
				'caption'       => self::caption( $value ),
			);
		}
		return $images;
	}

	/**
	 * Sets the alt of one occurrence: the n-th <img> in the content (only when its alt is
	 * missing or generic), and the attachment's alt when that is empty or generic.
	 *
	 * @param string $ref Occurrence ref.
	 * @param string $alt New alt text.
	 * @return array{ok: bool, message: string, post_id: int}
	 */
	public static function apply( string $ref, string $alt ): array {
		$alt = self::clean_alt( $alt );
		if ( ! preg_match( '/^p(\d+)-(f|i(\d+))$/', $ref, $m ) ) {
			return array( 'ok' => false, 'message' => 'شناسه تصویر نامعتبر است.', 'post_id' => 0 );
		}
		$post_id = (int) $m[1];
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || '' === $alt ) {
			return array( 'ok' => false, 'message' => 'نوشته یا متن جایگزین پیدا نشد.', 'post_id' => $post_id );
		}

		if ( 'f' === $m[2] ) {
			$thumb = (int) get_post_thumbnail_id( $post );
			if ( $thumb <= 0 ) {
				return array( 'ok' => false, 'message' => 'تصویر شاخص دیگر وجود ندارد.', 'post_id' => $post_id );
			}
			$current = trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) );
			if ( '' !== $current && ! AltQuality::is_generic( $current, (string) wp_get_attachment_url( $thumb ) ) ) {
				return array( 'ok' => false, 'message' => 'تصویر شاخص در این فاصله متن جایگزین گرفته است.', 'post_id' => $post_id );
			}
			update_post_meta( $thumb, '_wp_attachment_image_alt', $alt );
			return array( 'ok' => true, 'message' => '', 'post_id' => $post_id );
		}

		$target = (int) $m[3];
		$tags   = new WP_HTML_Tag_Processor( (string) $post->post_content );
		$n      = 0;
		$done   = false;
		$att    = 0;
		while ( $tags->next_tag( 'img' ) ) {
			++$n;
			if ( $n !== $target ) {
				continue;
			}
			$current = $tags->get_attribute( 'alt' );
			$src     = (string) $tags->get_attribute( 'src' );
			if ( is_string( $current ) && '' !== trim( $current ) && ! AltQuality::is_generic( trim( $current ), $src ) ) {
				return array( 'ok' => false, 'message' => 'این تصویر در این فاصله متن جایگزین گرفته است.', 'post_id' => $post_id );
			}
			$tags->set_attribute( 'alt', $alt );
			$att  = preg_match( '/wp-image-(\d+)/', (string) $tags->get_attribute( 'class' ), $c ) ? (int) $c[1] : 0;
			$done = true;
			break;
		}
		if ( ! $done ) {
			return array( 'ok' => false, 'message' => 'تصویر در متن نوشته پیدا نشد (احتمالاً نوشته ویرایش شده است).', 'post_id' => $post_id );
		}

		$kses = ! current_user_can( 'unfiltered_html' );
		if ( $kses ) {
			kses_remove_filters();
		}
		// wp_update_post keeps a revision, so the change can be undone from WordPress.
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $tags->get_updated_html(),
				)
			),
			true
		);
		if ( $kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $updated ) ) {
			return array( 'ok' => false, 'message' => 'ذخیره نوشته ناموفق بود: ' . $updated->get_error_message(), 'post_id' => $post_id );
		}

		if ( $att > 0 ) {
			$current = trim( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) );
			if ( '' === $current || AltQuality::is_generic( $current, (string) wp_get_attachment_url( $att ) ) ) {
				update_post_meta( $att, '_wp_attachment_image_alt', $alt );
			}
		}
		return array( 'ok' => true, 'message' => '', 'post_id' => $post_id );
	}

	/**
	 * Quality checks for a proposed alt text.
	 *
	 * @param string   $alt     Alt.
	 * @param string   $file    File name.
	 * @param string   $keyword Focus keyword of the article.
	 * @return array[] [level, text].
	 */
	public static function checks( string $alt, string $file = '', string $keyword = '' ): array {
		$checks = array();
		$len    = mb_strlen( $alt );
		if ( $len < 10 ) {
			$checks[] = array( 'warning', 'خیلی کوتاه است؛ بگویید تصویر دقیقاً چه چیزی را در این بخش نشان می‌دهد.' );
		} elseif ( $len > 125 ) {
			$checks[] = array( 'warning', 'بیش از ۱۲۵ نویسه است؛ صفحه‌خوان‌ها معمولاً بقیه را نمی‌خوانند.' );
		}
		if ( preg_match( '/^(تصویر|عکس|image|picture|photo)\b/iu', $alt ) ) {
			$checks[] = array( 'info', 'با «تصویر/عکس» شروع شده؛ این کلمه اضافه است.' );
		}
		if ( '' !== $file && AltQuality::is_generic( $alt, $file ) ) {
			$checks[] = array( 'error', 'شبیه نام فایل یا متن بی‌معناست.' );
		}
		if ( '' !== $keyword && substr_count( mb_strtolower( $alt ), mb_strtolower( $keyword ) ) > 1 ) {
			$checks[] = array( 'warning', 'کلمه کلیدی تکرار شده است.' );
		}
		return $checks;
	}

	/**
	 * Single-line alt text (no tags, quotes kept, max 150 characters).
	 *
	 * @param string $alt Alt.
	 */
	public static function clean_alt( string $alt ): string {
		$alt = self::squash( wp_strip_all_tags( $alt ) );
		$alt = rtrim( $alt, '.。' );
		return mb_substr( $alt, 0, 150 );
	}

	/**
	 * Published posts with missing or generic alts (from the last scan), most images first.
	 *
	 * @param int $limit Posts.
	 * @return int[]
	 */
	private static function posts_needing_alt( int $limit ): array {
		global $wpdb;
		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_id FROM {$table}
				WHERE status = 'publish' AND ( inline_images_no_alt > 0 OR images_generic_alt > 0 OR ( has_featured_image = 1 AND featured_alt_ok = 0 ) )
				ORDER BY ( inline_images_no_alt + images_generic_alt ) DESC, object_id DESC LIMIT %d",
				$limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Caption of the image's <figure>, if any.
	 *
	 * @param DOMElement $img Image.
	 */
	private static function caption( DOMElement $img ): string {
		$node = $img->parentNode;
		for ( $depth = 0; $node instanceof DOMElement && $depth < 3; $depth++ ) {
			if ( 'figure' === strtolower( $node->nodeName ) ) {
				foreach ( $node->getElementsByTagName( 'figcaption' ) as $cap ) {
					return mb_substr( self::squash( $cap->textContent ), 0, 200 );
				}
				return '';
			}
			$node = $node->parentNode;
		}
		return '';
	}

	/**
	 * First words of a post (context for the featured image).
	 *
	 * @param string $html HTML.
	 */
	private static function plain_start( string $html ): string {
		return mb_substr( self::squash( wp_strip_all_tags( strip_shortcodes( $html ) ) ), 0, self::CONTEXT_CHARS );
	}

	/**
	 * Collapses whitespace.
	 *
	 * @param string $text Text.
	 */
	private static function squash( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}
}
