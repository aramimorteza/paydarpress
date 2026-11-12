<?php
/**
 * ContentMaster import: preview (checks only) and commit (topics, drafts, link suggestions).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Import;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\LinkResolver;
use PaydarWP\PaydarPress\Links\UrlNormalizer;
use PaydarWP\PaydarPress\Seo\SeoDetector;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import service.
 */
final class ImportService {

	/** Preview lifetime. */
	private const TTL = HOUR_IN_SECONDS;

	/** Post meta keys PaydarPress may write (nothing else is accepted from the file). */
	public const META_IMPORT = '_paydarpress_import_id';
	public const META_PROMPT = '_paydarpress_image_prompt';
	public const META_ALT    = '_paydarpress_image_alt';
	public const META_FAQ    = '_paydarpress_faq';
	public const META_SEO    = '_paydarpress_seo';

	/** @var UrlNormalizer */
	private $urls;

	/** @var LinkResolver */
	private $resolver;

	/** @var array|null Inventory rows used for duplicate checks. */
	private $inventory = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->urls     = new UrlNormalizer( home_url( '/' ) );
		$this->resolver = new LinkResolver();
	}

	/**
	 * Parses the text and runs every check. Nothing is written except a short-lived transient.
	 *
	 * @param string $text Pasted text or file content.
	 * @return array Preview payload with a token.
	 * @throws RuntimeException On invalid input.
	 */
	public function preview( string $text ): array {
		$parsed = ImportParser::parse( $text );

		$warnings = array();
		foreach ( $parsed['notices'] as $notice ) {
			if ( str_starts_with( $notice, 'limit:' ) ) {
				list( , $section, $max ) = explode( ':', $notice );
				$labels     = array(
					'topics'           => 'موضوع',
					'articles'         => 'مقاله',
					'link_suggestions' => 'پیشنهاد لینک',
				);
				$warnings[] = sprintf( 'فقط %s %s اول خوانده شد.', PersianNormalizer::fa_number( (int) $max ), $labels[ $section ] ?? $section );
			} elseif ( str_starts_with( $notice, 'html_too_large:' ) ) {
				$warnings[] = 'مقاله «' . substr( $notice, 15 ) . '» بیش از ۵۰۰ کیلوبایت است و وارد نشد.';
			}
		}

		$site = strtolower( (string) preg_replace( '#^(https?://)?(www\.)?#i', '', $parsed['meta']['site'] ) );
		$site = rtrim( (string) strtok( $site, '/' ), '/' );
		$home = strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( '' !== $site && $site !== $home ) {
			$warnings[] = sprintf( 'این خروجی برای سایت «%s» ساخته شده، اما این سایت «%s» است. اگر پروژه ContentMaster را اشتباه انتخاب کرده‌اید، ورود را ادامه ندهید.', $site, $home );
		}

		$categories = $this->categories();
		$topics_db  = $this->existing_topics();
		$seen       = array();

		$topics = array();
		foreach ( $parsed['topics'] as $i => $topic ) {
			$checks             = $this->duplicate_checks( $topic['title'], $topic['focus_keyword'], $topics_db, $seen );
			$category           = $this->resolve_category( $topic['category_id'], $topic['category'], $categories );
			$topic['category_id'] = $category['id'];
			if ( '' !== $topic['category'] && null === $category['id'] ) {
				$checks[] = array( 'warning', 'دسته «' . $topic['category'] . '» در سایت نیست.' );
			}
			$seen[]   = array( $topic['title'], $topic['focus_keyword'] );
			$topics[] = $this->with_checks( $topic + array( 'index' => $i, 'category_name' => $category['name'] ), $checks );
		}

		$articles = array();
		foreach ( $parsed['articles'] as $i => $article ) {
			$articles[] = $this->check_article( $i, $article, $categories, $topics_db, $seen );
			$seen[]     = array( $article['title'], $article['seo']['focus_keyword'] );
		}

		$suggestions = array();
		foreach ( $parsed['link_suggestions'] as $i => $s ) {
			$source = $this->resolve_url( $s['source_url'] );
			$target = $this->resolve_url( $s['target_url'] );
			$checks = array();
			if ( null === $source['id'] || 'ok' !== $source['state'] ) {
				$checks[] = array( 'error', 'نوشته مبدأ در سایت پیدا نشد.' );
			}
			if ( null === $target['id'] ) {
				$checks[] = array( 'error', 'صفحه مقصد در سایت پیدا نشد.' );
			}
			$suggestions[] = $this->with_checks(
				$s + array(
					'index'        => $i,
					'source_id'    => $source['id'],
					'source_title' => $source['title'],
					'target_id'    => $target['id'],
					'target_title' => $target['title'],
				),
				$checks
			);
		}

		$term_items = array();
		foreach ( $parsed['term_descriptions'] as $i => $t ) {
			$term_items[] = $this->check_term( $i, $t );
		}
		$seo_items = array();
		foreach ( $parsed['seo_updates'] as $i => $u ) {
			$seo_items[] = $this->check_seo( $i, $u );
		}
		if ( '' !== $parsed['notes'] ) {
			$warnings[] = 'توضیح هوش مصنوعی: ' . $parsed['notes'];
		}

		$token   = wp_generate_password( 20, false, false );
		$payload = array(
			'token'            => $token,
			'meta'             => $parsed['meta'],
			'warnings'         => $warnings,
			'seo_plugin'       => SeoDetector::adapter()->key(),
			'topics'           => $topics,
			'articles'         => $articles,
			'link_suggestions' => $suggestions,
			'term_descriptions' => $term_items,
			'seo_updates'      => $seo_items,
		);

		set_transient( $this->transient_key( $token ), $payload, self::TTL );

		// The browser does not need the article HTML back; it gets a short preview.
		$public = $payload;
		foreach ( $public['articles'] as &$article ) {
			$article['html_preview'] = mb_substr( self::plain( $article['html'] ), 0, 300 );
			unset( $article['html'] );
		}
		unset( $article );

		return $public;
	}

	/**
	 * Creates the selected items from a preview.
	 *
	 * @param string $token     Preview token.
	 * @param array  $selection Indexes per section: topics, articles, link_suggestions.
	 * @return array Results.
	 * @throws RuntimeException When the preview expired.
	 */
	public function commit( string $token, array $selection ): array {
		global $wpdb;

		$key     = $this->transient_key( $token );
		$preview = get_transient( $key );
		if ( ! is_array( $preview ) ) {
			throw new RuntimeException( 'پیش‌نمایش منقضی شده است (بیش از یک ساعت گذشته). متن را دوباره بچسبانید.' );
		}
		delete_transient( $key );

		$pick = static function ( string $section ) use ( $selection, $preview ): array {
			$wanted = array_map( 'intval', (array) ( $selection[ $section ] ?? array() ) );
			return array_values( array_filter( $preview[ $section ], static fn( $item ) => in_array( (int) $item['index'], $wanted, true ) && ! $item['blocked'] ) );
		};

		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'imports' ),
			array(
				'type'       => 'contentmaster',
				'file_name'  => mb_substr( (string) ( $preview['meta']['generator'] ?: 'ContentMaster' ), 0, 255 ),
				'status'     => 'running',
				'user_id'    => get_current_user_id(),
				'created_at' => $now,
			)
		);
		$import_id = (int) $wpdb->insert_id;

		$result = array(
			'topics'           => 0,
			'articles'         => array(),
			'link_suggestions' => 0,
			'terms'            => 0,
			'seo'              => 0,
			'errors'           => array(),
		);
		$preview += array(
			'term_descriptions' => array(),
			'seo_updates'       => array(),
		);

		foreach ( $pick( 'topics' ) as $topic ) {
			TopicRepository::insert(
				array(
					'title'         => $topic['title'],
					'category_id'   => $topic['category_id'],
					'focus_keyword' => $topic['focus_keyword'],
					'intent'        => $topic['intent'],
					'notes'         => $topic['notes'],
					'import_id'     => $import_id,
				)
			);
			++$result['topics'];
		}

		if ( current_user_can( 'edit_posts' ) ) {
			foreach ( $pick( 'articles' ) as $article ) {
				$post_id = $this->create_draft( $article, $import_id );
				if ( is_wp_error( $post_id ) ) {
					$result['errors'][] = '«' . $article['title'] . '»: ' . $post_id->get_error_message();
					continue;
				}
				$result['articles'][] = array(
					'id'       => $post_id,
					'title'    => $article['title'],
					'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
					'rewrite'  => ! empty( $article['rewrite_id'] ),
				);

				if ( empty( $article['rewrite_id'] ) ) {
					TopicRepository::mark_written( $article['title'], $post_id );
				}

				foreach ( $article['reverse_links'] as $link ) {
					if ( null !== $link['source_id'] ) {
						// A new article is born with its inbound links: they are approved with the
						// article and inserted when it is published (content policy).
						LinkSuggestionRepository::insert( (int) $link['source_id'], empty( $article['rewrite_id'] ) ? $post_id : (int) $article['rewrite_id'], $link['anchor'], $link['sentence'], 'contentmaster', $import_id, empty( $article['rewrite_id'] ) ? 'approved' : 'pending' );
						++$result['link_suggestions'];
					}
				}
			}
		} elseif ( array() !== $pick( 'articles' ) ) {
			$result['errors'][] = 'برای ساخت پیش‌نویس، دسترسی نوشتن نوشته لازم است.';
		}

		foreach ( $pick( 'term_descriptions' ) as $t ) {
			$done = $this->apply_term( $t );
			if ( true === $done ) {
				++$result['terms'];
			} else {
				$result['errors'][] = $done;
			}
		}
		foreach ( $pick( 'seo_updates' ) as $u ) {
			$done = $this->apply_seo( $u );
			if ( true === $done ) {
				++$result['seo'];
			} else {
				$result['errors'][] = $done;
			}
		}

		foreach ( $pick( 'link_suggestions' ) as $s ) {
			LinkSuggestionRepository::insert( (int) $s['source_id'], (int) $s['target_id'], $s['anchor'], $s['sentence'], 'contentmaster', $import_id );
			++$result['link_suggestions'];
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'imports' ),
			array(
				'status'  => 'completed',
				'summary' => wp_json_encode(
					array(
						'topics'           => $result['topics'],
						'articles'         => count( $result['articles'] ),
						'link_suggestions' => $result['link_suggestions'],
						'errors'           => count( $result['errors'] ),
					)
				),
			),
			array( 'id' => $import_id )
		);

		Activity::log(
			'import.contentmaster',
			sprintf(
				'ورود از ContentMaster: %s موضوع، %s پیش‌نویس، %s پیشنهاد لینک، %s متن دسته، %s متا.',
				PersianNormalizer::fa_number( $result['topics'] ),
				PersianNormalizer::fa_number( count( $result['articles'] ) ),
				PersianNormalizer::fa_number( $result['link_suggestions'] ),
				PersianNormalizer::fa_number( $result['terms'] ),
				PersianNormalizer::fa_number( $result['seo'] )
			),
			'import',
			$import_id
		);

		return $result;
	}

	/**
	 * Checks one article.
	 *
	 * @param int   $index      Index in the file.
	 * @param array $article    Parsed article.
	 * @param array $categories Category map.
	 * @param array $topics_db  Existing topics.
	 * @param array $seen       Earlier items of this file.
	 */
	private function check_article( int $index, array $article, array $categories, array $topics_db, array $seen ): array {
		$rewrite = null;
		if ( '' !== $article['rewrite_of'] ) {
			$target  = $this->resolve_url( $article['rewrite_of'] );
			$post    = null === $target['id'] ? null : get_post( (int) $target['id'] );
			$rewrite = $post && in_array( $post->post_type, array( 'post', 'page', 'product' ), true ) ? $post : null;
		}

		if ( '' !== $article['rewrite_of'] && null === $rewrite ) {
			$checks = array( array( 'error', 'نوشته‌ای که باید بازنویسی شود در سایت پیدا نشد: ' . rawurldecode( $article['rewrite_of'] ) ) );
		} elseif ( $rewrite ) {
			$checks = array( array( 'info', 'بازنویسی «' . html_entity_decode( get_the_title( $rewrite ), ENT_QUOTES, 'UTF-8' ) . '»: یک پیش‌نویس جدا ساخته می‌شود و نوشته فعلی تا وقتی در ویرایشگر «جایگزینی» را نزنید دست نمی‌خورد.' ) );
			if ( ! current_user_can( 'edit_post', $rewrite->ID ) ) {
				$checks[] = array( 'error', 'اجازه ویرایش این نوشته را ندارید.' );
			}
		} else {
			$checks = $this->duplicate_checks( $article['title'], $article['seo']['focus_keyword'], $topics_db, $seen, true );
		}
		$category = $this->resolve_category( $article['category_id'], $article['category'], $categories );
		if ( $rewrite && null === $category['id'] && 'post' === $rewrite->post_type ) {
			$cat_ids  = wp_get_post_categories( $rewrite->ID );
			$category = array(
				'id'   => $cat_ids ? (int) $cat_ids[0] : null,
				'name' => $cat_ids ? (string) get_cat_name( (int) $cat_ids[0] ) : '',
			);
		}

		if ( $rewrite && 'post' !== $rewrite->post_type ) {
			// Pages and products have no blog category.
		} elseif ( null === $category['id'] ) {
			$checks[] = array( 'warning', '' === $article['category'] ? 'دسته مشخص نشده؛ در دسته پیش‌فرض قرار می‌گیرد.' : 'دسته «' . $article['category'] . '» در سایت نیست؛ در دسته پیش‌فرض قرار می‌گیرد.' );
		}

		$slug = sanitize_title( $article['slug'] );
		if ( ! $rewrite && '' !== $slug && get_page_by_path( $slug, OBJECT, array( 'post', 'page' ) ) ) {
			$checks[] = array( 'warning', 'نامک «' . rawurldecode( $slug ) . '» قبلاً استفاده شده؛ وردپرس عددی به انتهای آن اضافه می‌کند.' );
		}

		// Internal links: links to pages that do not exist are removed (anchor text stays).
		$links = $this->links( $article['html'] );
		$bad   = array_filter( $links, static fn( $l ) => $l['internal'] && in_array( $l['state'], array( 'missing', 'unpublished' ), true ) );
		if ( $bad ) {
			$checks[] = array( 'warning', sprintf( '%s لینک داخلی به صفحه ناموجود یا منتشرنشده حذف می‌شود (متن لنگر می‌ماند): %s', PersianNormalizer::fa_number( count( $bad ) ), implode( '، ', array_map( static fn( $l ) => rawurldecode( $l['url'] ), $bad ) ) ) );
		}
		$internal_ok = count( array_filter( $links, static fn( $l ) => $l['internal'] && ! in_array( $l['state'], array( 'missing', 'unpublished' ), true ) ) );

		// Key pages: at most two per article, and only from the categories chosen for each page.
		$key_pages = \PaydarWP\PaydarPress\Links\KeyPageLinks::pages();
		$linked    = $key_pages ? \PaydarWP\PaydarPress\Links\KeyPageLinks::linked_in( $article['html'], array_keys( $key_pages ) ) : array();
		if ( count( $linked ) > \PaydarWP\PaydarPress\Links\KeyPageLinks::MAX_PER_ARTICLE ) {
			$checks[] = array( 'warning', sprintf( 'این مقاله به %s صفحه کلیدی لینک می‌دهد (%s)؛ سقف ۲ صفحه است. پس از ورود، لینک‌های کم‌اهمیت‌تر را در ویرایشگر بردارید.', PersianNormalizer::fa_number( count( $linked ) ), implode( '، ', array_map( static fn( $k ) => (string) ( $key_pages[ $k ]['name'] ?? $k ), $linked ) ) ) );
		}
		if ( null !== $category['id'] ) {
			foreach ( $linked as $k ) {
				$cats = array_map( 'intval', (array) ( $key_pages[ $k ]['link_categories'] ?? array() ) );
				if ( $cats && ! in_array( (int) $category['id'], $cats, true ) ) {
					$checks[] = array( 'info', 'صفحه کلیدی «' . ( $key_pages[ $k ]['name'] ?? $k ) . '» برای دسته‌های دیگری تعیین شده، نه «' . $category['name'] . '». اگر لینک به موضوع مقاله ربط دارد، مشکلی نیست.' );
				}
			}
		}

		$author = $this->resolve_author( $article['author'] );
		if ( '' !== $article['author'] && null === $author['id'] ) {
			$checks[] = array( 'info', 'نویسنده «' . $article['author'] . '» کاربر این سایت نیست؛ نوشته به نام شما ساخته می‌شود.' );
		}

		$existing_tags = array();
		foreach ( $article['tags'] as $tag ) {
			if ( term_exists( $tag, 'post_tag' ) ) {
				$existing_tags[] = $tag;
			}
		}
		$new_tags = array_values( array_diff( $article['tags'], $existing_tags ) );
		if ( $new_tags ) {
			$checks[] = array( 'info', 'برچسب‌های جدید ساخته می‌شوند: ' . implode( '، ', $new_tags ) );
		}

		$reverse = array();
		foreach ( $article['reverse_links'] as $link ) {
			$source    = $this->resolve_url( $link['source_url'] );
			$reverse[] = $link + array(
				'source_id'    => 'ok' === $source['state'] ? $source['id'] : null,
				'source_title' => $source['title'],
			);
		}

		return $this->with_checks(
			array_merge(
				$article,
				array(
					'index'          => $index,
					'category_id'    => $category['id'],
					'category_name'  => $category['name'],
					'author_id'      => $author['id'],
					'author_name'    => $author['name'],
					'words'          => PersianNormalizer::word_count( self::plain( $article['html'] ) ),
					'internal_links' => $internal_ok,
					'removed_links'  => array_values( array_map( static fn( $l ) => $l['url'], $bad ) ),
					'new_tags'       => $new_tags,
					'reverse_links'  => $reverse,
					'rewrite_id'     => $rewrite ? $rewrite->ID : null,
					'rewrite_type'   => $rewrite ? $rewrite->post_type : null,
					'rewrite_title'  => $rewrite ? html_entity_decode( get_the_title( $rewrite ), ENT_QUOTES, 'UTF-8' ) : null,
				)
			),
			$checks
		);
	}

	/**
	 * Duplicate checks against content, existing topics and earlier items of the same file.
	 *
	 * @param string $title     Title.
	 * @param string $keyword   Focus keyword.
	 * @param array  $topics_db Existing topics.
	 * @param array  $seen      Earlier items of this file: [title, keyword].
	 * @param bool   $is_article Whether the item is an article (a matching open topic is expected, not a duplicate).
	 * @return array<int, array{0:string, 1:string}>
	 */
	private function duplicate_checks( string $title, string $keyword, array $topics_db, array $seen, bool $is_article = false ): array {
		$checks      = array();
		$keyword_key = '' === $keyword ? '' : PersianNormalizer::for_key( $keyword );
		$title_key   = PersianNormalizer::for_key( $title );

		foreach ( $this->inventory() as $row ) {
			if ( ImportParser::similar_keys( $title_key, (string) $row['title_norm'] ) ) {
				$checks[] = array( 'duplicate', sprintf( 'عنوان مشابه «%s» در سایت وجود دارد.', $row['title'] ) );
				break;
			}
		}
		if ( '' !== $keyword_key ) {
			foreach ( $this->inventory() as $row ) {
				if ( $keyword_key === (string) $row['focus_keyword_norm'] ) {
					$checks[] = array( 'duplicate', sprintf( 'کلمه کلیدی «%s» قبلاً برای «%s» استفاده شده است.', $keyword, $row['title'] ) );
					break;
				}
			}
		}
		foreach ( $topics_db as $topic ) {
			if ( ImportParser::similar_titles( $title, $topic['title'] ) ) {
				if ( $is_article && in_array( $topic['status'], array( 'new', 'accepted' ), true ) ) {
					$checks[] = array( 'info', 'این مقاله برای موضوع پیشنهادی «' . $topic['title'] . '» است و آن موضوع «نوشته شد» علامت می‌خورد.' );
				} elseif ( ! $is_article ) {
					$checks[] = array( 'duplicate', sprintf( 'این موضوع قبلاً در موضوعات پیشنهادی هست (%s).', TopicRepository::STATUS_LABELS[ $topic['status'] ] ?? $topic['status'] ) );
				}
				break;
			}
		}
		foreach ( $seen as list( $other_title, $other_keyword ) ) {
			if ( ImportParser::similar_titles( $title, (string) $other_title ) || ( '' !== $keyword_key && $keyword_key === PersianNormalizer::for_key( (string) $other_keyword ) ) ) {
				if ( ! $is_article ) {
					$checks[] = array( 'duplicate', 'با مورد دیگری در همین متن تکراری است.' );
				}
				break;
			}
		}

		return $checks;
	}

	/**
	 * Adds checks plus the derived flags used by the UI and by commit.
	 *
	 * @param array $item   Item.
	 * @param array $checks Checks.
	 */
	private function with_checks( array $item, array $checks ): array {
		$item['checks']    = array_map(
			static fn( $c ) => array(
				'level' => $c[0],
				'text'  => $c[1],
			),
			$checks
		);
		$item['duplicate'] = (bool) array_filter( $checks, static fn( $c ) => 'duplicate' === $c[0] );
		$item['blocked']   = (bool) array_filter( $checks, static fn( $c ) => 'error' === $c[0] );

		return $item;
	}

	/**
	 * Creates one draft.
	 *
	 * @param array $article   Checked article.
	 * @param int   $import_id Import.
	 * @return int|\WP_Error Post ID.
	 */
	private function create_draft( array $article, int $import_id ) {
		$html = $this->remove_links( $article['html'], $article['removed_links'] );
		// wp_kses_post() strips the tags but keeps their text; drop executable/embedded blocks entirely.
		$html = (string) preg_replace( '#<(script|style|iframe|noscript|object|embed|form)\b[^>]*>.*?</\1\s*>#isu', '', $html );

		$type    = empty( $article['rewrite_id'] ) ? 'post' : (string) $article['rewrite_type'];
		$content = wp_kses_post( $html );
		// FAQ goes into the article itself (accordion blocks), unless the HTML has its own section.
		if ( $article['faq'] && 'product' !== $type && ! \PaydarWP\PaydarPress\Content\Faq::has_section( $content ) ) {
			$content .= \PaydarWP\PaydarPress\Content\Faq::blocks( $article['faq'] );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'     => $type,
				'post_status'   => 'draft',
				'post_title'    => $article['title'],
				// A rewrite keeps the original's address; the draft gets none of its own.
				'post_name'     => empty( $article['rewrite_id'] ) ? sanitize_title( $article['slug'] ) : '',
				'post_content'  => $content,
				'post_excerpt'  => sanitize_textarea_field( $article['excerpt'] ),
				'post_author'   => $article['author_id'] ?? get_current_user_id(),
				'post_category' => null === $article['category_id'] || 'post' !== $type ? array() : array( (int) $article['category_id'] ),
				'tags_input'    => 'post' === $type ? $article['tags'] : array(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$seo = array(
			'title'            => $article['seo']['title'],
			'meta_description' => $article['seo']['meta_description'],
			'focus_keyword'    => $article['seo']['focus_keyword'],
			'primary_term_id'  => $article['category_id'],
		);
		if ( ! SeoDetector::adapter()->write_post( $post_id, $seo ) ) {
			update_post_meta( $post_id, self::META_SEO, array_map( 'sanitize_text_field', array_filter( array_map( 'strval', $seo ) ) ) );
		}

		update_post_meta( $post_id, self::META_IMPORT, $import_id );
		if ( ! empty( $article['rewrite_id'] ) ) {
			update_post_meta( $post_id, Rewrites::META_OF, (int) $article['rewrite_id'] );
		}
		if ( '' !== $article['image_prompt'] ) {
			update_post_meta( $post_id, self::META_PROMPT, sanitize_textarea_field( $article['image_prompt'] ) );
		}
		if ( '' !== $article['image_alt'] ) {
			update_post_meta( $post_id, self::META_ALT, sanitize_text_field( $article['image_alt'] ) );
		}
		if ( $article['faq'] ) {
			update_post_meta(
				$post_id,
				self::META_FAQ,
				array_map(
					static fn( $f ) => array(
						'q' => sanitize_text_field( $f['q'] ),
						'a' => sanitize_textarea_field( $f['a'] ),
					),
					$article['faq']
				)
			);
		}

		return (int) $post_id;
	}

	/**
	 * Checks a term description.
	 *
	 * @param int   $index Index.
	 * @param array $t     Parsed item.
	 */
	private function check_term( int $index, array $t ): array {
		$term = null;
		if ( null !== $t['term_id'] ) {
			$found = get_term( (int) $t['term_id'], $t['taxonomy'] );
			$term  = $found instanceof \WP_Term ? $found : null;
		}
		if ( null === $term && '' !== $t['name'] ) {
			$found = get_term_by( 'name', $t['name'], $t['taxonomy'] );
			$term  = $found instanceof \WP_Term ? $found : null;
		}
		$checks = array();
		if ( null === $term ) {
			$checks[] = array( 'error', '«' . $t['name'] . '» در سایت پیدا نشد.' );
		} elseif ( ! current_user_can( 'manage_categories' ) ) {
			$checks[] = array( 'error', 'اجازه ویرایش دسته‌ها را ندارید.' );
		} elseif ( '' !== trim( (string) $term->description ) ) {
			$checks[] = array( 'warning', 'متن فعلی جایگزین می‌شود؛ نسخه قبلی برای بازگرداندن ذخیره می‌ماند.' );
		}

		return $this->with_checks(
			$t + array(
				'index'       => $index,
				'term_id'     => $term ? (int) $term->term_id : null,
				'name'        => $term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : $t['name'],
				'current'     => $term ? mb_substr( self::plain( (string) $term->description ), 0, 300 ) : '',
				'new_preview' => mb_substr( self::plain( $t['description'] ), 0, 400 ),
				'words'       => PersianNormalizer::word_count( self::plain( $t['description'] ) ),
			),
			$checks
		);
	}

	/**
	 * Checks an SEO title / meta description update.
	 *
	 * @param int   $index Index.
	 * @param array $u     Parsed item.
	 */
	private function check_seo( int $index, array $u ): array {
		$target = $this->resolve_url( $u['url'] );
		$checks = array();
		$id     = 'ok' === $target['state'] ? $target['id'] : null;
		if ( null === $id ) {
			$checks[] = array( 'error', 'صفحه پیدا نشد: ' . rawurldecode( $u['url'] ) );
		} elseif ( ! current_user_can( 'edit_post', $id ) ) {
			$checks[] = array( 'error', 'اجازه ویرایش این صفحه را ندارید.' );
		} elseif ( 'none' === SeoDetector::adapter()->key() ) {
			$checks[] = array( 'error', 'هیچ افزونه سئوی پشتیبانی‌شده‌ای (Rank Math، Yoast، AIOSEO) فعال نیست؛ متن را دستی در قالب یا افزونه سئو وارد کنید.' );
		}
		if ( mb_strlen( $u['seo_title'] ) > 60 ) {
			$checks[] = array( 'warning', 'عنوان سئو بیش از ۶۰ نویسه است و ممکن است در گوگل کوتاه شود.' );
		}
		if ( mb_strlen( $u['meta_description'] ) > 160 ) {
			$checks[] = array( 'warning', 'توضیحات متا بیش از ۱۶۰ نویسه است.' );
		}
		$current = null === $id ? array() : SeoDetector::adapter()->post( (int) $id );

		return $this->with_checks(
			$u + array(
				'index'            => $index,
				'post_id'          => $id,
				'title'            => $target['title'],
				'current_title'    => (string) ( $current['seo_title'] ?? '' ),
				'current_description' => (string) ( $current['meta_description'] ?? '' ),
			),
			$checks
		);
	}

	/**
	 * Saves a term description (previous text kept in term meta).
	 *
	 * @param array $t Checked item.
	 * @return true|string True or an error message.
	 */
	private function apply_term( array $t ) {
		$term = get_term( (int) $t['term_id'], $t['taxonomy'] );
		if ( ! $term instanceof \WP_Term || ! current_user_can( 'manage_categories' ) ) {
			return '«' . $t['name'] . '»: دسته پیدا نشد یا اجازه ویرایش ندارید.';
		}
		$html = (string) preg_replace( '#<(script|style|iframe|noscript|object|embed|form)\b[^>]*>.*?</\1\s*>#isu', '', $t['description'] );
		$html = wp_kses_post( $html );
		update_term_meta( $term->term_id, '_paydarpress_prev_description', (string) $term->description );

		// Term descriptions normally pass a strict filter that removes paragraphs and headings
		// for users without unfiltered_html; the text was already cleaned with wp_kses_post.
		$kses = false !== has_filter( 'pre_term_description', 'wp_filter_kses' );
		if ( $kses ) {
			remove_filter( 'pre_term_description', 'wp_filter_kses' );
		}
		$r = wp_update_term( $term->term_id, $term->taxonomy, array( 'description' => $html ) );
		if ( $kses ) {
			add_filter( 'pre_term_description', 'wp_filter_kses' );
		}
		if ( is_wp_error( $r ) ) {
			return '«' . $t['name'] . '»: ' . $r->get_error_message();
		}
		Activity::log( 'import.term_description', 'متن معرفی «' . $t['name'] . '» به‌روز شد.', 'term', (int) $term->term_id );
		return true;
	}

	/**
	 * Saves a new SEO title / description through the SEO plugin (previous values kept).
	 *
	 * @param array $u Checked item.
	 * @return true|string True or an error message.
	 */
	private function apply_seo( array $u ) {
		$id = (int) $u['post_id'];
		if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
			return '«' . $u['title'] . '»: اجازه ویرایش ندارید.';
		}
		$adapter = SeoDetector::adapter();
		$before  = $adapter->post( $id );
		$ok      = $adapter->write_post(
			$id,
			array_filter(
				array(
					'title'            => $u['seo_title'],
					'meta_description' => $u['meta_description'],
				)
			)
		);
		if ( ! $ok ) {
			return '«' . $u['title'] . '»: افزونه سئوی فعال از نوشتن پشتیبانی نمی‌کند.';
		}
		update_post_meta(
			$id,
			'_paydarpress_prev_seo',
			array(
				'title'            => (string) ( $before['seo_title'] ?? '' ),
				'meta_description' => (string) ( $before['meta_description'] ?? '' ),
				'changed_at'       => gmdate( 'c' ),
			)
		);
		Activity::log( 'import.seo_update', 'عنوان و توضیحات متای «' . $u['title'] . '» به‌روز شد.', 'post', $id );
		return true;
	}

	/**
	 * Unwraps links whose href is in the given list (keeps the anchor text).
	 *
	 * @param string   $html HTML.
	 * @param string[] $urls URLs to remove.
	 */
	private function remove_links( string $html, array $urls ): string {
		if ( array() === $urls ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#isu',
			static fn( $m ) => in_array( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ), $urls, true ) ? $m[3] : $m[0],
			$html
		);
	}

	/**
	 * Links in the article HTML with their resolution.
	 *
	 * @param string $html HTML.
	 * @return array<int, array{url:string, internal:bool, state:string}>
	 */
	private function links( string $html ): array {
		$out = array();
		if ( ! preg_match_all( '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#isu', $html, $matches ) ) {
			return $out;
		}

		foreach ( array_unique( $matches[2] ) as $href ) {
			$href = html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' );
			$abs  = $this->urls->absolute( $href, home_url( '/' ) );
			if ( null === $abs || ! $this->urls->is_internal( $abs ) ) {
				$out[] = array(
					'url'      => $href,
					'internal' => false,
					'state'    => 'external',
				);
				continue;
			}
			list( , $state ) = $this->resolver->resolve( $abs );
			$out[]           = array(
				'url'      => $href,
				'internal' => true,
				'state'    => $state,
			);
		}

		return $out;
	}

	/**
	 * Resolves a URL to content.
	 *
	 * @param string $url URL.
	 * @return array{id:?int, state:string, title:string}
	 */
	private function resolve_url( string $url ): array {
		$abs = $this->urls->absolute( $url, home_url( '/' ) );
		if ( null === $abs || ! $this->urls->is_internal( $abs ) ) {
			return array(
				'id'    => null,
				'state' => 'external',
				'title' => '',
			);
		}
		list( $id, $state ) = $this->resolver->resolve( $abs );

		return array(
			'id'    => null === $id ? null : (int) $id,
			'state' => $state,
			'title' => null === $id ? '' : html_entity_decode( get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' ),
		);
	}

	/**
	 * Category by ID or by (normalised) name; never created.
	 *
	 * @param int|null $id         ID from the file.
	 * @param string   $name       Name from the file.
	 * @param array    $categories Map from categories().
	 * @return array{id:?int, name:string}
	 */
	private function resolve_category( ?int $id, string $name, array $categories ): array {
		if ( null !== $id && isset( $categories['by_id'][ $id ] ) ) {
			return array(
				'id'   => $id,
				'name' => $categories['by_id'][ $id ],
			);
		}
		$key = PersianNormalizer::compact( $name );
		if ( '' !== $key && isset( $categories['by_key'][ $key ] ) ) {
			$found = $categories['by_key'][ $key ];
			return array(
				'id'   => $found,
				'name' => $categories['by_id'][ $found ],
			);
		}

		return array(
			'id'   => null,
			'name' => '',
		);
	}

	/**
	 * Post categories.
	 *
	 * @return array{by_id: array<int, string>, by_key: array<string, int>}
	 */
	private function categories(): array {
		$out   = array(
			'by_id'  => array(),
			'by_key' => array(),
		);
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$name                                                 = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			$out['by_id'][ (int) $term->term_id ]                 = $name;
			$out['by_key'][ PersianNormalizer::compact( $name ) ] = (int) $term->term_id;
		}

		return $out;
	}

	/**
	 * A site user who can write posts, matched by display name.
	 *
	 * @param string $name Display name from the file.
	 * @return array{id:?int, name:string}
	 */
	private function resolve_author( string $name ): array {
		if ( '' !== $name ) {
			$users = get_users(
				array(
					'capability' => 'edit_posts',
					'number'     => 200,
					'fields'     => array( 'ID', 'display_name' ),
				)
			);
			$key   = PersianNormalizer::compact( $name );
			foreach ( $users as $user ) {
				if ( PersianNormalizer::compact( (string) $user->display_name ) === $key ) {
					return array(
						'id'   => (int) $user->ID,
						'name' => (string) $user->display_name,
					);
				}
			}
		}

		$me = wp_get_current_user();

		return array(
			'id'   => null,
			'name' => (string) $me->display_name,
		);
	}

	/**
	 * Inventory rows with display titles (published and unpublished content).
	 */
	private function inventory(): array {
		if ( null === $this->inventory ) {
			global $wpdb;
			$table = Schema::table( 'inventory' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( "SELECT i.object_id, i.title_norm, i.focus_keyword_norm, p.post_title AS title FROM {$table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.object_id", ARRAY_A );
			foreach ( $rows as &$row ) {
				$row['title'] = html_entity_decode( (string) $row['title'], ENT_QUOTES, 'UTF-8' );
			}
			$this->inventory = $rows;
		}

		return $this->inventory;
	}

	/**
	 * Existing topics (all statuses).
	 */
	private function existing_topics(): array {
		return TopicRepository::all_titles();
	}

	/**
	 * Plain text of HTML with a space at every tag boundary (so "</p><p>" does not glue words).
	 *
	 * @param string $html HTML.
	 */
	private static function plain( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', $html ) ) ) );
	}

	/**
	 * Transient key bound to the current user.
	 *
	 * @param string $token Token.
	 */
	private function transient_key( string $token ): string {
		return 'paydarpress_imp_' . get_current_user_id() . '_' . preg_replace( '/[^A-Za-z0-9]/', '', $token );
	}
}
