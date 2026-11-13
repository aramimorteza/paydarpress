<?php
/**
 * Parses and normalises a `paydarpress-import/1` file from ContentMaster. WordPress-independent.
 *
 * Only known keys survive; strings are trimmed and length-limited; HTML is kept as a string
 * here and sanitised by WordPress when the draft is created.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Import;

use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import file parser.
 */
final class ImportParser {

	public const SCHEMA          = 'paydarpress-import/1';
	public const MAX_BYTES       = 5 * 1024 * 1024;
	public const MAX_TOPICS      = 200;
	public const MAX_ARTICLES    = 20;
	public const MAX_SUGGESTIONS = 500;
	public const MAX_HTML_BYTES  = 500 * 1024;

	private const INTENTS = array( 'informational', 'educational', 'how-to', 'commercial', 'support', 'transactional' );

	/**
	 * Parses pasted text or file content.
	 *
	 * @param string $text Raw text (JSON, possibly inside a Markdown code block or with chat text around it).
	 * @return array{meta: array, topics: array, articles: array, link_suggestions: array, notices: string[]}
	 * @throws RuntimeException When no valid import object is found.
	 */
	public static function parse( string $text ): array {
		if ( strlen( $text ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'حجم متن بیش از ۵ مگابایت است. مقالات را در چند نوبت وارد کنید.' );
		}

		$data = self::decode( $text );

		if ( ( $data['schema'] ?? '' ) !== self::SCHEMA ) {
			if ( isset( $data['export_meta'] ) ) {
				throw new RuntimeException( 'این فایل «خروجی سایت» خود پایدارپرس است، نه پاسخ هوش مصنوعی. پاسخ JSON دستیار هوش مصنوعی را اینجا بچسبانید.' );
			}
			throw new RuntimeException( 'قالب متن شناخته نشد. کل بلوک JSON پاسخ هوش مصنوعی را کپی کنید و بچسبانید (پرامپت را از دکمه‌های «… با هوش مصنوعی» پایدارپرس بگیرید).' );
		}

		$notices  = array();
		$topics   = self::items( $data['topics'] ?? array(), self::MAX_TOPICS, 'topics', $notices );
		$articles = self::items( $data['articles'] ?? array(), self::MAX_ARTICLES, 'articles', $notices );
		$links    = self::items( $data['link_suggestions'] ?? array(), self::MAX_SUGGESTIONS, 'link_suggestions', $notices );
		$terms    = self::items( $data['term_descriptions'] ?? array(), 50, 'term_descriptions', $notices );
		$alts     = self::items( $data['image_alts'] ?? array(), 200, 'image_alts', $notices );
		$classes  = self::items( $data['classifications'] ?? array(), 2000, 'classifications', $notices );
		$seo      = self::items( $data['seo_updates'] ?? array(), 100, 'seo_updates', $notices );
		$struct   = self::items( $data['structure_suggestions'] ?? array(), 30, 'structure_suggestions', $notices );

		$out = array(
			'meta'             => array(
				'generator' => self::str( $data['generator'] ?? '', 60 ),
				'site'      => self::str( $data['site'] ?? '', 190 ),
			),
			'topics'           => array_values( array_filter( array_map( array( self::class, 'topic' ), $topics ) ) ),
			'articles'         => array(),
			'link_suggestions' => array_values( array_filter( array_map( array( self::class, 'suggestion' ), $links ) ) ),
			'term_descriptions' => array_values( array_filter( array_map( array( self::class, 'term_description' ), $terms ) ) ),
			'image_alts'       => array_values( array_filter( array_map( array( self::class, 'image_alt' ), $alts ) ) ),
			'classifications'  => array_values( array_filter( array_map( array( self::class, 'classification' ), $classes ) ) ),
			'seo_updates'      => array_values( array_filter( array_map( array( self::class, 'seo_update' ), $seo ) ) ),
			'structure_suggestions' => array_values( array_filter( array_map( array( self::class, 'structure_suggestion' ), $struct ) ) ),
			'notes'            => self::str( $data['notes'] ?? '', 3000 ),
			'notices'          => $notices,
		);

		foreach ( $articles as $raw ) {
			$article = self::article( $raw );
			if ( null === $article ) {
				continue;
			}
			if ( strlen( $article['html'] ) > self::MAX_HTML_BYTES ) {
				$out['notices'][] = 'html_too_large:' . $article['title'];
				continue;
			}
			$out['articles'][] = $article;
		}

		if ( array() === $out['topics'] && array() === $out['articles'] && array() === $out['link_suggestions'] && array() === $out['term_descriptions'] && array() === $out['seo_updates'] && array() === $out['structure_suggestions'] && array() === $out['image_alts'] && array() === $out['classifications'] ) {
			if ( '' !== $out['notes'] ) {
				throw new RuntimeException( 'هوش مصنوعی چیزی برای ورود نساخت و این توضیح را داد: ' . $out['notes'] );
			}
			throw new RuntimeException( 'متن معتبر است اما موضوع، مقاله یا پیشنهاد لینکی در آن نیست.' );
		}

		return $out;
	}

