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
			$warnings[] = sprintf( 'این خروجی برای سایت «%s» ساخته شده، اما این سایت «%s» است. احتمالاً پرامپت سایت دیگری را استفاده کرده‌اید؛ ورود را ادامه ندهید.', $site, $home );
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
			$plan     = $this->check_topic_plan( $topic, $checks );
			$seen[]   = array( $topic['title'], $topic['focus_keyword'] );
			$topics[] = $this->with_checks( $plan + array( 'index' => $i, 'category_name' => $category['name'] ), $checks );
		}
		$cluster_plans = array();
		foreach ( (array) ( $parsed['cluster_plans'] ?? array() ) as $i => $cp ) {
			$cluster_plans[] = $this->check_cluster_plan( $i, $cp, $categories, $topics_db, $seen );
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
		$alt_items = $this->check_alts( (array) ( $parsed['image_alts'] ?? array() ) );
		$classes   = $this->check_classifications( (array) ( $parsed['classifications'] ?? array() ) );
		$struct_items = array();
		foreach ( $parsed['structure_suggestions'] as $i => $st ) {
			$struct_items[] = $this->check_structure( $i, $st );
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
			'structure_suggestions' => $struct_items,
			'image_alts'       => $alt_items,
			'new_terms'        => $classes['new_terms'],
			'classifications'  => $classes['items'],
			'cluster_plans'    => $cluster_plans,
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
				'file_name'  => mb_substr( (string) ( $preview['meta']['generator'] ?: 'AI' ), 0, 255 ),
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
			'alts'             => 0,
			'classified'       => 0,
			'new_terms'        => 0,
			'notes'            => array(),
			'errors'           => array(),
		);
		$preview += array(
			'term_descriptions' => array(),
			'seo_updates'       => array(),
			'structure_suggestions' => array(),
			'image_alts'        => array(),
			'new_terms'         => array(),
			'classifications'   => array(),
			'cluster_plans'     => array(),
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
					'cluster_id'    => $topic['cluster_id'] ?? null,
					'tags'          => $topic['tags'] ?? array(),
					'link_plan'     => $topic['link_plan'] ?? array(),
				)
			);
			++$result['topics'];
		}

		// Cluster maps: the cluster, its pillar and existing members, and topics for the gaps.
		$result['clusters'] = 0;
		foreach ( $pick( 'cluster_plans' ) as $cp ) {
			try {
				$cid = $cp['cluster_id'] ? (int) $cp['cluster_id'] : \PaydarWP\PaydarPress\Strategy\Clusters::save(
					array(
						'name'         => $cp['name'],
						'main_keyword' => $cp['main_keyword'],
						'category_id'  => (int) $cp['category_id'],
					)
				);
				foreach ( $cp['members'] as $m ) {
					\PaydarWP\PaydarPress\Strategy\Clusters::add_post( $cid, (int) $m['post_id'], (string) $m['subtopic'] );
				}
				if ( $cp['pillar_id'] ) {
					\PaydarWP\PaydarPress\Strategy\Clusters::set_pillar( $cid, (int) $cp['pillar_id'] );
				}
				foreach ( $cp['new_topics'] as $nt ) {
					if ( ! empty( $nt['duplicate'] ) ) {
						continue;
					}
					TopicRepository::insert(
						array(
							'title'         => $nt['title'],
							'category_id'   => $cp['category_id'],
							'focus_keyword' => $nt['focus_keyword'],
							'intent'        => $nt['intent'],
							'notes'         => $nt['notes'],
							'import_id'     => $import_id,
							'cluster_id'    => $cid,
							'tags'          => $nt['tags'],
							'link_plan'     => $nt['pillar'] ? array( 'role' => 'pillar' ) : array(),
						)
					);
					++$result['topics'];
				}
				++$result['clusters'];
			} catch ( \RuntimeException $e ) {
				$result['errors'][] = '«' . $cp['name'] . '»: ' . $e->getMessage();
			}
		}

		if ( current_user_can( 'edit_posts' ) ) {
			foreach ( $pick( 'articles' ) as $article ) {
				$post_id = $this->create_draft( $article, $import_id );
				if ( is_wp_error( $post_id ) ) {
					$result['errors'][] = '«' . $article['title'] . '»: ' . $post_id->get_error_message();
					continue;
				}
				$chosen = null;
				if ( ! empty( $article['rewrite_id'] ) && is_array( $article['plan'] ?? null ) ) {
					$chosen = self::chosen_plan( $article['plan'], $selection['plans'][ (string) $article['index'] ] ?? null );
					update_post_meta( $post_id, Rewrites::META_PLAN, $chosen );
				}
				$result['articles'][] = array(
					'id'           => $post_id,
					'title'        => $article['title'],
					'edit_url'     => (string) get_edit_post_link( $post_id, 'raw' ),
					'rewrite'      => ! empty( $article['rewrite_id'] ),
					'original'     => $article['rewrite_title'] ?? null,
					'original_url' => $article['rewrite_url'] ?? null,
					'plan'         => null === $chosen ? array() : Rewrites::plan_lines( $chosen ),
					'can_apply'    => ! empty( $article['rewrite_id'] ) && ! \PaydarWP\PaydarPress\Policy\ReviewGate::needs_review( $post_id ),
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

		$alt_posts = array();
		foreach ( $pick( 'image_alts' ) as $a ) {
			$done = \PaydarWP\PaydarPress\Images\AltContext::apply( $a['ref'], $a['alt'] );
			if ( $done['ok'] ) {
				++$result['alts'];
				$alt_posts[ $done['post_id'] ] = true;
			} else {
				$result['errors'][] = '«' . $a['alt'] . '»: ' . $done['message'];
			}
		}
		foreach ( array_keys( $alt_posts ) as $alt_post ) {
			\PaydarWP\PaydarPress\Strategy\ChangeLog::record( 'alt', (int) $alt_post, 'متن جایگزین تصاویر به‌روز شد' );
		}
		// Rescan so the image findings update soon (a featured-image alt is not a post save).
		foreach ( array_keys( $alt_posts ) as $alt_post ) {
			\PaydarWP\PaydarPress\Queue\Queue::schedule( \PaydarWP\PaydarPress\Scanner\ScanManager::ACTION_ITEM, array( (int) $alt_post ), 10, true );
		}

		// Classification: approved new tags first, then the items (add only).
		$created = array();
		$made    = array();
		foreach ( $pick( 'new_terms' ) as $nt ) {
			$existing = get_term_by( 'name', $nt['name'], $nt['taxonomy'] );
			if ( $existing instanceof \WP_Term ) {
				$created[ $nt['name'] ] = (int) $existing->term_id;
				continue;
			}
			$ins = wp_insert_term( $nt['name'], $nt['taxonomy'] );
			if ( is_wp_error( $ins ) ) {
				$result['errors'][] = '«' . $nt['name'] . '»: ' . $ins->get_error_message();
				continue;
			}
			$created[ $nt['name'] ] = (int) $ins['term_id'];
			$made[]                 = (int) $ins['term_id'];
			++$result['new_terms'];
		}
		$undo = array();
		foreach ( $pick( 'classifications' ) as $c ) {
			$done = \PaydarWP\PaydarPress\Strategy\Classifier::apply( $c, $created );
			if ( ! $done['ok'] ) {
				$result['errors'][] = '«' . $c['title'] . '»: ' . $done['message'];
				continue;
			}
			++$result['classified'];
			if ( $done['undo']['terms'] || null !== $done['undo']['primary'] ) {
				$undo[] = $done['undo'];
			}
			if ( '' !== $done['message'] ) {
				$result['notes'][] = '«' . $c['title'] . '»: ' . $done['message'];
			}
		}
		\PaydarWP\PaydarPress\Strategy\Classifier::log( $undo, $made );

		$result['structure'] = \PaydarWP\PaydarPress\Strategy\StructurePlan::add( $pick( 'structure_suggestions' ) );

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
				'ورود پاسخ هوش مصنوعی: %s موضوع، %s پیش‌نویس، %s پیشنهاد لینک، %s متن دسته، %s متا، %s متن جایگزین تصویر.',
				PersianNormalizer::fa_number( $result['topics'] ),
				PersianNormalizer::fa_number( count( $result['articles'] ) ),
				PersianNormalizer::fa_number( $result['link_suggestions'] ),
				PersianNormalizer::fa_number( $result['terms'] ),
				PersianNormalizer::fa_number( $result['seo'] ),
				PersianNormalizer::fa_number( $result['alts'] )
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

		// Inline images in proportion to the length (existing <img> in the HTML count too).
		$words_n = PersianNormalizer::word_count( self::plain( $article['html'] ) );
		$imgs_n  = count( (array) ( $article['inline_images'] ?? array() ) ) + preg_match_all( '/<img\\b/i', $article['html'] );
		$verdict = \PaydarWP\PaydarPress\Images\InlinePlan::verdict( $words_n, $imgs_n );
		if ( 'ok' !== $verdict ) {
			$want = \PaydarWP\PaydarPress\Images\InlinePlan::expected( $words_n );
			$checks[] = array(
				'many' === $verdict ? 'warning' : 'info',
				'many' === $verdict
					? sprintf( '%s تصویر داخل متن برای %s کلمه زیاد است (پیشنهاد: %s)؛ تصویر اضافه صفحه را سنگین و خواننده را خسته می‌کند. فقط مفیدترین‌ها را بگذارید.', PersianNormalizer::fa_number( $imgs_n ), PersianNormalizer::fa_number( $words_n ), PersianNormalizer::fa_number( $want ) )
					: sprintf( 'برای %s کلمه حدود %s تصویر داخل متن پیشنهاد می‌شود و %s تصویر آمده؛ پس از ورود، تصویر بخش‌های اصلی را اضافه کنید.', PersianNormalizer::fa_number( $words_n ), PersianNormalizer::fa_number( $want ), PersianNormalizer::fa_number( $imgs_n ) ),
			);
		}

		$slug = sanitize_title( $article['slug'] );
		if ( ! $rewrite ) {
			$style = (string) \PaydarWP\PaydarPress\Core\Settings::get( 'policy.slug_style', 'english' );
			if ( 'english' === $style && ( '' === trim( $article['slug'] ) || preg_match( '/[^\x00-\x7F]/', rawurldecode( $article['slug'] ) ) ) ) {
				$checks[] = array( 'warning', '' === trim( $article['slug'] ) ? 'نامک (اسلاگ) انگلیسی نیامده؛ وردپرس از عنوان فارسی نامک می‌سازد. پیش از انتشار یک نامک کوتاه انگلیسی بنویسید.' : 'نامک انگلیسی نیست (سیاست سایت: نامک انگلیسی). پیش از انتشار اصلاحش کنید.' );
			} elseif ( mb_strlen( rawurldecode( $slug ) ) > 60 ) {
				$checks[] = array( 'warning', 'نامک طولانی است (' . PersianNormalizer::fa_number( mb_strlen( rawurldecode( $slug ) ) ) . ' نویسه)؛ ۳ تا ۵ کلمه کافی است.' );
			}
		}
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

		// Rewrites: changes to the original's tags, category and address, chosen in the preview
		// and applied to the original together with the text.
		$plan = null;
		if ( $rewrite ) {
			list( $plan, $plan_checks ) = self::rewrite_plan( $rewrite, $article, $category );
			$checks          = array_merge( $checks, $plan_checks );
			$article['tags'] = array();
		}

		// Tags: taxonomy map rules (no retired / navigation tags, no category names, at most
		// MAX_TAGS, at most 2 new ones). Existing tags are kept first.
		$max_tags      = \PaydarWP\PaydarPress\Profile\TaxonomyMap::MAX_TAGS;
		$existing_tags = array();
		$new_tags      = array();
		$dropped       = array();
		foreach ( $article['tags'] as $tag ) {
			$term = get_term_by( 'name', $tag, 'post_tag' );
			if ( term_exists( $tag, 'category' ) ) {
				$dropped[] = $tag;
			} elseif ( $term instanceof \WP_Term ) {
				if ( in_array( \PaydarWP\PaydarPress\Profile\TaxonomyMap::role( (int) $term->term_id ), \PaydarWP\PaydarPress\Profile\TaxonomyMap::ACTIVE_ROLES, true ) ) {
					$existing_tags[] = $tag;
				} else {
					$dropped[] = $tag;
				}
			} else {
				$new_tags[] = $tag;
			}
		}
		if ( count( $new_tags ) > 2 ) {
			$dropped  = array_merge( $dropped, array_slice( $new_tags, 2 ) );
			$new_tags = array_slice( $new_tags, 0, 2 );
		}
		$kept = array_slice( array_merge( $existing_tags, $new_tags ), 0, $max_tags );
		if ( count( $existing_tags ) + count( $new_tags ) > $max_tags ) {
			$dropped = array_merge( $dropped, array_slice( array_merge( $existing_tags, $new_tags ), $max_tags ) );
		}
		$new_tags        = array_values( array_intersect( $new_tags, $kept ) );
		$article['tags'] = $kept;
		if ( $dropped ) {
			$checks[] = array( 'info', sprintf( 'این برچسب‌ها وارد نمی‌شوند (حداکثر %s برچسب، حداکثر ۲ برچسب تازه، بدون برچسب کنارگذاشته یا هم‌نام دسته): %s', PersianNormalizer::fa_number( $max_tags ), implode( '، ', $dropped ) ) );
		}
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
					'rewrite_url'    => $rewrite ? (string) get_permalink( $rewrite ) : null,
					'plan'           => $plan,
				)
			),
			$checks
		);
	}

	/**
	 * What a rewrite changes on the original besides the text: tags (keep / add / remove with
	 * a reason, within the cap), an added category (posts) and the address (only when the
	 * current one is Persian, encoded, numeric or a "-2" duplicate). Nothing here is applied
	 * until the owner ticks it and replaces.
	 *
	 * An empty `tags` list from the assistant means "no tag change", never "remove all".
	 *
	 * @param \WP_Post $original Post being rewritten.
	 * @param array    $article  Parsed article.
	 * @param array    $category Resolved category { id, name }.
	 * @return array{0: array, 1: array}
	 */
	private static function rewrite_plan( \WP_Post $original, array $article, array $category ): array {
		$map    = \PaydarWP\PaydarPress\Profile\TaxonomyMap::class;
		$checks = array();
		$tax    = 'product' === $original->post_type ? 'product_tag' : ( 'post' === $original->post_type ? 'post_tag' : '' );
		$cat_tx = 'product' === $original->post_type ? 'product_cat' : 'category';
		$plan   = array(
			'taxonomy' => $tax,
			'max'      => $map::max_tags( $original->post_type ),
			'current'  => array(),
			'add'      => array(),
			'new'      => array(),
			'remove'   => array(),
			'category' => null,
			'slug'     => null,
		);
		$norm = static fn( string $n ): string => \PaydarWP\PaydarPress\Text\PersianNormalizer::for_key( $n );

		if ( '' !== $tax && taxonomy_exists( $tax ) ) {
			$current = wp_get_post_terms( $original->ID, $tax );
			$current = is_array( $current ) ? $current : array();
			$by_norm = array();
			foreach ( $current as $t ) {
				$name                     = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				$plan['current'][]        = $name;
				$by_norm[ $norm( $name ) ] = $name;
			}
			foreach ( (array) ( $article['tags_remove'] ?? array() ) as $name => $reason ) {
				$hit = $by_norm[ $norm( (string) $name ) ] ?? null;
				if ( null !== $hit ) {
					$plan['remove'][] = array( 'name' => $hit, 'reason' => (string) $reason );
				}
			}
			$removing = array_map( static fn( $r ) => $norm( $r['name'] ), $plan['remove'] );
			$dropped  = array();
			foreach ( $article['tags'] as $name ) {
				$key = $norm( $name );
				if ( isset( $by_norm[ $key ] ) || in_array( $key, $removing, true ) ) {
					continue;
				}
				if ( term_exists( $name, $cat_tx ) ) {
					$dropped[] = $name;
					continue;
				}
				$term = get_term_by( 'name', $name, $tax );
				if ( $term instanceof \WP_Term ) {
					if ( in_array( $map::role( (int) $term->term_id ), $map::ACTIVE_ROLES, true ) ) {
						$plan['add'][] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
					} else {
						$dropped[] = $name;
					}
				} elseif ( count( $plan['new'] ) < 1 ) {
					$plan['new'][] = $name;
				} else {
					$dropped[] = $name;
				}
			}
			// Within the cap: what stays + what is added.
			$room = $plan['max'] - ( count( $plan['current'] ) - count( $plan['remove'] ) );
			if ( count( $plan['add'] ) + count( $plan['new'] ) > max( 0, $room ) ) {
				$all          = array_merge( $plan['add'], $plan['new'] );
				$keep         = array_slice( $all, 0, max( 0, $room ) );
				$dropped      = array_merge( $dropped, array_slice( $all, max( 0, $room ) ) );
				$plan['add']  = array_values( array_intersect( $plan['add'], $keep ) );
				$plan['new']  = array_values( array_intersect( $plan['new'], $keep ) );
			}
			if ( $dropped ) {
				$checks[] = array( 'info', sprintf( 'این برچسب‌ها اضافه نمی‌شوند (سقف %s برچسب، حداکثر یک برچسب تازه، بدون برچسب کنارگذاشته یا هم‌نام دسته): %s', \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( $plan['max'] ), implode( '، ', array_unique( $dropped ) ) ) );
			}
			$after = count( $plan['current'] ) - count( $plan['remove'] ) + count( $plan['add'] ) + count( $plan['new'] );
			if ( $after > $plan['max'] ) {
				$checks[] = array( 'warning', sprintf( 'این %s %s برچسب خواهد داشت؛ سقف %s است. برچسب‌های کم‌ربط را حذف کنید.', 'product' === $original->post_type ? 'محصول' : 'مقاله', \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( $after ), \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( $plan['max'] ) ) );
			}
		}

		// Posts: an added category (never removes the current ones).
		if ( 'post' === $original->post_type && null !== $category['id'] && ! in_array( (int) $category['id'], wp_get_post_categories( $original->ID ), true ) && (int) $category['id'] !== (int) get_option( 'default_category' ) ) {
			$plan['category'] = array( 'id' => (int) $category['id'], 'name' => (string) $category['name'] );
		}

		// Address: changed only when the current one is poor and the suggestion is a clean English slug.
		$cur  = rawurldecode( (string) $original->post_name );
		$poor = self::poor_slug( $cur, $original );
		$new  = sanitize_title( (string) $article['slug'] );
		if ( '' !== $poor ) {
			if ( '' !== $new && preg_match( '/^[a-z0-9]+(-[a-z0-9]+){1,7}$/', $new ) && $new !== $cur && ! get_page_by_path( $new, OBJECT, $original->post_type ) ) {
				$plan['slug'] = array( 'from' => $cur, 'to' => $new, 'why' => $poor );
			} else {
				$checks[] = array( 'info', 'آدرس فعلی ' . $poor . '، ولی نامک انگلیسی مناسبی در پاسخ نیامد؛ آدرس دست نمی‌خورد.' );
			}
		}

		return array( $plan, $checks );
	}

	/**
	 * The parts of a rewrite plan the owner ticked. Without a choice from the browser the
	 * defaults apply: existing tags added, suggested removals done, new tags not created,
	 * category added and a poor address replaced.
	 *
	 * @param array      $plan   Plan from the preview.
	 * @param array|null $choice { add: string[], new: string[], remove: string[], category: bool, slug: bool }.
	 */
	public static function chosen_plan( array $plan, $choice ): array {
		$pick = static function ( array $offered, $wanted ) {
			if ( ! is_array( $wanted ) ) {
				return null;
			}
			return array_values( array_intersect( $offered, array_map( 'strval', $wanted ) ) );
		};
		$c          = is_array( $choice ) ? $choice : array();
		$remove_all = array_map( static fn( $r ) => $r['name'], (array) $plan['remove'] );
		$out        = array(
			'taxonomy' => (string) $plan['taxonomy'],
			'add'      => $pick( (array) $plan['add'], $c['add'] ?? null ) ?? (array) $plan['add'],
			'new'      => $pick( (array) $plan['new'], $c['new'] ?? null ) ?? array(),
			'remove'   => $pick( $remove_all, $c['remove'] ?? null ) ?? $remove_all,
			'category' => null !== $plan['category'] && ( ! isset( $c['category'] ) || ! empty( $c['category'] ) ) ? $plan['category'] : null,
			'slug'     => null !== $plan['slug'] && ( ! isset( $c['slug'] ) || ! empty( $c['slug'] ) ) ? $plan['slug'] : null,
		);
		return $out;
	}

	/**
	 * Why an address is worth changing ('' = it is fine and must stay).
	 *
	 * @param string   $slug Decoded slug.
	 * @param \WP_Post $post Post.
	 */
	public static function poor_slug( string $slug, \WP_Post $post ): string {
		if ( '' === $slug ) {
			return '';
		}
		if ( preg_match( '/[^\x00-\x7F]/', $slug ) ) {
			return 'فارسی است (در لینک‌ها به شکل %d8%… دیده می‌شود)';
		}
		if ( preg_match( '/^(post|product|page|p)?-?\d+$/', $slug ) ) {
			return 'فقط عدد است و معنایی ندارد';
		}
		if ( preg_match( '/^(.+)-([2-9])$/', $slug, $m ) && get_page_by_path( $m[1], OBJECT, $post->post_type ) ) {
			return 'تکراری است و پسوند «-' . $m[2] . '» گرفته';
		}
		return '';
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
		if ( ! empty( $article['inline_images'] ) ) {
			update_post_meta( $post_id, '_paydarpress_inline_images', $article['inline_images'] );
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
	 * Checks a structure suggestion: the categories it names must exist.
	 *
	 * @param int   $index Index.
	 * @param array $st    Parsed suggestion.
	 */
	private function check_structure( int $index, array $st ): array {
		$checks = array();
		$find   = static function ( ?int $id, string $name, string $tax ) {
			$t = $id ? get_term( $id, $tax ) : null;
			if ( ! $t instanceof \WP_Term && '' !== $name ) {
				$t = get_term_by( 'name', $name, $tax );
			}
			return $t instanceof \WP_Term ? $t : null;
		};
		if ( in_array( $st['action'], array( 'merge', 'split', 'rename', 'noindex', 'keep' ), true ) ) {
			$term = $find( $st['term_id'], $st['name'], $st['taxonomy'] );
			if ( null === $term ) {
				$checks[] = array( 'error', 'دسته «' . $st['name'] . '» در سایت پیدا نشد.' );
			} else {
				$st['term_id'] = (int) $term->term_id;
				$st['name']    = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( 'merge' === $st['action'] ) {
			$into = $find( $st['into_term_id'], $st['into_name'], $st['taxonomy'] );
			if ( null === $into ) {
				$checks[] = array( 'error', 'دسته مقصد ادغام «' . $st['into_name'] . '» پیدا نشد.' );
			} else {
				$st['into_term_id'] = (int) $into->term_id;
				$st['into_name']    = html_entity_decode( $into->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		$checks[] = array( 'info', 'فقط به فهرست پیشنهادهای ساختار اضافه می‌شود؛ هیچ دسته‌ای خودکار تغییر نمی‌کند.' );
		return $this->with_checks( $st + array( 'index' => $index ), $checks );
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
	 * Checks proposed alt texts against the images that still need one.
	 *
	 * @param array $items Parsed image_alts.
	 * @return array[]
	 */
	private function check_alts( array $items ): array {
		$out     = array();
		$by_post = array();
		$seen    = array();
		foreach ( $items as $index => $a ) {
			$post_id = (int) substr( $a['ref'], 1, (int) strpos( $a['ref'], '-' ) - 1 );
			if ( ! isset( $by_post[ $post_id ] ) ) {
				$post                = get_post( $post_id );
				$by_post[ $post_id ] = array();
				if ( $post instanceof \WP_Post ) {
					foreach ( \PaydarWP\PaydarPress\Images\AltContext::for_post( $post ) as $o ) {
						$by_post[ $post_id ][ $o['ref'] ] = $o;
					}
				}
			}
			$o      = $by_post[ $post_id ][ $a['ref'] ] ?? null;
			$alt    = \PaydarWP\PaydarPress\Images\AltContext::clean_alt( $a['alt'] );
			$checks = array();
			if ( null === $o ) {
				$checks[] = array( 'error', 'این تصویر پیدا نشد یا در این فاصله متن جایگزین گرفته است.' );
			} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
				$checks[] = array( 'error', 'اجازه ویرایش این نوشته را ندارید.' );
			} else {
				$keyword = (string) self::focus_keyword_of( $post_id );
				$checks  = \PaydarWP\PaydarPress\Images\AltContext::checks( $alt, (string) $o['file'], $keyword );
			}
			$key = $post_id . '|' . mb_strtolower( $alt );
			if ( isset( $seen[ $key ] ) ) {
				$checks[] = array( 'warning', 'همین متن برای تصویر دیگری از همین نوشته هم آمده؛ هر تصویر متن خودش را لازم دارد.' );
			}
			$seen[ $key ] = true;

			$out[] = $this->with_checks(
				array(
					'index'      => $index,
					'ref'        => $a['ref'],
					'alt'        => $alt,
					'post_id'    => $post_id,
					'post_title' => $o['post_title'] ?? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
					'kind'       => $o['kind'] ?? '',
					'src'        => $o['src'] ?? '',
					'heading'    => $o['heading'] ?? '',
				),
				$checks
			);
		}
		return $out;
	}

	/**
	 * Topic plan: cluster by name, tags (active, no category names), planned links that
	 * resolve to published content.
	 *
	 * @param array $topic  Parsed topic.
	 * @param array $checks Checks (appended).
	 */
	private function check_topic_plan( array $topic, array &$checks ): array {
		$topic['cluster_id'] = null;
		if ( '' !== ( $topic['cluster'] ?? '' ) ) {
			$c = \PaydarWP\PaydarPress\Strategy\Clusters::find_by_name( $topic['cluster'] );
			if ( $c ) {
				$topic['cluster_id'] = (int) $c['id'];
				$topic['cluster']    = $c['name'];
			} else {
				$checks[] = array( 'info', 'خوشه «' . $topic['cluster'] . '» وجود ندارد؛ موضوع بدون خوشه ذخیره می‌شود.' );
				$topic['cluster'] = '';
			}
		}
		$tags = array();
		foreach ( (array) ( $topic['tags'] ?? array() ) as $tag ) {
			$t = get_term_by( 'name', $tag, 'post_tag' );
			if ( term_exists( $tag, 'category' ) || ( $t instanceof \WP_Term && ! in_array( \PaydarWP\PaydarPress\Profile\TaxonomyMap::role( (int) $t->term_id ), \PaydarWP\PaydarPress\Profile\TaxonomyMap::ACTIVE_ROLES, true ) ) ) {
				continue;
			}
			$tags[] = $tag;
		}
		$topic['tags'] = array_slice( $tags, 0, \PaydarWP\PaydarPress\Profile\TaxonomyMap::MAX_TAGS );

		$plan    = (array) ( $topic['link_plan'] ?? array() );
		$dropped = 0;
		foreach ( array( 'pillar_url', 'key_page_url' ) as $k ) {
			if ( '' !== (string) ( $plan[ $k ] ?? '' ) && 'ok' !== $this->resolve_url( (string) $plan[ $k ] )['state'] ) {
				$plan[ $k ] = '';
				++$dropped;
			}
		}
		$related = array();
		foreach ( (array) ( $plan['related_urls'] ?? array() ) as $u ) {
			if ( 'ok' === $this->resolve_url( (string) $u )['state'] ) {
				$related[] = $u;
			} else {
				++$dropped;
			}
		}
		$plan['related_urls'] = $related;
		$topic['link_plan']   = array_filter( $plan );
		if ( $dropped ) {
			$checks[] = array( 'info', sprintf( '%s آدرس از برنامه لینک کنار گذاشته شد (در سایت نیست یا منتشر نشده).', PersianNormalizer::fa_number( $dropped ) ) );
		}
		return $topic;
	}

	/**
	 * Checks a cluster map.
	 *
	 * @param int   $index      Index.
	 * @param array $cp         Parsed plan.
	 * @param array $categories Categories.
	 * @param array $topics_db  Existing topics.
	 * @param array $seen       Titles in this import.
	 */
	private function check_cluster_plan( int $index, array $cp, array $categories, array $topics_db, array $seen ): array {
		$checks   = array();
		$existing = \PaydarWP\PaydarPress\Strategy\Clusters::find_by_name( $cp['name'] );
		$category = $this->resolve_category( null, $cp['category'], $categories );
		$pillar   = 0;
		if ( '' !== $cp['pillar_url'] ) {
			$r = $this->resolve_url( $cp['pillar_url'] );
			if ( 'ok' === $r['state'] && $r['id'] && 'post' === get_post_type( (int) $r['id'] ) ) {
				$pillar = (int) $r['id'];
			} else {
				$checks[] = array( 'warning', 'مقاله ستون پیشنهادی در سایت پیدا نشد.' );
			}
		}
		$members    = array();
		$new_topics = array();
		$subs       = $cp['subtopics'];
		if ( ! $pillar && '' !== $cp['pillar_title'] ) {
			array_unshift( $subs, array( 'title' => $cp['pillar_title'], 'focus_keyword' => $cp['main_keyword'], 'intent' => 'educational', 'existing_url' => '', 'tags' => array(), 'notes' => 'مقاله ستون خوشه: راهنمای جامع که به همه زیرموضوع‌ها لینک می‌دهد.', 'pillar' => true ) );
		}
		foreach ( $subs as $sub ) {
			if ( '' !== $sub['existing_url'] ) {
				$r = $this->resolve_url( $sub['existing_url'] );
				if ( 'ok' === $r['state'] && $r['id'] && 'post' === get_post_type( (int) $r['id'] ) ) {
					$members[] = array( 'post_id' => (int) $r['id'], 'title' => html_entity_decode( get_the_title( (int) $r['id'] ), ENT_QUOTES, 'UTF-8' ), 'subtopic' => '' !== $sub['title'] ? $sub['title'] : $sub['focus_keyword'] );
					continue;
				}
			}
			if ( '' === $sub['title'] ) {
				continue;
			}
			$dup          = $this->duplicate_checks( $sub['title'], $sub['focus_keyword'], $topics_db, $seen );
			$seen[]       = array( $sub['title'], $sub['focus_keyword'] );
			$new_topics[] = array(
				'title'         => $sub['title'],
				'focus_keyword' => $sub['focus_keyword'],
				'intent'        => $sub['intent'],
				'tags'          => array_slice( $sub['tags'], 0, \PaydarWP\PaydarPress\Profile\TaxonomyMap::MAX_TAGS ),
				'notes'         => $sub['notes'],
				'pillar'        => ! empty( $sub['pillar'] ),
				'duplicate'     => (bool) array_filter( $dup, static fn( $c ) => 'duplicate' === $c[0] ),
			);
		}
		$dups = count( array_filter( $new_topics, static fn( $t ) => $t['duplicate'] ) );
		$checks[] = array( 'info', sprintf( '%s %s · %s مقاله موجود عضو خوشه می‌شود · %s موضوع تازه%s · ستون: %s', $existing ? 'تکمیل خوشه موجود' : 'خوشه تازه', '«' . $cp['name'] . '»', PersianNormalizer::fa_number( count( $members ) ), PersianNormalizer::fa_number( count( $new_topics ) - $dups ), $dups ? ' (' . PersianNormalizer::fa_number( $dups ) . ' تکراری کنار گذاشته می‌شود)' : '', $pillar ? '«' . html_entity_decode( get_the_title( $pillar ), ENT_QUOTES, 'UTF-8' ) . '»' : ( '' !== $cp['pillar_title'] ? 'مقاله تازه «' . $cp['pillar_title'] . '»' : 'تعیین نشده' ) ) );
		if ( $members ) {
			$checks[] = array( 'info', 'مقاله‌های موجود: ' . implode( '، ', array_map( static fn( $m ) => $m['title'], array_slice( $members, 0, 10 ) ) ) );
		}
		if ( $new_topics ) {
			$checks[] = array( 'info', 'موضوع‌های تازه: ' . implode( '، ', array_map( static fn( $t ) => $t['title'] . ( $t['duplicate'] ? ' (تکراری)' : '' ), $new_topics ) ) );
		}
		return $this->with_checks(
			$cp + array(
				'index'       => $index,
				'cluster_id'  => $existing ? (int) $existing['id'] : null,
				'category_id' => $category['id'],
				'pillar_id'   => $pillar,
				'members'     => $members,
				'new_topics'  => $new_topics,
				'title'       => $cp['name'],
			),
			$checks
		);
	}

	/**
	 * Checks category and tag suggestions for existing items (add only).
	 *
	 * @param array $items Parsed classifications.
	 * @return array{items: array[], new_terms: array[]}
	 */
	private function check_classifications( array $items ): array {
		$map   = \PaydarWP\PaydarPress\Profile\TaxonomyMap::class;
		$cls   = \PaydarWP\PaydarPress\Strategy\Classifier::class;
		$out   = array();
		$new   = array();
		$norm  = static fn( string $v ): string => preg_replace( '/(\s*ها|\s*های)$/u', '', PersianNormalizer::for_key( $v ) );
		$known = array();
		foreach ( $items as $index => $c ) {
			$post = get_post( (int) $c['id'] );
			$checks = array();
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'product' ), true ) ) {
				$out[] = $this->with_checks(
					array(
						'index'   => $index,
						'post_id' => (int) $c['id'],
						'title'   => '#' . (int) $c['id'],
						'kind'    => '',
					),
					array( array( 'error', 'مطلب منتشرشده‌ای با این شناسه پیدا نشد.' ) )
				);
				continue;
			}
			$kind = 'product' === $post->post_type ? 'product' : 'post';
			$k    = $cls::KINDS[ $kind ];
			if ( ! isset( $known[ $kind ] ) ) {
				$known[ $kind ] = array();
				foreach ( array( $k['cat'], $k['tag'] ) as $tax ) {
					foreach ( taxonomy_exists( $tax ) ? (array) get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 0 ) ) : array() as $t ) {
						if ( $t instanceof \WP_Term ) {
							$known[ $kind ][ $tax ][ $norm( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) ) ] = $t;
						}
					}
				}
			}
			$active  = static fn( \WP_Term $t ): bool => in_array( $map::role( (int) $t->term_id ), $map::ACTIVE_ROLES, true );
			$default = 'category' === $k['cat'] ? (int) get_option( 'default_category' ) : 0;
			$cur_cat = wp_get_post_terms( $post->ID, $k['cat'], array( 'fields' => 'ids' ) );
			$cur_tag = taxonomy_exists( $k['tag'] ) ? wp_get_post_terms( $post->ID, $k['tag'], array( 'fields' => 'ids' ) ) : array();
			$cur_cat = is_array( $cur_cat ) ? array_map( 'intval', $cur_cat ) : array();
			$cur_tag = is_array( $cur_tag ) ? array_map( 'intval', $cur_tag ) : array();

			$add_cat = array();
			$skipped = array();
			foreach ( $c['add_categories'] as $name ) {
				$t = $known[ $kind ][ $k['cat'] ][ $norm( $name ) ] ?? null;
				if ( ! $t || (int) $t->term_id === $default || ! $active( $t ) ) {
					$skipped[] = $name;
				} elseif ( ! in_array( (int) $t->term_id, $cur_cat, true ) ) {
					$add_cat[ (int) $t->term_id ] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				}
			}
			$add_cat = array_slice( $add_cat, 0, 2, true );

			$room    = max( 0, $map::max_tags( $k['tag'] ) - count( $cur_tag ) );
			$add_tag = array();
			$new_tag = array();
			foreach ( $c['add_tags'] as $name ) {
				$t = $known[ $kind ][ $k['tag'] ][ $norm( $name ) ] ?? null;
				if ( ! $t || ! $active( $t ) || isset( $known[ $kind ][ $k['cat'] ][ $norm( $name ) ] ) ) {
					$skipped[] = $name;
				} elseif ( ! in_array( (int) $t->term_id, $cur_tag, true ) ) {
					$add_tag[ (int) $t->term_id ] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				}
			}
			foreach ( array_slice( $c['new_tags'], 0, 1 ) as $name ) {
				$key = $norm( $name );
				if ( isset( $known[ $kind ][ $k['tag'] ][ $key ] ) ) {
					$t = $known[ $kind ][ $k['tag'] ][ $key ];
					if ( $active( $t ) && ! in_array( (int) $t->term_id, $cur_tag, true ) ) {
						$add_tag[ (int) $t->term_id ] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
					}
				} elseif ( isset( $known[ $kind ][ $k['cat'] ][ $key ] ) || mb_strlen( $name ) > 40 || mb_strlen( $name ) < 2 || preg_match( '/[.!؟?]/u', $name ) ) {
					$skipped[] = $name;
				} elseif ( taxonomy_exists( $k['tag'] ) ) {
					$new_tag[] = $name;
				}
			}
			$add_tag = array_slice( $add_tag, 0, $room, true );
			$new_tag = count( $add_tag ) < $room ? $new_tag : array();

			$primary_id   = 0;
			$primary_name = '';
			$primary_from = '';
			if ( 'post' === $kind && '' !== $c['primary_category'] ) {
				$t = $known[ $kind ][ $k['cat'] ][ $norm( $c['primary_category'] ) ] ?? null;
				$seo_primary = (int) ( \PaydarWP\PaydarPress\Seo\SeoDetector::adapter()->post( $post->ID )['primary_term_id'] ?? 0 );
				if ( $t && $active( $t ) && (int) $t->term_id !== $default && (int) $t->term_id !== $seo_primary && 'none' !== \PaydarWP\PaydarPress\Seo\SeoDetector::adapter()->key() ) {
					$primary_id   = (int) $t->term_id;
					$primary_name = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
					$from         = $seo_primary > 0 ? get_term( $seo_primary ) : null;
					$primary_from = $from instanceof \WP_Term ? html_entity_decode( $from->name, ENT_QUOTES, 'UTF-8' ) : '';
				}
			}

			$misfit = array();
			foreach ( $c['misfit'] as $m ) {
				foreach ( array( $k['cat'], $k['tag'] ) as $tax ) {
					$t = $known[ $kind ][ $tax ][ $norm( $m['term'] ) ] ?? null;
					if ( $t && in_array( (int) $t->term_id, array_merge( $cur_cat, $cur_tag ), true ) ) {
						$misfit[] = array(
							'term_id' => (int) $t->term_id,
							'name'    => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
							'reason'  => $m['reason'],
						);
						break;
					}
				}
			}

			foreach ( $new_tag as $name ) {
				$key = $k['tag'] . '|' . $norm( $name );
				if ( ! isset( $new[ $key ] ) ) {
					$new[ $key ] = array(
						'name'     => $name,
						'taxonomy' => $k['tag'],
						'posts'    => array(),
					);
				}
				$new[ $key ]['posts'][] = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
			}

			$noop = ! $add_cat && ! $add_tag && ! $new_tag && ! $primary_id;
			if ( $noop ) {
				$checks[] = array( 'info', 'تغییری لازم نیست' . ( $misfit ? '؛ موارد نامرتبط برای تصمیم شما ثبت می‌شوند.' : '.' ) );
			}
			if ( $primary_id && \PaydarWP\PaydarPress\Strategy\Classifier::category_in_url( $kind ) ) {
				$checks[] = array( 'warning', 'دسته اصلی در آدرس این مطلب است؛ با این تغییر، آدرس طبق وردپرس عوض می‌شود و آدرس قبلی با ۳۰۱ به آدرس جدید می‌رود.' );
			}
			if ( $skipped ) {
				$checks[] = array( 'info', 'نادیده گرفته شد (نیست، کنار گذاشته یا نامعتبر): ' . implode( '، ', array_unique( $skipped ) ) );
			}
			if ( $misfit ) {
				$checks[] = array( 'info', 'نامرتبط به نظر می‌رسد (حذف نمی‌شود؛ برای تصمیم شما ثبت می‌شود): ' . implode( '، ', array_map( static fn( $m ) => $m['name'] . ( '' !== $m['reason'] ? ' — ' . $m['reason'] : '' ), $misfit ) ) );
			}
			if ( 'product' === $kind ) {
				$checks[] = array( 'info', 'محصول: دسته‌ها روی منو و فیلتر فروشگاه اثر دارند؛ پیش از ورود بررسی کنید.' );
			}

			$out[] = $this->with_checks(
				array(
					'index'          => $index,
					'post_id'        => $post->ID,
					'title'          => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'url'            => (string) get_permalink( $post ),
					'kind'           => $kind,
					'add_cat_ids'    => array_keys( $add_cat ),
					'add_cat_names'  => array_values( $add_cat ),
					'add_tag_ids'    => array_keys( $add_tag ),
					'add_tag_names'  => array_values( $add_tag ),
					'new_tags'       => $new_tag,
					'primary_id'     => $primary_id,
					'primary_name'   => $primary_name,
					'primary_from'   => $primary_from,
					'misfit'         => $misfit,
					'noop'           => $noop,
					// Products and new tags are never pre-selected.
					'manual'         => 'product' === $kind,
				),
				$checks
			);
		}

		$terms = array();
		$i     = 0;
		foreach ( $new as $nt ) {
			$terms[] = $this->with_checks(
				$nt + array(
					'index'  => $i++,
					'count'  => count( $nt['posts'] ),
					'manual' => true,
				),
				array( array( 'info', 'برچسب تازه فقط با تأیید شما ساخته می‌شود؛ برای: ' . implode( '، ', array_slice( $nt['posts'], 0, 5 ) ) . ( count( $nt['posts'] ) > 5 ? '…' : '' ) ) )
			);
		}
		return array(
			'items'     => $out,
			'new_terms' => $terms,
		);
	}

	/**
	 * Focus keyword stored in the inventory.
	 *
	 * @param int $post_id Post.
	 */
	private static function focus_keyword_of( int $post_id ): string {
		global $wpdb;
		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT focus_keyword FROM {$table} WHERE object_id = %d", $post_id ) );
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
		\PaydarWP\PaydarPress\Strategy\ChangeLog::record( 'term', (int) $term->term_id, 'متن معرفی تازه', 'term' );
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
		\PaydarWP\PaydarPress\Strategy\ChangeLog::record( 'meta', $id, 'عنوان سئو: «' . $u['seo_title'] . '»' );
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
