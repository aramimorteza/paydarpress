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

	public const TASKS = array( 'topics', 'article', 'rewrite', 'term', 'meta' );

	/** Existing items listed for duplicate checks and internal links. */
	private const RELATED_LIMIT = 60;

	/** Longest current content sent for a rewrite (characters of HTML). */
	private const REWRITE_MAX_CHARS = 40000;

	/** Profile fields that are not useful to the writer. */
	private const SKIP_FIELDS = array( 'seo_plugin', 'page_builder', 'woocommerce', 'key_pages', 'cta_options', 'categories_scope', 'competitors' );

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
			. "(این متن را کامل در ChatGPT، Claude، Gemini یا هر دستیار دیگری بچسبانید. پاسخ را که یک بلوک JSON است، در پایدارپرس ← «ورود مقالات نوشته‌شده» بچسبانید.)\n\n"
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
	 * New topic ideas.
	 *
	 * @param array $args category_id, query, about (title of a page to support), count.
	 */
	private static function task_topics( array $args ): array {
		$count    = max( 3, min( 20, (int) ( $args['count'] ?? 10 ) ) );
		$category = self::category( (int) ( $args['category_id'] ?? 0 ) );
		$query    = self::text( $args['query'] ?? '', 200 );
		$focus    = $category ? 'all in the category «' . $category->name . '»' : 'spread across the categories, weighted toward thin categories and the primary content goal; at most 4 per category';

		$task = "Suggest {$count} NEW article topics for this website, {$focus}.";
		if ( '' !== $query ) {
			$task .= "\nStart from this real Google search that the site already appears for: «{$query}». Include it (or the best article for it) and related intents.";
		}
		$task .= "\nIf a strong idea is already covered by an existing item, skip it.";

		return array(
			'title'       => $category ? 'پیشنهاد موضوع برای دسته «' . $category->name . '»' : ( '' !== $query ? 'پیشنهاد موضوع از جست‌وجوی «' . $query . '»' : 'پیشنهاد موضوع تازه' ),
			'command'     => $category ? 'موضوع جدید: ' . $category->name : ( '' !== $query ? "موضوع جدید\n(بر اساس جست‌وجوی «{$query}» در سرچ کنسول)" : 'موضوع جدید' ),
			'task'        => $task,
			'subject'     => trim( ( $category ? $category->name : '' ) . ' ' . $query ),
			'category_id' => $category ? (int) $category->term_id : 0,
			'rules'       => self::rules_topics(),
			'output'      => self::output( 'topics' ),
			'with_topics' => true,
			'with_gsc'    => true,
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
			$topic = $wpdb->get_row( $wpdb->prepare( "SELECT title, focus_keyword, category_id, intent, notes FROM {$table} WHERE id = %d", (int) $args['topic_id'] ), ARRAY_A );
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

		$extra = "## CURRENT CONTENT\nTitle: {$title}\n"
			. ( '' !== $seo['focus_keyword'] ? "Main keyword: {$seo['focus_keyword']}\n" : '' )
			. ( '' !== $seo['title'] ? "SEO title: {$seo['title']}\n" : '' )
			. ( '' !== $seo['meta_description'] ? "Meta description: {$seo['meta_description']}\n" : '' )
			. ( $is_product && '' !== trim( $post->post_excerpt ) ? 'Short description: ' . self::text( wp_strip_all_tags( $post->post_excerpt ), 1000 ) . "\n" : '' )
			. "HTML:\n```html\n{$html}\n```" . ( $cut ? "\n(The content was cut to the first " . self::REWRITE_MAX_CHARS . ' characters.)' : '' );

		return array(
			'title'       => ( $is_product ? 'بازنویسی توضیحات محصول «' : 'بازنویسی «' ) . $title . '»',
			'command'     => 'بروزرسانی: ' . $url . ( $reasons ? "\nمشکلاتی که پایدارپرس پیدا کرده: " . implode( '، ', $reasons ) : '' ),
			'task'        => $task,
			'subject'     => $title . ' ' . $seo['focus_keyword'],
			'category_id' => (int) ( wp_get_post_categories( $post->ID )[0] ?? 0 ),
			'exclude'     => array( $post->ID ),
			'rules'       => self::rules_article() . "\n" . self::rules_rewrite(),
			'output'      => self::output( 'rewrite', array( 'url' => $url, 'product' => $is_product ) ),
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
	 * Fixed instructions.
	 *
	 * @param string $rules  Task rules.
	 * @param string $output Output format.
	 */
	private static function system( string $rules, string $output ): string {
		$lang = (string) ProfileRepository::value( 'language' );
		$lang = '' !== $lang ? $lang : get_locale();

		return "You are ContentMaster, a senior SEO content strategist and writer working for the website described below. Write for real readers first, in the site's language ({$lang}; Persian sites: natural, fluent Persian with correct half-spaces, no machine-translated phrasing). Follow the SITE PROFILE exactly (audience, tone, rules, forbidden topics, glossary).\n\n"
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
			)
		);
	}

	/**
	 * Rules for writing an article (condensed from ContentMaster sections 8.1–8.6 and 6.5).
	 */
	private static function rules_article(): string {
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
				'- If the article has a FAQ section, put the same questions/answers in `faq`.',
				'- SEO: title ≤ 60 characters, meta description ≤ 155 characters, short English slug based on intent, tags from the approved tags when given.',
				'- reverse_links: 2–3 EXISTING CONTENT items that should link to this new article, with an anchor and the sentence where it fits.',
				'- featured_image.prompt in English (photographic or illustrative scene, no text in the image); alt in the site language.',
			)
		);
	}

	/**
	 * Extra rules for rewrites.
	 */
	private static function rules_rewrite(): string {
		return implode(
			"\n",
			array(
				'- This is a REWRITE: keep the URL and the main search intent; keep correct facts, useful examples and existing good internal links; remove outdated years/figures unless still true.',
				'- Do not count existing links to key pages as extra: the whole new content must respect the key-page limit.',
				'- `reverse_links` are optional for a rewrite.',
			)
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

		$article = '{"title": "", "slug": "", "html": "<full article HTML>", "category": "<exact category name>", "tags": [], "seo": {"title": "", "meta_description": "", "focus_keyword": ""}, "excerpt": "", "featured_image": {"prompt": "", "alt": ""}, "faq": [{"q": "", "a": ""}], "reverse_links": [{"source_url": "", "anchor": "", "sentence": ""}]}';

		switch ( $task ) {
			case 'topics':
				return $head . 'with "topics": [{"title": "", "category": "<exact category name>", "focus_keyword": "", "intent": "informational|educational|how-to|commercial|support|transactional", "notes": "<one line: why this topic, which key page it can link to>"}]';
			case 'article':
				return $head . "with \"articles\": [ {$article} ] and optionally \"notes\": \"\".";
			case 'rewrite':
				return $head . 'with "articles": [ ' . str_replace( '{"title"', '{"rewrite_of": "' . $ctx['url'] . '", "title"', $article ) . ' ] and optionally "notes": "<what changed, in the site language>".'
					. ( empty( $ctx['product'] ) ? '' : ' For a product, `html` is the full product description and `excerpt` is the new short description; leave category and tags empty.' );
			case 'term':
				return $head . 'with "term_descriptions": [{"taxonomy": "' . $ctx['taxonomy'] . '", "term_id": ' . (int) $ctx['term_id'] . ', "name": ' . wp_json_encode( $ctx['name'], JSON_UNESCAPED_UNICODE ) . ', "description": "<HTML>"}]';
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

		$key = self::key_pages_text();
		if ( '' !== $key ) {
			$parts[] = "## KEY PAGES (link targets; at most " . KeyPageLinks::MAX_PER_ARTICLE . " per article)\n{$key}";
		}
		$cta = self::cta_text();
		if ( '' !== $cta ) {
			$parts[] = "## CTA OPTIONS\n{$cta}";
		}
		$parts[] = "## CATEGORIES\n" . self::categories_text();

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
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$cats = array_filter( array_map( static fn( $c ) => ( $t = get_term( (int) $c, 'category' ) ) instanceof WP_Term ? $t->name : '', (array) ( $page['link_categories'] ?? array() ) ) );
			$st   = $stats[ $id ] ?? null;
			$lines[] = '- ' . self::title( $id ) . ' | ' . self::url( $id )
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
	 * Categories with item counts and scope.
	 */
	private static function categories_text(): string {
		$scopes = array();
		foreach ( (array) ProfileRepository::value( 'categories_scope' ) as $c ) {
			if ( is_array( $c ) && ! empty( $c['id'] ) ) {
				$scopes[ (int) $c['id'] ] = (string) ( $c['scope'] ?? '' );
			}
		}
		$lines = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 80 ) ) as $t ) {
			if ( $t instanceof WP_Term && 'uncategorized' !== $t->slug ) {
				$lines[] = '- ' . $t->name . ' (' . $t->count . ' posts)' . ( ! empty( $scopes[ $t->term_id ] ) ? ' — ' . $scopes[ $t->term_id ] : '' );
			}
		}
		return $lines ? implode( "\n", $lines ) : '- (no categories yet)';
	}

	/**
	 * Most related published items for the subject (word overlap), then same category, then newest.
	 *
	 * @param string $subject     Subject text.
	 * @param int    $category_id Category.
	 * @param int[]  $exclude     Ids to leave out.
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
		$filler   = array_slice( array_values( array_filter( $scored, static fn( $s ) => 0 === $s[0] ) ), 0, max( 0, 15 - count( $matching ) ) );
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