	/**
	 * Whether two titles are the same or nearly the same topic: equal normalised keys, or
	 * a word overlap (Jaccard) of at least 0.75 when both have three or more words.
	 *
	 * @param string $a Title.
	 * @param string $b Title.
	 */
	public static function similar_titles( string $a, string $b ): bool {
		return self::similar_keys( PersianNormalizer::for_key( $a ), PersianNormalizer::for_key( $b ) );
	}

	/**
	 * Same as similar_titles() for keys already normalised with PersianNormalizer::for_key().
	 *
	 * @param string $ka Key.
	 * @param string $kb Key.
	 */
	public static function similar_keys( string $ka, string $kb ): bool {
		if ( '' === $ka || '' === $kb ) {
			return false;
		}
		if ( str_replace( ' ', '', $ka ) === str_replace( ' ', '', $kb ) ) {
			return true;
		}

		$wa = array_unique( explode( ' ', $ka ) );
		$wb = array_unique( explode( ' ', $kb ) );
		if ( count( $wa ) < 3 || count( $wb ) < 3 ) {
			return false;
		}

		$common = count( array_intersect( $wa, $wb ) );
		$union  = count( array_unique( array_merge( $wa, $wb ) ) );

		return $union > 0 && $common / $union >= 0.75;
	}

	/**
	 * Finds and decodes the first JSON object in the text.
	 *
	 * @param string $text Raw text.
	 * @return array
	 * @throws RuntimeException On invalid JSON.
	 */
	private static function decode( string $text ): array {
		$text = trim( $text );
		if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}

