<?php
/**
 * Builds self-contained prompts for each content task. The same prompt is copied by the user
 * into any chat (manual mode) or sent to the API (automatic mode), so both follow one set of
 * rules and return the same `paydarpress-import/1` JSON.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Ai;

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\KeyPageLinks;
use PaydarWP\PaydarPress\Profile\Fields;
use PaydarWP\PaydarPress\Profile\Industries;
use PaydarWP\PaydarPress\Profile\ProfileRepository;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Tasks: topics, article, rewrite (posts, pages, products), term, meta.
 */
final class PromptBuilder {

	public const TASKS = array( 'topics', 'article', 'rewrite', 'term', 'meta', 'structure', 'merge', 'alt', 'classify', 'cluster', 'slugs' );

	/** Existing items listed for duplicate checks and internal links. */
	private const RELATED_LIMIT = 60;

	/** Longest current content sent for a rewrite (characters of HTML). */
	private const REWRITE_MAX_CHARS = 40000;

	/** Profile fields that are not useful to the writer. */
	private const SKIP_FIELDS = array( 'approved_tags', 'visual_style', 'seo_plugin', 'page_builder', 'woocommerce', 'key_pages', 'cta_options', 'categories_scope', 'competitors' );

	/**
	 * Builds the prompt for a task.
	 *
	 * @param string $task Task.
	 * @param array  $args Task arguments.
	 * @return array{task: string, title: string, system: string, user: string, full: string, command: string, chars: int, object_id: ?int}
	 * @throws RuntimeException On invalid arguments.
	 */
	public static function build( string $task, array $args ): array {
		if ( ! in_array( $task, self::TASKS, true ) ) {
			throw new RuntimeException( 'نوع درخواست شناخته نشد.' );
		}

		$spec   = self::{ 'task_' . $task }( $args );
		$system = self::system( $spec['rules'], $spec['output'] );
		$user   = implode( "\n\n", array_filter( array( "## TASK\n" . $spec['task'], self::site_context( $spec ), $spec['extra'] ?? '' ) ) );

		$full = "# دستور کار تولید محتوا — ساخته‌شده در افزونه پایدارپرس برای سایت «" . self::site_name() . "»\n"
			. "(این متن را کامل در ChatGPT، Claude، Gemini یا هر دستیار دیگری بچسبانید. پاسخ را که یک بلوک JSON است، در پایدارپرس ← «ورود پاسخ هوش مصنوعی» بچسبانید. بخش انگلیسی RULES دستورکار ثابت هوش مصنوعی است و مثال‌های داخل آن فقط نمونه‌اند.)\n\n"
			. $system . "\n\n" . $user;

		return array(
			'task'      => $task,
			'title'     => $spec['title'],
			'system'    => $system,
			'user'      => $user,
			'full'      => $full,
			'command'   => $spec['command'],
			'chars'     => mb_strlen( $full ),
			'object_id' => $spec['object_id'] ?? null,
		);
	}