		// Inside a Markdown code block?
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/su', $text, $m ) ) {
			$text = $m[1];
		} else {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false === $start || false === $end || $end <= $start ) {
				throw new RuntimeException( 'متنی با قالب JSON پیدا نشد. کل بلوک کد پاسخ هوش مصنوعی را کپی کنید.' );
			}
			$text = substr( $text, $start, $end - $start + 1 );
		}

		$data = json_decode( $text, true, 64 );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'متن JSON ناقص یا خراب است (' . json_last_error_msg() . '). احتمالاً بخشی از متن هنگام کپی جا مانده؛ دوباره کل بلوک کد را کپی کنید یا از هوش مصنوعی بخواهید هر مقاله را در پاسخی جداگانه بدهد.' );
		}

		return $data;
	}

	/**
	 * List items with a count limit.
	 *
	 * @param mixed  $list    Raw list.
	 * @param int    $max     Limit.
	 * @param string $name    Section name for notices.
	 * @param array  $notices Notices (by reference).
	 */
	private static function items( $list, int $max, string $name, array &$notices ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$list = array_values( array_filter( $list, 'is_array' ) );
		if ( count( $list ) > $max ) {
			$notices[] = 'limit:' . $name . ':' . $max;
			$list      = array_slice( $list, 0, $max );
		}

		return $list;
	}

	/**
	 * One topic.
	 *
	 * @param array $raw Raw.
	 */
	private static function topic( array $raw ): ?array {
		$title = self::str( $raw['title'] ?? '', 255 );
		if ( '' === $title ) {
			return null;
		}
		$intent = strtolower( self::str( $raw['intent'] ?? '', 30 ) );

		return array(
			'title'         => $title,
			'category'      => self::str( $raw['category'] ?? '', 200 ),
			'category_id'   => self::int( $raw['category_id'] ?? null ),
			'focus_keyword' => self::str( $raw['focus_keyword'] ?? '', 255 ),
			'intent'        => in_array( $intent, self::INTENTS, true ) ? $intent : '',
			'notes'         => self::str( $raw['notes'] ?? '', 1000 ),
		);
	}

	/**
	 * One article.
	 *
	 * @param array $raw Raw.
	 */
	private static function article( array $raw ): ?array {
		$title = self::str( $raw['title'] ?? '', 255 );
		$html  = is_string( $raw['html'] ?? null ) ? trim( $raw['html'] ) : '';
		if ( '' === $title || '' === $html ) {
			return null;
		}

		$seo   = is_array( $raw['seo'] ?? null ) ? $raw['seo'] : array();
		$image = is_array( $raw['featured_image'] ?? null ) ? $raw['featured_image'] : array();
		$faq   = array();
		foreach ( array_slice( is_array( $raw['faq'] ?? null ) ? $raw['faq'] : array(), 0, 30 ) as $item ) {
			if ( is_array( $item ) ) {
				$q = self::str( $item['q'] ?? $item['question'] ?? '', 500 );
				$a = self::str( $item['a'] ?? $item['answer'] ?? '', 3000 );
				if ( '' !== $q && '' !== $a ) {
					$faq[] = array(
						'q' => $q,
						'a' => $a,
					);
				}
			}
		}
		$reverse = array();
		foreach ( array_slice( is_array( $raw['reverse_links'] ?? null ) ? $raw['reverse_links'] : array(), 0, 20 ) as $item ) {
			if ( is_array( $item ) && '' !== self::str( $item['source_url'] ?? '', 2000 ) ) {
				$reverse[] = array(
					'source_url' => self::str( $item['source_url'], 2000 ),
					'anchor'     => self::str( $item['anchor'] ?? '', 255 ),
					'sentence'   => self::str( $item['sentence'] ?? '', 1000 ),
				);
			}
		}
		$tags = array();
		foreach ( array_slice( is_array( $raw['tags'] ?? null ) ? $raw['tags'] : array(), 0, 15 ) as $tag ) {
			$tag = self::str( is_scalar( $tag ) ? (string) $tag : '', 100 );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}

		// Strip a leading <h1> repeating the title (WordPress shows the title itself).
		$html = (string) preg_replace( '#^\s*<h1[^>]*>.*?</h1>\s*#isu', '', $html );

		return array(
			'rewrite_of'    => self::str( $raw['rewrite_of'] ?? '', 2000 ),
			'title'         => $title,
			'slug'          => self::str( $raw['slug'] ?? '', 190 ),
			'html'          => $html,
			'category'      => self::str( $raw['category'] ?? '', 200 ),
			'category_id'   => self::int( $raw['category_id'] ?? null ),
			'tags'          => array_values( array_unique( $tags ) ),
			'seo'           => array(
				'title'            => self::str( $seo['title'] ?? '', 255 ),
				'meta_description' => self::str( $seo['meta_description'] ?? $seo['description'] ?? '', 500 ),
				'focus_keyword'    => self::str( $seo['focus_keyword'] ?? $seo['keyword'] ?? '', 255 ),
			),
			'excerpt'       => self::str( $raw['excerpt'] ?? '', 1000 ),
			'author'        => self::str( $raw['author'] ?? '', 100 ),
			'image_prompt'  => self::str( $image['prompt'] ?? '', 3000 ),
			'image_alt'     => self::str( $image['alt'] ?? '', 255 ),
			'inline_images' => array_values(
				array_filter(
					array_map(
						static fn( $i ) => is_array( $i ) && ! empty( $i['prompt'] ) ? array(
							'after_heading' => self::str( $i['after_heading'] ?? '', 200 ),
							'prompt'        => self::str( $i['prompt'], 2000 ),
							'alt'           => self::str( $i['alt'] ?? '', 255 ),
							'why'           => self::str( $i['why'] ?? '', 300 ),
						) : null,
						array_slice( is_array( $raw['inline_images'] ?? null ) ? $raw['inline_images'] : array(), 0, 5 )
					)
				)
			),
			'faq'           => $faq,
			'reverse_links' => $reverse,
		);
	}

	/**
	 * Category and tag suggestions for one existing item.
	 *
	 * @param array $raw Raw.
	 */
	private static function classification( array $raw ): ?array {
		$id = self::int( $raw['id'] ?? null );
		if ( null === $id || $id <= 0 ) {
			return null;
		}
		$list = static function ( $v, int $max ): array {
			$out = array();
			foreach ( array_slice( is_array( $v ) ? $v : array(), 0, $max ) as $x ) {
				$x = is_scalar( $x ) ? self::str( (string) $x, 100 ) : '';
				if ( '' !== $x ) {
					$out[] = $x;
				}
			}
			return array_values( array_unique( $out ) );
		};
		$misfit = array();
		foreach ( array_slice( is_array( $raw['misfit'] ?? null ) ? $raw['misfit'] : array(), 0, 5 ) as $m ) {
			if ( is_array( $m ) && '' !== self::str( $m['term'] ?? '', 100 ) ) {
				$misfit[] = array(
					'term'   => self::str( $m['term'], 100 ),
					'reason' => self::str( $m['reason'] ?? '', 200 ),
				);
			}
		}
		return array(
			'id'               => $id,
			'add_categories'   => $list( $raw['add_categories'] ?? array(), 3 ),
			'primary_category' => self::str( $raw['primary_category'] ?? '', 100 ),
			'add_tags'         => $list( $raw['add_tags'] ?? array(), 5 ),
			'new_tags'         => $list( $raw['new_tags'] ?? array(), 2 ),
			'misfit'           => $misfit,
		);
	}

	/**
	 * Alt text for one image occurrence ("p12-i3" = 3rd image of post 12, "p12-f" = featured).
	 *
	 * @param array $raw Raw.
	 */
	private static function image_alt( array $raw ): ?array {
		$ref = strtolower( self::str( $raw['ref'] ?? '', 40 ) );
		$alt = self::str( $raw['alt'] ?? '', 300 );
		if ( ! preg_match( '/^p\d+-(f|i\d+)$/', $ref ) || '' === $alt ) {
			return null;
		}
		return array(
			'ref' => $ref,
			'alt' => $alt,
		);
	}

	/**
	 * New description of a category, tag or product category.
	 *
	 * @param array $raw Raw.
	 */
	private static function term_description( array $raw ): ?array {
		$html = is_string( $raw['description'] ?? null ) ? trim( $raw['description'] ) : '';
		$name = self::str( $raw['name'] ?? $raw['term'] ?? '', 200 );
		$id   = self::int( $raw['term_id'] ?? null );
		if ( '' === $html || ( '' === $name && null === $id ) ) {
			return null;
		}
		$tax = (string) preg_replace( '/[^a-z_]/', '', strtolower( self::str( $raw['taxonomy'] ?? 'category', 32 ) ) );

		return array(
			'taxonomy'    => in_array( $tax, array( 'category', 'post_tag', 'product_cat', 'product_tag' ), true ) ? $tax : 'category',
			'term_id'     => $id,
			'name'        => $name,
			'description' => mb_substr( $html, 0, 20000 ),
		);
	}

	/**
	 * One category-structure suggestion.
	 *
	 * @param array $raw Raw.
	 */
	public static function structure_suggestion( array $raw ): ?array {
		$action = strtolower( self::str( $raw['action'] ?? '', 20 ) );
		if ( ! in_array( $action, array( 'merge', 'split', 'create', 'rename', 'noindex', 'keep' ), true ) ) {
			return null;
		}
		$reason = self::str( $raw['reason'] ?? '', 600 );
		$tax    = in_array( $raw['taxonomy'] ?? '', array( 'category', 'product_cat' ), true ) ? $raw['taxonomy'] : 'category';
		$prio   = in_array( $raw['priority'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $raw['priority'] : 'medium';
		return array(
			'action'       => $action,
			'taxonomy'     => $tax,
			'term_id'      => self::int( $raw['term_id'] ?? null ),
			'name'         => self::str( $raw['name'] ?? '', 200 ),
			'new_name'     => self::str( $raw['new_name'] ?? '', 200 ),
			'into_term_id' => self::int( $raw['into_term_id'] ?? null ),
			'into_name'    => self::str( $raw['into_name'] ?? '', 200 ),
			'reason'       => $reason,
			'priority'     => $prio,
		);
	}

	/**
	 * New SEO title / meta description of an existing page.
	 *
	 * @param array $raw Raw.
	 */
	private static function seo_update( array $raw ): ?array {
		$url   = self::str( $raw['url'] ?? '', 2000 );
		$title = self::str( $raw['seo_title'] ?? $raw['title'] ?? '', 255 );
		$desc  = self::str( $raw['meta_description'] ?? $raw['description'] ?? '', 500 );
		if ( '' === $url || ( '' === $title && '' === $desc ) ) {
			return null;
		}
		return array(
			'url'              => $url,
			'seo_title'        => $title,
			'meta_description' => $desc,
		);
	}

	/**
	 * One link suggestion.
	 *
	 * @param array $raw Raw.
	 */
	private static function suggestion( array $raw ): ?array {
		$source = self::str( $raw['source_url'] ?? '', 2000 );
		$target = self::str( $raw['target_url'] ?? '', 2000 );
		$anchor = self::str( $raw['anchor'] ?? '', 255 );
		if ( '' === $source || '' === $target || '' === $anchor ) {
			return null;
		}

		return array(
			'source_url' => $source,
			'target_url' => $target,
			'anchor'     => $anchor,
			'sentence'   => self::str( $raw['sentence'] ?? '', 1000 ),
		);
	}

	/**
	 * Plain single-line string, tags removed, length-limited.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum characters.
	 */
	private static function str( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $value ) ) );

		return mb_substr( $value, 0, $max );
	}

	/**
	 * Positive integer or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function int( $value ): ?int {
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}
}