	/**
	 * Content policy state for new content this month.
	 *
	 * @return array{published: int, budget: int, refresh_waiting: int, over: bool}
	 */
	public static function policy(): array {
		global $wpdb;
		$start  = wp_date( 'Y-m-01 00:00:00' );
		$budget = (int) \PaydarWP\PaydarPress\Core\Settings::get( 'policy.new_per_month', 8 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$published = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','future') AND post_date >= %s", $start ) );
		$stats     = get_option( 'paydarpress_stats', array() );
		return array(
			'published'       => $published,
			'budget'          => $budget,
			'refresh_waiting' => (int) ( is_array( $stats ) ? ( $stats['refresh'] ?? 0 ) : 0 ),
			'over'            => $budget > 0 && $published >= $budget,
		);
	}

	/**
	 * Policy lines for prompts that create new content.
	 */
	private static function policy_text(): string {
		$p = self::policy();
		return "## CONTENT POLICY
- New articles published this month: {$p['published']} of a budget of {$p['budget']}."
			. "
- Existing articles waiting for a refresh: {$p['refresh_waiting']}. Quality over volume: never propose a new article whose intent an existing article could cover after a refresh; say so in `notes` instead."
			. ( $p['over'] ? "
- The monthly budget is used up: only propose what is clearly missing, and mention in `notes` that the owner may prefer refreshing existing content." : '' );
	}

	/**
	 * Visual style and recent image concepts (same category first) to avoid look-alike images.
	 *
	 * @param int $category_id Category.
	 */
	private static function images_text( int $category_id ): string {
		$style = trim( (string) ProfileRepository::value( 'visual_style' ) );
		$args  = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => array( 'publish', 'draft', 'future', 'pending' ),
			'posts_per_page' => 12,
			'meta_key'       => '_paydarpress_image_prompt', // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( $category_id > 0 ) {
			$args['cat'] = $category_id;
		}
		$ids = get_posts( $args );
		if ( $category_id > 0 && count( $ids ) < 6 ) {
			unset( $args['cat'] );
			$ids = array_values( array_unique( array_merge( $ids, get_posts( $args ) ) ) );
		}
		$recent = array();
		foreach ( array_slice( $ids, 0, 12 ) as $id ) {
			$p = self::text( get_post_meta( $id, '_paydarpress_image_prompt', true ), 140 );
			if ( '' !== $p ) {
				$recent[] = '- ' . $p;
			}
		}
		$ratios = 'Aspect ratio: featured image ' . \PaydarWP\PaydarPress\Core\Settings::get( 'images.featured_ratio', '16:9' ) . ', images inside the article ' . \PaydarWP\PaydarPress\Core\Settings::get( 'images.inline_ratio', '4:3' ) . ' (state the ratio in each prompt, e.g. "aspect ratio 16:9").';
		return "## VISUAL STYLE (same for every image of the site; only the subject changes)
" . $ratios . "
"
			. ( '' !== $style ? $style : 'Not set: choose one clean, realistic style that suits the audience; no text in images.' )
			. ( $recent ? "

## RECENT IMAGES (do not repeat these concepts or compositions)
" . implode( "
", $recent ) : '' );
	}

	/**
	 * New topic ideas.
	 *
	 * @param array $args category_id, query, about (title of a page to support), count.
	 */
	private static function task_topics( array $args ): array {
		$count    = max( 3, min( 20, (int) ( $args['count'] ?? 10 ) ) );
		$category = self::category( (int) ( $args['category_id'] ?? 0 ) );
		$query    = self::text( $args['query'] ?? '', 200 );
		$focus    = $category ? 'all in the category «' . $category->name . '»' : 'spread across the categories, weighted toward thin categories and the primary content goal; at most 4 per category';
		$tag      = ! empty( $args['tag_id'] ) ? get_term( (int) $args['tag_id'], 'post_tag' ) : null;
		if ( $tag instanceof WP_Term ) {
			// A kept tag with too few posts: articles whose real topic is this tag.
			$focus = 'each one an article whose main subject really belongs to the tag «' . html_entity_decode( $tag->name, ENT_QUOTES, 'UTF-8' ) . '» (it will get this tag), in the most fitting category';
		}

		$task = "Suggest {$count} NEW article topics for this website, {$focus}.";
		if ( '' !== $query ) {
			$task .= "\nStart from this real Google search that the site already appears for: «{$query}». Include it (or the best article for it) and related intents.";
		}
		$task .= "\nIf a strong idea is already covered by an existing item, skip it.";
		$clusters = \PaydarWP\PaydarPress\Strategy\Clusters::overview_text();
		if ( '' !== $clusters ) {
			$task .= "\n\n## CONTENT CLUSTERS (article groups around one main keyword; fill their gaps first)\n" . $clusters;
		}

		return array(
			'title'       => $tag instanceof WP_Term ? 'پیشنهاد موضوع برای برچسب «' . html_entity_decode( $tag->name, ENT_QUOTES, 'UTF-8' ) . '»' : ( $category ? 'پیشنهاد موضوع برای دسته «' . $category->name . '»' : ( '' !== $query ? 'پیشنهاد موضوع از جست‌وجوی «' . $query . '»' : 'پیشنهاد موضوع تازه' ) ),
			'command'     => $category ? 'موضوع جدید: ' . $category->name : ( '' !== $query ? "موضوع جدید\n(بر اساس جست‌وجوی «{$query}» در سرچ کنسول)" : 'موضوع جدید' ),
			'task'        => $task,
			'subject'     => trim( ( $category ? $category->name : '' ) . ' ' . $query ),
			'category_id' => $category ? (int) $category->term_id : 0,
			'rules'       => self::rules_topics(),
			'output'      => self::output( 'topics' ),
			'with_topics' => true,
			'with_gsc'    => true,
			'with_policy' => true,
			'with_tags'   => true,
		);
	}

	/**
	 * A complete new article.
	 *
	 * @param array $args title, focus_keyword, topic_id, link_to (post id), query, category_id.
	 */
	private static function task_article( array $args ): array {
		$title   = self::text( $args['title'] ?? '', 255 );
		$keyword = self::text( $args['focus_keyword'] ?? '', 255 );
		$query   = self::text( $args['query'] ?? '', 200 );
		$cat     = self::category( (int) ( $args['category_id'] ?? 0 ) );
		$topic   = null;

		if ( ! empty( $args['topic_id'] ) ) {
			global $wpdb;
			$table = Schema::table( 'topics' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$topic = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, focus_keyword, category_id, intent, notes, cluster_id, tags, link_plan FROM {$table} WHERE id = %d", (int) $args['topic_id'] ), ARRAY_A );
			if ( $topic ) {
				$title   = (string) $topic['title'];
				$keyword = (string) $topic['focus_keyword'];
				$cat     = $cat ?: self::category( (int) $topic['category_id'] );
			}
		}

		$link_to = null;
		if ( ! empty( $args['link_to'] ) ) {
			$p       = get_post( (int) $args['link_to'] );
			$link_to = $p instanceof WP_Post && 'publish' === $p->post_status ? $p : null;
		}

		if ( '' !== $title ) {
			$task = "Write the complete article «{$title}»." . ( '' !== $keyword ? " Main keyword: «{$keyword}»." : '' );
		} elseif ( '' !== $query ) {
			$task = "Choose the best title for an article that fully answers this real Google search the site appears for: «{$query}», then write the complete article.";
		} elseif ( $link_to ) {
			$task = 'Find ONE new, non-duplicate topic closely related to «' . self::title( $link_to->ID ) . '» and write the complete article. The article must link naturally, inside a body paragraph, to: ' . self::url( $link_to->ID ) . ' (this page currently gets too few internal links).';
		} else {
			$task = 'Pick the most valuable missing topic for this site and write the complete article.';
		}
		if ( $link_to && '' !== $title . $query ) {
			$task .= "\nThe article must also link naturally to: " . self::url( $link_to->ID );
		}
		if ( $cat ) {
			$task .= "\nCategory: «{$cat->name}».";
		}
		if ( $topic && '' !== (string) $topic['notes'] ) {
			$task .= "\nNotes from the site owner: " . self::text( $topic['notes'], 600 );
		}
		$cluster_text = '';
		if ( $topic ) {
			$tags = json_decode( (string) $topic['tags'], true );
			if ( is_array( $tags ) && $tags ) {
				$task .= "\nPlanned tags (use them unless one clearly does not fit): " . implode( '، ', array_map( 'strval', $tags ) ) . '.';
			}
			$plan = json_decode( (string) $topic['link_plan'], true );
			$urls = array();
			foreach ( is_array( $plan ) ? array_merge( array( $plan['pillar_url'] ?? '', $plan['key_page_url'] ?? '' ), (array) ( $plan['related_urls'] ?? array() ) ) : array() as $u ) {
				$pid = $u ? url_to_postid( (string) $u ) : 0;
				// The plan was made earlier: only addresses that are still published are kept.
				if ( $pid > 0 && 'publish' === get_post_status( $pid ) ) {
					$urls[] = self::url( $pid );
				}
			}
			if ( $urls ) {
				$task .= "\nPlanned internal links (preferred, still valid): " . implode( ' ، ', array_unique( $urls ) ) . '.';
			}
			if ( ! empty( $topic['cluster_id'] ) ) {
				$cluster_text = \PaydarWP\PaydarPress\Strategy\Clusters::prompt_text( (int) $topic['cluster_id'], 0, (int) $topic['id'] );
				if ( '' !== $cluster_text ) {
					$task .= "\nThis article is ONE subtopic of the CONTENT CLUSTER below: cover only this subtopic in depth, do not cover the siblings' subtopics, link once (inside a body paragraph) to the PILLAR and to 1–2 PUBLISHED SIBLINGS where relevant.";
				}
			}
		}
		$task .= "\nIf this topic duplicates an existing item listed below, do NOT write it: return a JSON with an empty `articles` list and explain in `notes` which existing URL to refresh instead.";

		$label = '' !== $title ? $title : ( '' !== $query ? $query : ( $link_to ? self::title( $link_to->ID ) : 'مقاله تازه' ) );

		return array(
			'title'       => $link_to && '' === $title . $query ? 'مقاله جدید با لینک به «' . self::title( $link_to->ID ) . '»' : 'نوشتن مقاله «' . $label . '»',
			'command'     => '' !== $title ? 'مقاله: ' . $title : ( '' !== $query ? 'مقاله آزاد: ' . $query : ( $link_to ? 'مقاله آزاد: یک موضوع جدید و غیرتکراری مرتبط با «' . self::title( $link_to->ID ) . '» پیدا کن و مقاله کامل بنویس که در متن، به‌طور طبیعی به این صفحه لینک بدهد: ' . self::url( $link_to->ID ) : 'مقاله آزاد' ) ),
			'task'        => $task,
			'subject'     => trim( $title . ' ' . $keyword . ' ' . $query . ' ' . ( $link_to ? self::title( $link_to->ID ) : '' ) ),
			'category_id' => $cat ? (int) $cat->term_id : 0,
			'rules'       => self::rules_article(),
			'output'      => self::output( 'article' ),
			'with_gsc'    => true,
			'with_policy' => true,
			'with_images' => true,
			'with_tags'   => true,
			'extra'       => $cluster_text,
			'object_id'   => $link_to ? $link_to->ID : null,
		);
	}

	/**
	 * Rewrite of an existing post, page or product.
	 *
	 * @param array $args post_id, reasons (string[]).
	 * @throws RuntimeException When the post cannot be used.
	 */
	private static function task_rewrite( array $args ): array {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_status, array( 'publish', 'future', 'draft', 'private' ), true ) ) {
			throw new RuntimeException( 'نوشته پیدا نشد.' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new RuntimeException( 'اجازه ویرایش این نوشته را ندارید.' );
		}

		$is_product = 'product' === $post->post_type;
		$reasons    = array_slice( array_filter( array_map( static fn( $r ) => self::text( $r, 200 ), (array) ( $args['reasons'] ?? array() ) ) ), 0, 8 );
		$seo        = self::seo_of( $post->ID );
		$html       = self::clean_html( (string) $post->post_content );
		$cut        = mb_strlen( $html ) > self::REWRITE_MAX_CHARS;
		$html       = mb_substr( $html, 0, self::REWRITE_MAX_CHARS );
		$title      = self::title( $post->ID );
		$url        = self::url( $post->ID );

		$task  = ( $is_product ? 'Rewrite the description of this PRODUCT page' : 'Refresh and rewrite this existing ' . ( 'page' === $post->post_type ? 'page' : 'article' ) ) . ": «{$title}» ({$url}).";
		$task .= "\nKeep what is correct and valuable, fix what is outdated, thin or unclear, answer the reader's real questions first, and improve internal links. Keep the same topic and search intent; do not change the URL.";
		if ( $is_product ) {
			$task .= "\nFor a product: describe real benefits, use cases, specifications already present in the text, care/usage tips and buying questions. Never invent prices, dimensions, materials, warranties or stock information that are not in the current text.";
		}
		if ( $reasons ) {
			$task .= "\nProblems PaydarPress found: " . implode( '; ', $reasons ) . '.';
		}
		$task .= "\nReturn the FULL new " . ( $is_product ? 'description' : 'content' ) . ' (not only the changed parts).';
		$cluster = $is_product ? null : \PaydarWP\PaydarPress\Strategy\Clusters::of_post( $post->ID );
		$cluster_text = $cluster ? \PaydarWP\PaydarPress\Strategy\Clusters::prompt_text( (int) $cluster['id'], $post->ID ) : '';
		if ( $cluster && (int) $cluster['pillar_id'] === $post->ID ) {
			$task .= "\nThis page is the PILLAR of the CONTENT CLUSTER below: give a complete overview of every subtopic in short sections, and link (inside the text) to EVERY published sibling, plus the KEY PAGE if given. Leave the depth of each subtopic to its own article.";
		} elseif ( $cluster ) {
			$task .= "\nThis page is ONE subtopic of the CONTENT CLUSTER below: keep to its subtopic, link once to the PILLAR and to 1–2 siblings where relevant.";
		}

		$tag_tax  = $is_product ? 'product_tag' : ( 'post' === $post->post_type ? 'post_tag' : '' );
		$cur_tags = '' !== $tag_tax && taxonomy_exists( $tag_tax ) ? wp_get_post_terms( $post->ID, $tag_tax, array( 'fields' => 'names' ) ) : array();
		$cur_tags = is_array( $cur_tags ) ? array_map( static fn( $n ) => html_entity_decode( (string) $n, ENT_QUOTES, 'UTF-8' ), $cur_tags ) : array();
		$max_tags = \PaydarWP\PaydarPress\Profile\TaxonomyMap::max_tags( $post->post_type );
		$slug     = rawurldecode( (string) $post->post_name );
		$poor     = \PaydarWP\PaydarPress\Import\ImportService::poor_slug( $slug, $post );
		$cat_id   = $is_product ? (int) ( wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'ids' ) )[0] ?? 0 ) : (int) ( wp_get_post_categories( $post->ID )[0] ?? 0 );

		$extra = "## CURRENT CONTENT\nTitle: {$title}\n"
			. 'Current address (slug): ' . $slug . ( '' === $poor ? " — GOOD: keep it, leave `slug` empty.\n" : " — POOR ({$poor}): give a short English `slug` (3–5 words, lowercase, hyphens) that matches the search intent.\n" )
			. ( '' !== $tag_tax ? 'Current tags (' . count( $cur_tags ) . ", max {$max_tags}): " . ( $cur_tags ? implode( '، ', $cur_tags ) : '(none)' ) . "\n" : '' )
			. ( '' !== $seo['focus_keyword'] ? "Main keyword: {$seo['focus_keyword']}\n" : '' )
			. ( '' !== $seo['title'] ? "SEO title: {$seo['title']}\n" : '' )
			. ( '' !== $seo['meta_description'] ? "Meta description: {$seo['meta_description']}\n" : '' )
			. ( $is_product && '' !== trim( $post->post_excerpt ) ? 'Short description: ' . self::text( wp_strip_all_tags( $post->post_excerpt ), 1000 ) . "\n" : '' )
			. "HTML:\n```html\n{$html}\n```" . ( $cut ? "\n(The content was cut to the first " . self::REWRITE_MAX_CHARS . ' characters.)' : '' )
			. ( '' !== $cluster_text ? "\n\n" . $cluster_text : '' );

		return array(
			'title'       => ( $is_product ? 'بازنویسی توضیحات محصول «' : 'بازنویسی «' ) . $title . '»',
			'command'     => 'بروزرسانی: ' . $url . ( $reasons ? "\nمشکلاتی که پایدارپرس پیدا کرده: " . implode( '، ', $reasons ) : '' ),
			'task'        => $task,
			'subject'     => $title . ' ' . $seo['focus_keyword'],
			'category_id' => $cat_id,
			'exclude'     => array( $post->ID ),
			'rules'       => self::rules_article( $max_tags ) . "\n" . self::rules_rewrite( '' !== $tag_tax, $max_tags ),
			'output'      => self::output( 'rewrite', array( 'url' => $url, 'product' => $is_product ) ),
			'with_images' => ! $is_product,
			'with_tags'   => '' !== $tag_tax,
			'tag_taxonomy' => '' !== $tag_tax ? $tag_tax : 'post_tag',
			'no_categories' => $is_product,
			'product_category' => $is_product ? self::product_category_text( $post->ID ) : '',
			'extra'       => $extra,
			'with_gsc'    => true,
			'object_id'   => $post->ID,
		);
	}

	/**
	 * Description for a category, tag or product category archive.
	 *
	 * @param array $args term_id.
	 * @throws RuntimeException When the term is missing.
	 */
	private static function task_term( array $args ): array {
		$term = get_term( (int) ( $args['term_id'] ?? 0 ) );
		if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, array( 'category', 'post_tag', 'product_cat', 'product_tag' ), true ) ) {
			throw new RuntimeException( 'دسته یا برچسب پیدا نشد.' );
		}
		$tax_label = array(
			'category'    => 'blog category',
			'post_tag'    => 'tag',
			'product_cat' => 'product category',
			'product_tag' => 'product tag',
		)[ $term->taxonomy ];

		$items = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 25,
				'tax_query'      => array( array( 'taxonomy' => $term->taxonomy, 'terms' => $term->term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
			)
		);
		$list = implode( "\n", array_map( static fn( $id ) => '- ' . self::title( $id ) . ' | ' . self::url( $id ), $items ) );
		$link = get_term_link( $term );

		$task = "Write the introduction text shown at the top of the {$tax_label} archive page «{$term->name}»" . ( is_wp_error( $link ) ? '' : " ({$link})" ) . '.'
			. "\n150–300 words in HTML (<p>, optional <h2>/<ul>, a few internal links to the most important items below or to a key page). It must help a visitor understand what this {$tax_label} covers and where to start, and target the search intent of the category name. No filler."
			. ( '' !== trim( (string) $term->description ) ? "\nCurrent description (improve it):\n" . self::text( wp_strip_all_tags( $term->description ), 2000 ) : '' );

		return array(
			'title'       => 'متن معرفی «' . $term->name . '»',
			'command'     => 'متن دسته: ' . $term->name . ( is_wp_error( $link ) ? '' : ' (' . rawurldecode( $link ) . ')' ) . "\nیک متن معرفی ۱۵۰ تا ۳۰۰ کلمه‌ای برای بالای صفحه این دسته بنویس و در «خروجی پایدارپرس» در بخش term_descriptions بده.",
			'task'        => $task,
			'subject'     => $term->name,
			'extra'       => $list ? "## ITEMS IN THIS {$tax_label}\n{$list}" : '',
			'rules'       => self::rules_short(),
			'output'      => self::output( 'term', array( 'taxonomy' => $term->taxonomy, 'term_id' => $term->term_id, 'name' => $term->name ) ),
			'no_related'  => true,
			'object_id'   => null,
		);
	}

	/**
	 * New SEO title and meta description.
	 *
	 * @param array $args post_id, queries (string[]).
	 * @throws RuntimeException When the post is missing.
	 */
	private static function task_meta( array $args ): array {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			throw new RuntimeException( 'نوشته منتشرشده پیدا نشد.' );
		}
		$seo     = self::seo_of( $post->ID );
		$excerpt = self::text( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 1500 );
		$url     = self::url( $post->ID );

		$task = 'This page ranks well in Google but few searchers click it. Write a better SEO title (max 60 characters) and meta description (max 155 characters) that match the searcher\'s intent, promise exactly what the page delivers, and include the main keyword naturally. Give the best version plus 2 alternatives of each in `notes`.'
			. "\nPage: «" . self::title( $post->ID ) . "» ({$url})"
			. ( '' !== $seo['focus_keyword'] ? "\nMain keyword: {$seo['focus_keyword']}" : '' )
			. "\nCurrent SEO title: " . ( '' !== $seo['title'] ? $seo['title'] : '(none — WordPress title is used)' )
			. "\nCurrent meta description: " . ( '' !== $seo['meta_description'] ? $seo['meta_description'] : '(none)' )
			. "\nBeginning of the content: {$excerpt}";

		return array(
			'title'     => 'عنوان و توضیحات متای «' . self::title( $post->ID ) . '»',
			'command'   => "بروزرسانی: {$url}\nفقط عنوان سئو و توضیحات متای تازه بده (رتبه خوب، کلیک کم) و در «خروجی پایدارپرس» در بخش seo_updates قرار بده.",
			'task'      => $task,
			'subject'   => self::title( $post->ID ) . ' ' . $seo['focus_keyword'],
			'rules'     => self::rules_short(),
			'output'    => self::output( 'meta', array( 'url' => $url ) ),
			'no_related' => true,
			'with_gsc'  => true,
			'object_id' => $post->ID,
		);
	}

	/**
	 * Alt texts for existing images, each written from its own place in the article.
	 *
	 * @param array $args post_id (optional; otherwise the posts with the most missing alts).
	 * @throws RuntimeException When there is nothing to do.
	 */
	private static function task_alt( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$items   = \PaydarWP\PaydarPress\Images\AltContext::occurrences( $post_id );
		if ( array() === $items ) {
			throw new RuntimeException( $post_id > 0 ? 'همه تصاویر این نوشته متن جایگزین مناسب دارند.' : 'تصویری بدون متن جایگزین پیدا نشد. اگر به‌تازگی تصویر اضافه کرده‌اید، اسکن را دوباره اجرا کنید.' );
		}

		$lines = array();
		$last  = 0;
		foreach ( $items as $it ) {
			if ( $it['post_id'] !== $last ) {
				$seo     = self::seo_of( $it['post_id'] );
				$lines[] = "\n### ARTICLE «{$it['post_title']}» (" . self::url( $it['post_id'] ) . ')' . ( '' !== $seo['focus_keyword'] ? " — focus keyword: {$seo['focus_keyword']}" : '' );
				$last    = $it['post_id'];
			}
			$row = array( "- ref: {$it['ref']}", 'featured' === $it['kind'] ? '  place: FEATURED IMAGE (shown at the top and when shared; context = the whole article)' : '  place: inside the article' );
			if ( '' !== $it['heading'] ) {
				$row[] = "  section heading: {$it['heading']}";
			}
			if ( '' !== $it['before'] ) {
				$row[] = "  text before: …{$it['before']}";
			}
			if ( '' !== $it['after'] ) {
				$row[] = '  text after: ' . $it['after'] . '…';
			}
			if ( '' !== $it['caption'] ) {
				$row[] = "  caption: {$it['caption']}";
			}
			$row[] = '  file name: ' . $it['file'] . ( '' !== $it['current_alt'] ? " (current alt, not useful: «{$it['current_alt']}»)" : '' );
			$lines[] = implode( "\n", $row );
		}

		$one   = $post_id > 0 ? self::title( $post_id ) : '';
		$title = '' !== $one ? 'متن جایگزین تصاویر «' . $one . '»' : 'متن جایگزین ' . PersianNormalizer::fa_number( count( $items ) ) . ' تصویر بدون alt';

		return array(
			'title'      => $title,
			'command'    => ( '' !== $one ? 'متن جایگزین تصاویر: ' . self::url( $post_id ) : 'متن جایگزین تصاویر بدون alt' ) . "\nبرای هر تصویر بر اساس جای آن در مقاله (سرتیتر، متن قبل و بعد) alt بنویس و در «خروجی پایدارپرس» در بخش image_alts قرار بده.",
			'task'       => 'Write the alt text of each image listed below. You cannot see the images: infer what each one most likely shows from WHERE it is (section heading, the text right before and after it, caption, file name) and what the reader needs to understand at that point. Describe a concrete scene, object or document, never just the topic name. If the context really says nothing about an image, write a short neutral description based on the section topic rather than guessing details.'
				. "\n" . self::alt_rules()
				. "\n\n## IMAGES" . implode( "\n", $lines ),
			'subject'    => $one,
			'rules'      => self::rules_short(),
			'output'     => self::output( 'alt' ),
			'no_related' => true,
			'minimal'    => true,
			'object_id'  => $post_id > 0 ? $post_id : null,
		);
	}

	/**
	 * Cluster map: a pillar and its subtopics around one main keyword, mapped to existing
	 * articles where they already exist.
	 *
	 * @param array $args cluster_id, or keyword + category_id.
	 * @throws RuntimeException Without a keyword.
	 */
	private static function task_cluster( array $args ): array {
		$cluster = ! empty( $args['cluster_id'] ) ? \PaydarWP\PaydarPress\Strategy\Clusters::get( (int) $args['cluster_id'] ) : null;
		$keyword = $cluster ? ( '' !== $cluster['main_keyword'] ? $cluster['main_keyword'] : $cluster['name'] ) : self::text( $args['keyword'] ?? '', 200 );
		if ( '' === $keyword ) {
			throw new RuntimeException( 'کلمه کلیدی اصلی خوشه را بنویسید.' );
		}
		$cat  = self::category( (int) ( $args['category_id'] ?? ( $cluster['category_id'] ?? 0 ) ) );
		$task = "Build a CONTENT CLUSTER (articles only) around the main keyword «{$keyword}»" . ( $cat ? " in the category «{$cat->name}»" : '' ) . ".\n"
			. "- One PILLAR: a complete guide to the main keyword that gives an overview of every subtopic. Use an EXISTING article as the pillar when one already is (or is close to) that guide (pillar_url); otherwise give pillar_title for a new one.\n"
			. "- 8–15 SUBTOPICS: each one distinct search intent people really have about «{$keyword}» (cost, steps, documents, mistakes, comparisons, special cases, questions…), deep enough for its own article, never overlapping each other.\n"
			. "- Map EXISTING CONTENT: when an existing article already covers a subtopic, put its URL in existing_url (do not propose a new article for it). Only propose new subtopics for real gaps.\n"
			. "- Tags from TAGS (exact names), 1–3 per subtopic.";
		if ( $cluster ) {
			$task .= "\n\nThe cluster already exists (name «{$cluster['name']}»); keep its name and complete it:\n" . \PaydarWP\PaydarPress\Strategy\Clusters::prompt_text( (int) $cluster['id'] );
		}
		return array(
			'title'       => 'نقشه خوشه «' . ( $cluster ? $cluster['name'] : $keyword ) . '»',
			'command'     => '',
			'task'        => $task,
			'subject'     => $keyword,
			'category_id' => $cat ? (int) $cat->term_id : 0,
			'rules'       => self::rules_topics(),
			'output'      => self::output( 'cluster' ),
			'with_topics' => true,
			'with_gsc'    => true,
			'with_tags'   => true,
			'object_id'   => $cluster ? (int) $cluster['id'] : null,
		);
	}

	/**
	 * English slugs for pages whose address is Persian, numeric or a "-2" duplicate.
	 *
	 * @param array $args ids (optional; default: the next 40 that need one).
	 * @throws RuntimeException When nothing needs a slug.
	 */
	private static function task_slugs( array $args ): array {
		$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $args['ids'] ?? '' ) ) ) ) );
		if ( ! $ids ) {
			$ids = \PaydarWP\PaydarPress\Improve\SlugProgram::state()['needs_slug'];
		}
		$ids = array_slice( $ids, 0, 40 );
		if ( ! $ids ) {
			throw new RuntimeException( 'آدرسی منتظر نامک انگلیسی نیست.' );
		}
		$lines = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$seo     = self::seo_of( $id );
			$lines[] = '- id ' . $id . ' | ' . self::title( $id ) . ( '' !== $seo['focus_keyword'] ? ' | keyword: ' . $seo['focus_keyword'] : '' ) . ' | ' . $post->post_type;
		}
		$task = "Give a short English URL slug for each page below.\n"
			. "- 2 to 5 words, lowercase latin letters, digits and hyphens only; no stop words (the, of, and, a), no years, no brand or site name.\n"
			. "- Describe what people search for (the main topic or product type), not a word-by-word translation of the title. Keep product model codes (e.g. r21, k13).\n"
			. "- Every slug must be different.\n\n## PAGES\n" . implode( "\n", $lines );
		return array(
			'title'      => 'نامک انگلیسی برای ' . PersianNormalizer::fa_number( count( $lines ) ) . ' آدرس',
			'command'    => '',
			'task'       => $task,
			'subject'    => '',
			'rules'      => self::rules_short(),
			'output'     => self::output( 'slugs' ),
			'no_related' => true,
			'minimal'    => true,
			'object_id'  => null,
		);
	}

	/**
	 * Categories and tags for existing posts or products (add only).
	 *
	 * @param array $args ids (comma-separated) or kind + scope (next chunk of candidates).
	 * @throws RuntimeException When there is nothing to classify.
	 */
	private static function task_classify( array $args ): array {
		$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $args['ids'] ?? '' ) ) ) ) );
		if ( ! $ids ) {
			$ids = array_slice( \PaydarWP\PaydarPress\Strategy\Classifier::candidates( (string) ( $args['kind'] ?? 'post' ), (string) ( $args['scope'] ?? 'weak' ) ), 0, \PaydarWP\PaydarPress\Strategy\Classifier::CHUNK );
		}
		$ids = array_slice( $ids, 0, \PaydarWP\PaydarPress\Strategy\Classifier::CHUNK );
		if ( ! $ids ) {
			throw new RuntimeException( 'مطلبی برای طبقه‌بندی نمانده است.' );
		}
		$kind  = 'product' === get_post_type( $ids[0] ) ? 'product' : 'post';
		$label = 'product' === $kind ? 'محصول' : 'مقاله';
		$cleanup = 'cleanup' === ( $args['scope'] ?? '' );
		$max   = \PaydarWP\PaydarPress\Profile\TaxonomyMap::max_tags( $kind );

		$task = 'Choose better categories and tags for each existing ' . ( 'product' === $kind ? 'product' : 'article' ) . " listed below. Read its title, headings and beginning, then:\n"
			. "- add_categories: categories from the CATEGORIES list (exact names) that clearly fit its main subject and are missing. Usually 0 or 1; never more than 2. Use the «covers» notes to decide.\n"
			. "- primary_category: only when the item's current main category is clearly wrong (or it has none / only the default one); otherwise empty.\n"
			. "- add_tags: tags from EXISTING TAGS (exact names) that describe real topics of the item and are missing. The item may have at most {$max} tags in total including its current ones.\n"
			. "- new_tags: at most ONE new tag per item, and only if no existing tag fits: a general, reusable term people actually search for (not a sentence, not the title, not a plural, synonym or near-duplicate of an existing tag or a category). Prefer none.\n"
			. "- misfit: current categories or tags of the item that clearly do not fit it (they will be reported to the owner; nothing is removed).\n"
			. ( 'cleanup' === ( $args['scope'] ?? '' ) ? "- remove_tags: THIS IS A TAG CLEAN-UP. List current tags of the item that do not describe it (wrong product type, colour or material it does not have, temporary sale words, duplicates of another tag, the brand or site name), each with a short reason in the site language. Keep every tag that is a real topic of the item. The item should end with at most {$max} tags.\n" : '' )
			. 'Be conservative: when unsure, add nothing. Never invent category names.'
			. "\n\n" . \PaydarWP\PaydarPress\Strategy\Classifier::terms_text( $kind )
			. "\n\n## ITEMS\n" . \PaydarWP\PaydarPress\Strategy\Classifier::items_text( $ids );

		return array(
			'title'      => 'طبقه‌بندی ' . PersianNormalizer::fa_number( count( $ids ) ) . ' ' . $label,
			'command'    => '',
			'task'       => $task,
			'subject'    => '',
			'rules'      => self::rules_short(),
			'output'     => self::output( 'classify', array( 'cleanup' => $cleanup ) ),
			'no_related' => true,
			'minimal'    => true,
			'object_id'  => null,
		);
	}

	/**
	 * Merge: one rewrite of the article that stays, absorbing what is valuable in the other.
	 *
	 * @param array $args from, into.
	 * @throws RuntimeException When an article is missing.
	 */
	private static function task_merge( array $args ): array {
		$from = get_post( (int) ( $args['from'] ?? 0 ) );
		$into = (int) ( $args['into'] ?? 0 );
		if ( ! $from instanceof WP_Post ) {
			throw new RuntimeException( 'مقاله‌ای که باید ادغام شود پیدا نشد.' );
		}
		$spec           = self::task_rewrite( array( 'post_id' => $into, 'reasons' => array( 'merge' ) ) );
		$html           = mb_substr( self::clean_html( (string) $from->post_content ), 0, self::REWRITE_MAX_CHARS );
		$spec['title']  = 'ادغام «' . self::title( $from->ID ) . '» در «' . self::title( $into ) . '»';
		$spec['command'] = 'ادغام: ' . self::url( $from->ID ) . ' در ' . self::url( $into ) . "\nیک نسخه کامل از مقاله دوم بنویس که بخش‌های ارزشمند و غیرتکراری مقاله اول را هم داشته باشد؛ در «خروجی پایدارپرس» با rewrite_of مقاله دوم.";
		$spec['task']   = 'MERGE two articles with the same search intent into ONE stronger article. The article that stays (its URL is kept): «' . self::title( $into ) . '» (' . self::url( $into ) . "). The article being retired (it will redirect 301 to the one that stays): «" . self::title( $from->ID ) . '» (' . self::url( $from->ID ) . ").\nWrite the FULL new version of the article that stays: keep its main keyword and intent, bring in every valuable, non-duplicate section, example, FAQ and fact from the retired article, remove repetition, and do not link to the retired article.";
		$spec['extra'] .= "\n\n## ARTICLE BEING RETIRED (merge its valuable parts in)\nTitle: " . self::title( $from->ID ) . "\nHTML:\n```html\n{$html}\n```";
		$spec['exclude'][] = $from->ID;
		return $spec;
	}

	/**
	 * Review of the category structure (suggestions only; the owner decides).
	 *
	 * @param array $args Unused.
	 */
	private static function task_structure( array $args ): array {
		unset( $args );
		$lines = array();
		foreach ( \PaydarWP\PaydarPress\Strategy\Structure::categories() as $c ) {
			$titles  = get_posts(
				array(
					'cat'            => $c['id'],
					'posts_per_page' => 8,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$lines[] = '- [category #' . $c['id'] . '] ' . $c['name'] . ' | ' . $c['count'] . ' posts | role ' . self::role_word( $c['importance'] ) . ' | Google impressions ' . $c['impressions']
				. ( $titles ? "\n  sample titles: " . implode( ' / ', array_map( array( self::class, 'title' ), $titles ) ) : '' );
		}
		if ( \PaydarWP\PaydarPress\Strategy\ShopStrategy::active() ) {
			foreach ( (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 150 ) ) as $t ) {
				if ( $t instanceof WP_Term && 'uncategorized' !== $t->slug ) {
					$parent  = $t->parent ? get_term( $t->parent ) : null;
					$sample  = get_posts(
						array(
							'post_type'      => 'product',
							'posts_per_page' => 5,
							'fields'         => 'ids',
							'no_found_rows'  => true,
							'tax_query'      => array( array( 'taxonomy' => 'product_cat', 'terms' => $t->term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
						)
					);
					$lines[] = '- [product_cat #' . $t->term_id . '] ' . $t->name . ' | ' . $t->count . ' products' . ( $parent instanceof WP_Term ? ' | parent: ' . $parent->name : '' )
						. ( $sample ? "\n  sample products: " . implode( ' / ', array_map( array( self::class, 'title' ), $sample ) ) : '' );
				}
			}
		}
		$tags = wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );

		$task = "Review this site's category structure and suggest ONLY the changes that clearly help readers and search: categories to merge (overlapping intent), split (too broad, covering several distinct intents), create (a main keyword or service with no home), rename (unclear name — the display name only), noindex (thin archives that should not be in Google), or keep.\n"
			. "Be conservative: a stable structure is valuable; every change costs work and can lose rankings. At most 12 suggestions, most important first. Never suggest changing a category's URL slug. A merge always means moving the posts and a 301 redirect from the old category.\n"
			. 'Tags on this site: ' . ( is_wp_error( $tags ) ? 0 : (int) $tags ) . ".\n\n## CURRENT STRUCTURE\n" . implode( "\n", $lines );

		return array(
			'title'      => 'پیشنهاد ساختار دسته‌بندی',
			'command'    => "بررسی ساختار دسته‌بندی سایت\nفقط تغییرات لازم (ادغام، تقسیم، دسته تازه، تغییر نام نمایشی، noindex) را با دلیل بده و در «خروجی پایدارپرس» در بخش structure_suggestions قرار بده.",
			'task'       => $task,
			'subject'    => '',
			'rules'      => self::rules_short() . "\n- Base every suggestion on the profile (main keywords, services, audience), the sample titles and the Google data; say which in `reason`.",
			'output'     => self::output( 'structure' ),
			'no_related' => true,
			'with_gsc'   => true,
			'object_id'  => null,
		);
	}

	/**
	 * Fixed instructions.
	 *
	 * @param string $rules  Task rules.
	 * @param string $output Output format.
	 */
	private static function system( string $rules, string $output ): string {
		$lang = (string) ProfileRepository::value( 'language' );
		$lang = '' !== $lang ? $lang : get_locale();

		return "You are a senior SEO content strategist and writer working for the website described below. Write for real readers first, in the site's language ({$lang}; Persian sites: natural, fluent Persian with correct half-spaces, no machine-translated phrasing). Follow the SITE PROFILE exactly (audience, tone, rules, forbidden topics, glossary).\n\n"
			. "## RULES\n{$rules}\n\n## OUTPUT FORMAT\n{$output}";
	}

	/**
	 * Rules for topic ideas (condensed from ContentMaster sections 7.2–7.5).
	 */
	private static function rules_topics(): string {
		return implode(
			"\n",
			array(
				'- If you can browse the web, research what this audience really searches and asks (related searches, People Also Ask, forums, competitors\' gaps). Never invent search volumes.',
				'- Every topic belongs to exactly one category from CATEGORIES (use the exact name).',
				'- Reject any topic with the same primary search intent as an EXISTING CONTENT item or a PLANNED/REJECTED TOPIC, even with different wording. When in doubt, reject it.',
				'- Prefer topics that match real search phrasing (see the profile\'s keyword patterns and SEARCH CONSOLE queries), have enough depth for a useful article, sit close to a conversion, and can naturally link to a KEY PAGE.',
				'- Cover different intents and angles (how-to, cost, comparison, mistakes, checklist, definition, situations), not variations of one idea.',
				'- Titles: natural to native readers, main keyword near the start, numbers only for real lists, no clickbait or stuffing.',
				'- Respect forbidden topics and industry rules.',
				'- Plan each topic completely: `category` (exact), `tags` (1–' . \PaydarWP\PaydarPress\Profile\TaxonomyMap::MAX_TAGS . ' from TAGS, exact names), `cluster` (the exact name of a CONTENT CLUSTER it belongs to, or empty), and `link_plan`: `pillar_url` (the cluster\'s pillar, if any), `key_page_url` (the KEY PAGE it should support, if any) and `related_urls` (2–3 EXISTING CONTENT URLs it should link to). Only use URLs listed in this prompt.',
			)
		);
	}

	/**
	 * Rules for writing an article (condensed from ContentMaster sections 8.1–8.6 and 6.5).
	 */
	private static function rules_article( int $max_tags = 0 ): string {
		$max_tags = $max_tags > 0 ? $max_tags : \PaydarWP\PaydarPress\Profile\TaxonomyMap::MAX_TAGS;
		$max = KeyPageLinks::MAX_PER_ARTICLE;

		return implode(
			"\n",
			array(
				'- Plan internally first (do not output the plan): main question, 3–7 real sub-questions the reader has, what they must be able to do after reading, common worries, the logical next step. Cover all of them.',
				'- Pick ONE dominant approach that fits the topic (step-by-step, comparison, mistakes, scenarios, question-led, examples…). Do not use a fixed template.',
				'- Answer first: after at most one sentence of context, give a direct 40–70 word answer to the main question. Each section also starts with its answer, then details.',
				'- Headings phrase the reader\'s real questions. Human, specific, no filler intros, no emojis.',
				'- Never fabricate facts, statistics, laws, prices or quotes. For YMYL topics (legal, medical, financial) be precise and cautious; if unsure, use a safe accurate general statement and recommend consulting the professional.',
				'- Length follows the topic\'s real depth; value over word count.',
				"- Internal links: 3–6 links inside body paragraphs (not a list at the end). At least 1 and at most {$max} links to KEY PAGES whose 'link from categories' include this article's category (or all). 1–3 links to related EXISTING CONTENT. Link each URL once. Only use URLs listed in this prompt; never invent URLs.",
				'- Anchor text: descriptive, 2–6 words, varied. For key pages use their allowed anchors; keep exact-match anchors (anchor = the page\'s main keyword) rare, and never use exact-match for a page marked over_cap. Never use another article\'s focus keyword as the anchor to a different page. Never «اینجا کلیک کنید».',
				'- Conclusion: natural, with ONE call to action from CTA OPTIONS framed as the helpful next step.',
				'- HTML: clean WordPress HTML only: <h2>, <h3>, <p>, <ul>, <ol>, <table> when useful, <a href>, <strong> sparingly. No <h1>, CSS, scripts or Markdown. Optional boxes: <div class="tip-box">, <div class="note-box">, <div class="mistake-box">, <div class="example-box">.',
				'- FAQ: 3–6 real follow-up questions go ONLY in `faq` (short, direct answers, plain text). Do NOT write a FAQ section inside `html`; the plugin adds it at the end of the article as an accordion with structured data.',
				'- SEO: title ≤ 60 characters, meta description ≤ 155 characters, ' . ( 'persian' === \PaydarWP\PaydarPress\Core\Settings::get( 'policy.slug_style', 'english' ) ? 'slug: leave empty (the site uses Persian addresses from the title)' : 'slug: short English, 3–5 words, lowercase with hyphens, based on the search intent (not a translation of the whole title)' ) . ', tags by the TAGS rule.',
				'- TAGS: 1–' . $max_tags . ' tags (never more than ' . $max_tags . '), each one a real topic of this article, chosen from the TAGS list (exact names). Only if no listed tag fits, you may add at most ONE new tag: a general, reusable term people actually search for (not a sentence, not the article title, not a synonym or plural of a listed tag or category). Never use a category name as a tag.',
				'- ' . \PaydarWP\PaydarPress\Images\InlinePlan::prompt_rule() . ' Each with `after_heading` (the exact H2/H3 text it follows), an English `prompt` in the VISUAL STYLE, `alt` in the site language and `why` (one line).',
				'- ' . self::alt_rules(),
				'- reverse_links: 2–3 EXISTING ARTICLES (blog posts, never products or shop pages) that should link to this new article, with an anchor that already appears in that article and the sentence where it fits.',
				'- featured_image: a UNIQUE image brief for THIS article. `prompt` in English: one concrete scene taken from the article\'s own situation (who, where, what is happening, one telling detail), in the VISUAL STYLE below; vary subject, setting, angle and composition; never a generic stock concept (handshake, gavel on desk, laptop on table, person thinking) and never one of the RECENT IMAGES listed; no text, logos or watermarks. `alt` follows the ALT TEXT rule above, using the article\'s main topic as its context.',
			)
		);
	}

	/**
	 * Alt text examples per industry (vague → specific), so the example matches the site.
	 */
	private const ALT_EXAMPLES = array(
		'ecommerce-retail'      => array( 'کتاب', 'جلد کتاب داستان کودک روی قفسه کتابخانه اتاق کودک' ),
		'product-manufacturing' => array( 'جاکفشی', 'جاکفشی آینه‌دار سفید کنار در ورودی آپارتمان' ),
		'b2b-industrial'        => array( 'دستگاه', 'دستگاه پرس هیدرولیک در خط تولید کارخانه قطعات خودرو' ),
		'legal'                 => array( 'قرارداد', 'امضای قرارداد اجاره توسط مستأجر و موجر در دفتر املاک' ),
		'medical-health'        => array( 'دندان', 'معاینه دندان کودک روی صندلی دندان‌پزشکی' ),
		'beauty-wellness'       => array( 'مو', 'رنگ کردن موی بلند با تکنیک بالیاژ در سالن زیبایی' ),
		'finance-insurance'     => array( 'فرم', 'تکمیل فرم درخواست بیمه درمان تکمیلی برای خانواده' ),
		'real-estate'           => array( 'آپارتمان', 'نمای پذیرایی آپارتمان ۹۰ متری با پنجره رو به حیاط' ),
		'construction-home'     => array( 'کاشی', 'نصب کاشی دیواری حمام با تراز لیزری' ),
		'local-services'        => array( 'تعمیر', 'تعویض لوله شیر آب زیر سینک آشپزخانه توسط لوله‌کش' ),
		'service-directory'     => array( 'نقشه', 'نقشه محل شعبه‌های فروشنده در شهرک صنعتی' ),
		'education'             => array( 'کلاس', 'دانش‌آموزان در حال حل تمرین ریاضی پای تخته کلاس' ),
		'language-learning'     => array( 'کتاب', 'زبان‌آموز در حال تمرین مکالمه انگلیسی با معلم آنلاین' ),
		'technology-software'   => array( 'نرم‌افزار', 'صفحه گزارش فروش ماهانه در داشبورد نرم‌افزار حسابداری' ),
		'tourism-travel'        => array( 'هتل', 'نمای استخر هتل ساحلی در کیش هنگام غروب' ),
		'food-restaurant'       => array( 'غذا', 'بشقاب کباب کوبیده با برنج زعفرانی و گوجه کبابی' ),
		'automotive'            => array( 'ماشین', 'تعویض روغن موتور خودرو روی جک در تعمیرگاه' ),
		'media-blog'            => array( 'لپ‌تاپ', 'نویسنده در حال ویرایش مقاله روی لپ‌تاپ در کافه' ),
	);

	/**
	 * «not X but Y» example in the site's own field.
	 */
	private static function alt_example(): string {
		$module = (string) \PaydarWP\PaydarPress\Profile\ProfileRepository::value( 'industry_module' );
		$pair   = self::ALT_EXAMPLES[ $module ] ?? null;
		if ( null === $pair ) {
			// Unknown field: an everyday household scene that cannot be mistaken for the site's topic.
			$pair = array( 'میز', 'میز تحریر چوبی کنار پنجره در اتاق کار خانگی' );
		}
		return 'e.g. not «' . $pair[0] . '» but «' . $pair[1] . '»';
	}

	/**
	 * Alt text rule shared by articles and the alt task.
	 */
	public static function alt_rules(): string {
		return 'ALT TEXT (every image): written in the site language for THIS image in THIS place. Describe what the image actually shows, in the context of the section it sits in: use the nearest heading and the sentences right before and after it to say what the picture means to the reader there (' . self::alt_example() . '; the example only shows the form — never use its subject). 5–15 words, at most 125 characters, one plain sentence without a final period. Mention the section\'s topic naturally; use the article\'s focus keyword in at most ONE image of the article and only if it truly describes the picture. Never start with «تصویر», «عکس» or «image of», never use the file name, never repeat the same alt for two images, no keyword lists, no promotional words.';
	}

	/**
	 * Extra rules for rewrites.
	 */
	private static function rules_rewrite( bool $tags = false, int $max = 5 ): string {
		return implode(
			"\n",
			array_filter( array(
				$tags ? "- Tags of a rewrite: `tags` = the full list the item should have afterwards (current tags that still fit + missing ones from the TAGS list, exact names; at most {$max}). List in `tags_remove` the current tags that do not fit, each with a short reason in the site language: [{\"name\": \"\", \"reason\": \"\"}]. Never remove a tag that describes the item. An empty `tags` list means no change." : '',
				'- Address: keep the current slug when it is marked GOOD; never invent a new address for a good one.',
				'- This is a REWRITE: keep the URL and the main search intent; keep correct facts, useful examples and existing good internal links; remove outdated years/figures unless still true.',
				'- Do not count existing links to key pages as extra: the whole new content must respect the key-page limit.',
				'- `reverse_links` are optional for a rewrite.',
				'- Images: keep every existing <img> of the current content that still fits (same src) in its section, and fix its alt by the ALT TEXT rule. Count existing images toward the inline image number; only add `inline_images` for what is still missing, and if there are already more images than the rule allows, keep the most useful ones.',
			) )
		);
	}

	/**
	 * Rules for short texts (term descriptions, meta).
	 */
	private static function rules_short(): string {
		return implode(
			"\n",
			array(
				'- Match the searcher\'s intent and the site\'s tone; specific, no filler, no clickbait, no fabricated facts or prices.',
				'- Only use URLs listed in this prompt.',
			)
		);
	}

	/**
	 * Output format per task: always ONE json code block in paydarpress-import/1.
	 *
	 * @param string $task Task.
	 * @param array  $ctx  Values to pre-fill.
	 */
	private static function output( string $task, array $ctx = array() ): string {
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$head = "Reply with ONE ```json code block and nothing else (no text before or after). It must be valid JSON (escape quotes and newlines inside strings). Start with:\n{\"schema\": \"paydarpress-import/1\", \"generator\": \"<your model name>\", \"site\": \"{$site}\", …}\n";

		$article = '{"title": "", "slug": "", "html": "<full article HTML>", "category": "<exact category name>", "tags": [], "seo": {"title": "", "meta_description": "", "focus_keyword": ""}, "excerpt": "", "featured_image": {"prompt": "", "alt": ""}, "inline_images": [{"after_heading": "", "prompt": "", "alt": "", "why": ""}], "faq": [{"q": "", "a": ""}], "reverse_links": [{"source_url": "", "anchor": "", "sentence": ""}]}';

		switch ( $task ) {
			case 'topics':
				return $head . 'with "topics": [{"title": "", "category": "<exact category name>", "focus_keyword": "", "intent": "informational|educational|how-to|commercial|support|transactional", "tags": ["<exact tag>"], "cluster": "<exact cluster name or empty>", "link_plan": {"pillar_url": "", "key_page_url": "", "related_urls": [""]}, "notes": "<one line: why this topic, which key page it can link to>"}]';
			case 'cluster':
				return $head . 'with "cluster_plan": {"name": "<short cluster name>", "main_keyword": "", "category": "<exact category name>", "pillar_url": "<URL of an EXISTING article that should be the pillar, or empty>", "pillar_title": "<title of a NEW pillar article when no existing one fits, else empty>", "subtopics": [{"title": "", "focus_keyword": "", "intent": "informational|educational|how-to|commercial|support|transactional", "existing_url": "<URL of an EXISTING article that already covers this subtopic, or empty>", "tags": ["<exact tag>"], "notes": ""}]} and "notes": "".';
			case 'article':
				return $head . "with \"articles\": [ {$article} ] and optionally \"notes\": \"\".";
			case 'rewrite':
				$rw = preg_replace( '/^\{"title"/', '{"rewrite_of": "' . $ctx['url'] . '", "title"', str_replace( '"tags": [],', '"tags": [], "tags_remove": [{"name": "", "reason": ""}],', $article ), 1 );
				return $head . 'with "articles": [ ' . $rw . ' ] and optionally "notes": "<what changed, in the site language>".'
					. ( empty( $ctx['product'] ) ? '' : ' For a product, `html` is the full product description and `excerpt` is REQUIRED: the new short description shown next to the price (2–4 short bullet points or 2 sentences; simple HTML allowed: <p>, <ul>, <li>, <strong>); leave `category` empty (product categories do not change).' );
			case 'term':
				return $head . 'with "term_descriptions": [{"taxonomy": "' . $ctx['taxonomy'] . '", "term_id": ' . (int) $ctx['term_id'] . ', "name": ' . wp_json_encode( $ctx['name'], JSON_UNESCAPED_UNICODE ) . ', "description": "<HTML>"}]';
			case 'structure':
				return $head . 'with "structure_suggestions": [{"action": "merge|split|create|rename|noindex|keep", "taxonomy": "category|product_cat", "term_id": 0, "name": "<current name>", "new_name": "<new display name, for create/rename/split>", "into_term_id": 0, "into_name": "<target category, for merge>", "reason": "<one or two sentences in the site language>", "priority": "high|medium|low"}] and "notes": "<short overall assessment in the site language>".';
			case 'classify':
				return $head . 'with "classifications": [{"id": <ITEM id>, "add_categories": ["<exact category name>"], "primary_category": "<exact name, only when the current main category is clearly wrong, else empty>", "add_tags": ["<exact existing tag>"], "new_tags": ["<new tag, rarely>"], ' . ( empty( $ctx['cleanup'] ) ? '' : '"remove_tags": [{"tag": "<a CURRENT tag that does not fit>", "reason": "<short, site language>"}], ' ) . '"misfit": [{"term": "<a CURRENT category or tag of the item that clearly does not fit>", "reason": "<short, site language>"}]}] — one entry for EVERY item listed, even when nothing changes (then empty lists).';
			case 'slugs':
				return $head . 'with "slugs": [{"id": <page id>, "slug": "<english-slug>"}] — one entry for EVERY page listed.';
			case 'alt':
				return $head . 'with "image_alts": [{"ref": "<ref exactly as listed>", "alt": "<alt text>"}] — one item for EVERY image listed, in the same order.';
			case 'meta':
				return $head . 'with "seo_updates": [{"url": "' . $ctx['url'] . '", "seo_title": "", "meta_description": ""}] and "notes": "<2 alternative titles and 2 alternative descriptions>".';
		}
		return $head;
	}

	/**
	 * Site profile, key pages, CTAs, categories, existing content, topics and Search Console data.
	 *
	 * @param array $spec Task spec.
	 */
	private static function site_context( array $spec ): string {
		$parts = array( "## SITE PROFILE\n" . self::profile_text() );
		if ( ! empty( $spec['minimal'] ) ) {
			return implode( "\n\n", $parts );
		}

		$key = self::key_pages_text();
		if ( '' !== $key ) {
			$parts[] = "## KEY PAGES (link targets; at most " . KeyPageLinks::MAX_PER_ARTICLE . " per article)\n{$key}";
		}
		$cta = self::cta_text();
		if ( '' !== $cta ) {
			$parts[] = "## CTA OPTIONS\n{$cta}";
		}
		if ( empty( $spec['no_categories'] ) ) {
			$parts[] = "## CATEGORIES\n" . self::categories_text();
		}
		if ( ! empty( $spec['product_category'] ) ) {
			$parts[] = "## PRODUCT CATEGORY (this product's category page: the most useful internal link from its description)\n" . $spec['product_category'];
		}
		$shop    = \PaydarWP\PaydarPress\Strategy\ShopStrategy::info();
		if ( ! empty( $shop['active'] ) ) {
			$parts[] = "## SHOP STRATEGY\nFocus: {$shop['focus']} — " . $shop['reason'] . ' ' . $shop['advice'];
		}
		if ( ! empty( $spec['with_policy'] ) ) {
			$parts[] = self::policy_text();
		}
		if ( ! empty( $spec['with_images'] ) ) {
			$parts[] = self::images_text( (int) ( $spec['category_id'] ?? 0 ) );
		}
		if ( ! empty( $spec['with_tags'] ) ) {
			$tags = \PaydarWP\PaydarPress\Profile\TaxonomyMap::allowed_tags( (int) ( $spec['category_id'] ?? 0 ), 40, (string) ( $spec['tag_taxonomy'] ?? 'post_tag' ) );
			if ( $tags ) {
				$parts[] = "## TAGS (existing tags you may use; the most relevant first)\n" . implode( "\n", array_map( static fn( $t ) => '- ' . $t['name'] . ( '' !== $t['scope'] ? ' — ' . $t['scope'] : '' ), $tags ) );
			}
		}

		if ( empty( $spec['no_related'] ) ) {
			$related = self::related_text( (string) ( $spec['subject'] ?? '' ), (int) ( $spec['category_id'] ?? 0 ), (array) ( $spec['exclude'] ?? array() ) );
			if ( '' !== $related ) {
				$parts[] = "## EXISTING CONTENT (title | URL | main keyword) — the most related items; use for duplicate checks and internal links\n{$related}";
			}
		}
		if ( ! empty( $spec['with_topics'] ) ) {
			$topics = self::topics_text();
			if ( '' !== $topics ) {
				$parts[] = "## PLANNED / REJECTED TOPICS (do not repeat)\n{$topics}";
			}
		}
		if ( ! empty( $spec['with_gsc'] ) ) {
			$gsc = self::gsc_text( (string) ( $spec['subject'] ?? '' ) );
			if ( '' !== $gsc ) {
				$parts[] = "## SEARCH CONSOLE QUERIES (real searches the site appears for: query | impressions | avg position)\n{$gsc}";
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Profile fields as "Label: value" lines.
	 */
	private static function profile_text(): string {
		$profile = ProfileRepository::get();
		$lines   = array();
		foreach ( Fields::all() as $name => $def ) {
			if ( in_array( $name, self::SKIP_FIELDS, true ) ) {
				continue;
			}
			$value = $profile['fields'][ $name ]['value'] ?? null;
			if ( 'industry_module' === $name ) {
				$ind   = Industries::all()[ (string) $value ] ?? null;
				$value = $ind ? $ind['label'] . ( $ind['ymyl'] ? ' (YMYL: accuracy is critical)' : '' ) : '';
			}
			if ( is_array( $value ) ) {
				$value = implode( '، ', array_map( static fn( $v ) => is_array( $v ) ? implode( ' ', array_filter( array_map( 'strval', array_filter( $v, 'is_scalar' ) ) ) ) : (string) $v, $value ) );
			}
			if ( is_bool( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$lines[] = $def['label'] . ': ' . str_replace( "\n", ' / ', mb_substr( $value, 0, 1200 ) );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Key pages with link categories, anchors and anchor statistics.
	 */
	private static function key_pages_text(): string {
		$stats = KeyPageLinks::stats();
		$lines = array();
		foreach ( KeyPageLinks::pages() as $id => $page ) {
			if ( ! KeyPageLinks::is_live( (int) $id ) ) {
				continue;
			}
			$cats = array_filter( array_map( static fn( $c ) => ( $t = get_term( (int) $c, 'category' ) ) instanceof WP_Term ? $t->name : '', (array) ( $page['link_categories'] ?? array() ) ) );
			$st   = $stats[ $id ] ?? null;
			$lines[] = '- ' . ( $id < 0 ? KeyPageLinks::title_of( (int) $id ) : self::title( $id ) ) . ' | ' . ( $id < 0 ? rawurldecode( KeyPageLinks::url_of( (int) $id ) ) : self::url( $id ) )
				. ' | type: ' . \PaydarWP\PaydarPress\Strategy\Structure::type_of( (int) $id, $page )
				. ( ! empty( $page['main_keyword'] ) ? ' | main keyword: ' . $page['main_keyword'] . ' (never use it as the main keyword of an article; articles support this page)' : '' )
				. ' | priority: ' . ( $page['priority'] ?? 'normal' )
				. ' | link from categories: ' . ( $cats ? implode( '، ', $cats ) : 'all' )
				. ( ! empty( $page['anchors'] ) ? ' | allowed anchors: ' . implode( '، ', (array) $page['anchors'] ) : '' )
				. ( $st && $st['over_cap'] ? ' | over_cap: exact-match anchors already above 30% — use only non-exact anchors' : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * CTA options.
	 */
	private static function cta_text(): string {
		$lines = array();
		foreach ( (array) ProfileRepository::value( 'cta_options' ) as $cta ) {
			if ( is_array( $cta ) ) {
				$lines[] = '- ' . implode( ' | ', array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? trim( (string) $v ) : '', array_intersect_key( $cta, array_flip( array( 'name', 'url', 'use_when' ) ) ) ) ) );
			}
		}
		return implode( "\n", array_filter( $lines, static fn( $l ) => '- ' !== $l ) );
	}

	/**
	 * The product's categories as link targets (name | URL | products).
	 *
	 * @param int $post_id Product.
	 */
	private static function product_category_text( int $post_id ): string {
		$terms = taxonomy_exists( 'product_cat' ) ? wp_get_post_terms( $post_id, 'product_cat' ) : array();
		$lines = array();
		foreach ( is_array( $terms ) ? array_slice( $terms, 0, 3 ) : array() as $t ) {
			$link = get_term_link( $t );
			if ( is_string( $link ) ) {
				$lines[] = '- ' . html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) . ' | ' . rawurldecode( $link ) . ' | ' . (int) $t->count . ' products';
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Role of a category in the taxonomy map, in prompt words.
	 *
	 * @param string $role high|normal|low.
	 */
	private static function role_word( string $role ): string {
		return array( 'high' => 'main', 'low' => 'minor' )[ $role ] ?? 'normal';
	}

	/**
	 * Categories with item counts and scope.
	 */
	private static function categories_text(): string {
		$lines = array();
		foreach ( \PaydarWP\PaydarPress\Strategy\Structure::categories() as $c ) {
			$lines[] = '- ' . $c['name'] . ' (' . $c['count'] . ' posts, target ' . $c['target'] . ', role ' . self::role_word( $c['importance'] ) . ( $c['gap'] > 0 ? ', needs ' . $c['gap'] . ' more' : ', target reached' ) . ')' . ( '' !== $c['scope'] ? ' — covers: ' . $c['scope'] : '' );
		}
		$closed = array();
		foreach ( \PaydarWP\PaydarPress\Profile\TaxonomyMap::notes() as $n ) {
			if ( 'category' === ( $n['taxonomy'] ?? '' ) && ! in_array( \PaydarWP\PaydarPress\Profile\TaxonomyMap::role_of( $n ), \PaydarWP\PaydarPress\Profile\TaxonomyMap::ACTIVE_ROLES, true ) ) {
				$closed[] = (string) $n['name'];
			}
		}
		if ( $closed ) {
			$lines[] = '(Do NOT put new content in: ' . implode( '، ', $closed ) . ' — navigation-only or retired.)';
		}
		return $lines ? implode( "\n", $lines ) . "\n(Prefer categories that need more posts, main ones first; for categories that reached their target, propose only clearly missing intents.)" : '- (no categories yet)';
	}

	/**
	 * Most related published items for the subject (word overlap), then same category, then newest.
	 *
	 * @param string $subject     Subject text.
	 * @param int    $category_id Category.
	 * @param int[]  $exclude     Ids to leave out.
	 */
	private const RELATED_PRODUCTS = 3;

	/**
	 * Related content lines.
	 *
	 * @param string $subject     Subject words.
	 * @param int    $category_id Category.
	 * @param array  $exclude     IDs to skip.
	 */
	private static function related_text( string $subject, int $category_id, array $exclude ): string {
		global $wpdb;
		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT object_id, object_type, title_norm, focus_keyword, focus_keyword_norm, primary_term_id, published_at FROM {$table} WHERE status = 'publish' AND object_type IN ('post','page','product') ORDER BY published_at DESC LIMIT 20000", ARRAY_A );

		$words = array_filter( explode( ' ', PersianNormalizer::for_key( $subject ) ), static fn( $w ) => mb_strlen( $w ) > 2 );
		$skip  = array_flip( array_map( 'intval', $exclude ) );
		$scored = array();
		foreach ( (array) $rows as $i => $row ) {
			$id = (int) $row['object_id'];
			if ( isset( $skip[ $id ] ) ) {
				continue;
			}
			$hay   = ' ' . $row['title_norm'] . ' ' . $row['focus_keyword_norm'] . ' ';
			$score = 0;
			foreach ( $words as $w ) {
				if ( false !== mb_strpos( $hay, ' ' . $w . ' ' ) ) {
					$score += 3;
				}
			}
			if ( $category_id > 0 && (int) $row['primary_term_id'] === $category_id ) {
				$score += 2;
			}
			$scored[] = array( $score, -$i, $row );
		}
		rsort( $scored );

		// Matching items first; unrelated ones only as a short sample (newest), so the prompt
		// stays focused and small on large sites.
		$matching = array_values( array_filter( $scored, static fn( $s ) => $s[0] > 0 ) );
		// Near-identical products (model R1…R21) crowd out guides: at most RELATED_PRODUCTS.
		$products = 0;
		$matching = array_values(
			array_filter(
				$matching,
				static function ( $s ) use ( &$products ) {
					if ( 'product' !== $s[2]['object_type'] ) {
						return true;
					}
					return ++$products <= self::RELATED_PRODUCTS;
				}
			)
		);
		$filler   = array_slice( array_values( array_filter( $scored, static fn( $s ) => 0 === $s[0] && 'product' !== $s[2]['object_type'] ) ), 0, max( 0, 15 - count( $matching ) ) );
		$picked   = array_slice( array_merge( $matching, $filler ), 0, self::RELATED_LIMIT );

		$lines = array();
		foreach ( $picked as list( , , $row ) ) {
			$id      = (int) $row['object_id'];
			$lines[] = '- ' . self::title( $id ) . ' | ' . self::url( $id ) . ( '' !== (string) $row['focus_keyword'] ? ' | ' . $row['focus_keyword'] : '' ) . ( 'product' === $row['object_type'] ? ' | product' : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Planned and rejected topics.
	 */
	private static function topics_text(): string {
		global $wpdb;
		$table = Schema::table( 'topics' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT title, status FROM {$table} WHERE status IN ('new','accepted','rejected') ORDER BY id DESC LIMIT 150", ARRAY_A );
		return implode( "\n", array_map( static fn( $r ) => '- ' . $r['title'] . ' (' . ( 'rejected' === $r['status'] ? 'rejected' : 'planned' ) . ')', (array) $rows ) );
	}

	/**
	 * Search Console queries related to the subject (or the top ones).
	 *
	 * @param string $subject Subject.
	 */
	private static function gsc_text( string $subject ): string {
		if ( ! class_exists( '\PaydarWP\PaydarPress\Gsc\GscInsights' ) ) {
			return '';
		}
		$words = array_filter( explode( ' ', PersianNormalizer::for_key( $subject ) ), static fn( $w ) => mb_strlen( $w ) > 2 );
		$all   = \PaydarWP\PaydarPress\Gsc\GscInsights::queries();
		$pick  = $words ? array_filter( $all, static fn( $q ) => (bool) array_filter( $words, static fn( $w ) => false !== mb_strpos( ' ' . $q['norm'] . ' ', ' ' . $w . ' ' ) ) ) : array();
		$pick  = array_slice( $pick ?: $all, 0, 25 );
		return implode( "\n", array_map( static fn( $q ) => '- ' . $q['query'] . ' | ' . $q['impressions'] . ' | ' . $q['position'], $pick ) );
	}

	/**
	 * SEO values of a post via the active SEO plugin.
	 *
	 * @param int $id Post.
	 * @return array{title: string, meta_description: string, focus_keyword: string}
	 */
	private static function seo_of( int $id ): array {
		$data = \PaydarWP\PaydarPress\Seo\SeoDetector::adapter()->post( $id );
		return array(
			'title'            => trim( (string) ( $data['seo_title'] ?? $data['title'] ?? '' ) ),
			'meta_description' => trim( (string) ( $data['meta_description'] ?? '' ) ),
			'focus_keyword'    => trim( (string) ( $data['focus_keyword'] ?? '' ) ),
		);
	}

	/**
	 * Post content without block comments, shortcodes' wrappers kept as text, attributes trimmed.
	 *
	 * @param string $html HTML.
	 */
	private static function clean_html( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/su', '', $html );
		$html = (string) preg_replace( '#<(script|style|iframe|noscript|svg)\b[^>]*>.*?</\1\s*>#isu', '', $html );
		$html = wp_kses(
			$html,
			array(
				'h2' => array(), 'h3' => array(), 'h4' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(),
				'table' => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(), 'th' => array(), 'td' => array(),
				'a' => array( 'href' => true ), 'strong' => array(), 'em' => array(), 'b' => array(), 'blockquote' => array(),
				'img' => array( 'src' => true, 'alt' => true ), 'div' => array( 'class' => true ), 'br' => array(),
			)
		);
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $html ) );
	}

	/**
	 * Category term or null.
	 *
	 * @param int $id Term id.
	 */
	private static function category( int $id ): ?WP_Term {
		if ( $id <= 0 ) {
			return null;
		}
		$t = get_term( $id );
		return $t instanceof WP_Term && in_array( $t->taxonomy, array( 'category', 'product_cat' ), true ) ? $t : null;
	}

	/**
	 * Decoded title.
	 *
	 * @param int $id Post.
	 */
	private static function title( int $id ): string {
		return html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Readable permalink.
	 *
	 * @param int $id Post.
	 */
	private static function url( int $id ): string {
		return rawurldecode( (string) get_permalink( $id ) );
	}

	/**
	 * Site name.
	 */
	private static function site_name(): string {
		return KeyPageLinks::brand();
	}

	/**
	 * Single-line plain text.
	 *
	 * @param mixed $v   Value.
	 * @param int   $max Max characters.
	 */
	private static function text( $v, int $max ): string {
		return is_scalar( $v ) ? mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) ), 0, $max ) : '';
	}
}
