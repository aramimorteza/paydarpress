<?php
/**
 * "This week's tasks": one prioritised, finite list built from every part of PaydarPress and
 * limited by the content policy, so the owner always knows what to do next.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Tasks;

use PaydarWP\PaydarPress\Ai\PromptBuilder;
use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Gsc\GscRepository;
use PaydarWP\PaydarPress\Import\ImportService;
use PaydarWP\PaydarPress\Policy\ReviewGate;
use PaydarWP\PaydarPress\Profile\ProfileRepository;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Tasks are recomputed from current data; the owner's "done" and "later" marks are kept in an
 * option and hide a task for a while (it returns if the problem is still there).
 */
final class TaskBoard {

	public const OPTION    = 'paydarpress_tasks';
	public const CACHE     = 'paydarpress_tasks_cache';
	public const WEEK_SIZE = 7;

	private const HIDE_DONE_DAYS   = 14;
	private const HIDE_LATER_DAYS  = 7;

	/**
	 * Board for the UI.
	 *
	 * @return array{week: array, later: array, done_week: int, policy: array}
	 */
	public static function board(): array {
		$state = self::state();
		$now   = time();
		$open  = array();
		$seen  = array();
		foreach ( self::build() as $task ) {
			// The same item can come from two sources (e.g. Search Console and refresh score).
			if ( isset( $seen[ $task['key'] ] ) ) {
				continue;
			}
			$seen[ $task['key'] ] = true;
			$mark = $state[ $task['key'] ] ?? null;
			if ( $mark && (int) $mark['until'] > $now ) {
				continue;
			}
			$open[] = $task;
		}
		usort( $open, static fn( $a, $b ) => array( $b['impact'], $a['minutes'] ) <=> array( $a['impact'], $b['minutes'] ) );

		// Variety: at most 3 tasks of one kind in the week, so one long kind of work does not
		// push quick wins out of sight; the rest stay in "later".
		$week  = array();
		$later = array();
		$per   = array();
		foreach ( $open as $task ) {
			$per[ $task['type'] ] = ( $per[ $task['type'] ] ?? 0 ) + 1;
			if ( count( $week ) < self::WEEK_SIZE && $per[ $task['type'] ] <= 3 ) {
				$week[] = $task;
			} else {
				$later[] = $task;
			}
		}

		$done_week = count( array_filter( $state, static fn( $m ) => 'done' === $m['state'] && (int) $m['at'] > $now - 7 * DAY_IN_SECONDS ) );
		$result    = array(
			'week'      => $week,
			'later'     => array_slice( $later, 0, 30 ),
			'done_week' => $done_week,
			'policy'    => PromptBuilder::policy(),
			'stage'     => \PaydarWP\PaydarPress\Policy\SiteStage::info(),
		);
		set_transient( self::CACHE, count( $result['week'] ), HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Number of tasks this week (cached; for the menu badge).
	 */
	public static function count(): int {
		$cached = get_transient( self::CACHE );
		return false === $cached ? count( self::board()['week'] ) : (int) $cached;
	}

	/**
	 * Marks a task done or for later, or reopens it.
	 *
	 * @param string $key    Task key.
	 * @param string $action done|later|reopen.
	 */
	public static function mark( string $key, string $action ): void {
		$key   = substr( preg_replace( '/[^a-z0-9_:\-]/i', '', $key ), 0, 80 );
		$state = self::state();
		if ( 'reopen' === $action ) {
			unset( $state[ $key ] );
		} else {
			$state[ $key ] = array(
				'state' => 'done' === $action ? 'done' : 'later',
				'at'    => time(),
				'until' => time() + ( 'done' === $action ? self::HIDE_DONE_DAYS : self::HIDE_LATER_DAYS ) * DAY_IN_SECONDS,
			);
		}
		// Keep the option small: forget marks older than a month.
		$state = array_filter( $state, static fn( $m ) => (int) $m['at'] > time() - 30 * DAY_IN_SECONDS );
		update_option( self::OPTION, $state, false );
		delete_transient( self::CACHE );
	}

	/**
	 * Stored marks.
	 *
	 * @return array<string, array{state: string, at: int, until: int}>
	 */
	private static function state(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * All candidate tasks.
	 */
	public static function build(): array {
		$tasks = array();
		$stats = get_option( AuditEngine::OPTION_STATS, array() );
		$stats = is_array( $stats ) ? $stats : array();

		// 1. Setup.
		if ( empty( $stats['audited_at'] ) ) {
			$tasks[] = self::task( 'setup:scan', 'setup', 'اولین اسکن سایت را اجرا کنید', 'بدون اسکن، پایدارپرس محتوای سایت را نمی‌شناسد و هیچ پیشنهادی نمی‌دهد.', 100, 2, array( 'route' => '/', 'label' => 'شروع اسکن' ) );
		}
		$completion = ProfileRepository::completion();
		if ( $completion['percent'] < 100 ) {
			$tasks[] = self::task( 'setup:profile', 'setup', 'پروفایل سایت را کامل کنید', sprintf( 'پروفایل %s٪ کامل است. همه پرامپت‌ها و پیشنهادها از پروفایل استفاده می‌کنند؛ پروفایل ناقص یعنی محتوای کلی‌تر.', PersianNormalizer::fa_number( $completion['percent'] ) ), 95, 10, array( 'route' => '/profile', 'label' => 'تکمیل پروفایل' ) );
		}
		if ( ! GscRepository::has_data() ) {
			$tasks[] = self::task( 'setup:gsc', 'setup', 'خروجی ۳ ماهه سرچ کنسول را وارد کنید', 'فرصت‌های نزدیک صفحه اول، افت کلیک و جست‌وجوهای بی‌مقاله فقط با داده سرچ کنسول پیدا می‌شوند.', 80, 3, array( 'route' => '/gsc', 'label' => 'ورود فایل سرچ کنسول' ) );
		} elseif ( GscRepository::is_stale() ) {
			$tasks[] = self::task( 'setup:gsc-stale', 'setup', 'داده سرچ کنسول را تازه کنید', 'آخرین خروجی بیش از ۶۰ روز پیش وارد شده است؛ پیشنهادها بر اساس داده قدیمی هستند.', 60, 3, array( 'route' => '/gsc', 'label' => 'ورود خروجی تازه' ) );
		}

		// New sites: the pages that sell come before articles.
		if ( 'new' === \PaydarWP\PaydarPress\Policy\SiteStage::current() && count( ProfileRepository::key_page_ids() ) < 3 ) {
			$tasks[] = self::task( 'setup:key-pages', 'setup', 'صفحات کلیدی و صفحات خدمات/محصول را کامل کنید', 'سایت تازه است: پیش از مقاله‌نویسی، صفحه‌هایی که مشتری را به تماس یا خرید می‌رسانند (خدمات، محصول اصلی، تماس، درباره ما) باید کامل باشند و در پروفایل به‌عنوان صفحه کلیدی ثبت شوند؛ مقاله‌ها به آن‌ها لینک می‌دهند.', 92, 30, array( 'route' => '/profile', 'label' => 'صفحات کلیدی' ) );
		}

		// 2. Site-wide blockers.
		foreach ( self::findings( array( 'CRAWLER_BLOCKED', 'SITE_NOINDEX' ), 'site', 3 ) as $f ) {
			$tasks[] = self::task( 'site:' . $f['code'], 'fix', 'SITE_NOINDEX' === $f['code'] ? 'سایت برای موتورهای جست‌وجو بسته است' : 'خزنده‌های گوگل یا هوش مصنوعی مسدودند', 'تا این مشکل حل نشود، هیچ محتوایی در گوگل دیده نمی‌شود.', 99, 5, array( 'route' => '/visibility', 'label' => 'بررسی دسترسی' ) );
		}

		// 3. Drafts waiting for the owner.
		foreach ( self::imported_drafts() as $d ) {
			$review  = ReviewGate::needs_review( $d['id'] );
			$tasks[] = self::task(
				'draft:' . $d['id'],
				'publish',
				( $d['rewrite'] ? 'بازنویسی «' : 'بازبینی و انتشار «' ) . $d['title'] . '»',
				$review ? 'متن با هوش مصنوعی نوشته شده و سایت حساس است: پیش از انتشار با دقت بازبینی و «تأیید بازبینی» را بزنید.' : ( $d['rewrite'] ? 'نسخه تازه آماده است؛ بخوانید و «جایگزینی نوشته اصلی» را بزنید.' : 'پیش‌نویس آماده است؛ بخوانید، تصویر شاخص بگذارید و منتشر یا زمان‌بندی کنید.' ),
				90 - min( 20, $d['age_days'] ),
				20,
				array( 'url' => $d['edit_url'], 'label' => 'باز کردن در ویرایشگر' )
			);
		}

		// 4. Link queue.
		$pending = self::count_links( 'pending' );
		if ( $pending > 0 ) {
			$tasks[] = self::task( 'links:pending', 'links', sprintf( '%s پیشنهاد لینک داخلی را بررسی کنید', PersianNormalizer::fa_number( $pending ) ), 'هر پیشنهاد یک کلیک است: «درج»، «ویرایش متن» یا «رد». تا تأیید نکنید هیچ نوشته‌ای تغییر نمی‌کند.', 70, max( 3, min( 20, $pending ) ), array( 'route' => '/links', 'label' => 'صف لینک داخلی' ) );
		}
		$failed = self::count_links( 'failed' );
		if ( $failed > 0 ) {
			$tasks[] = self::task( 'links:failed', 'links', sprintf( '%s لینک درج نشد؛ دلیلش را ببینید', PersianNormalizer::fa_number( $failed ) ), 'معمولاً متن لنگر در مقاله نیست یا سقف سیاست محتوا پر شده؛ متن را اصلاح یا لینک را رد کنید.', 45, 5, array( 'route' => '/links?status=failed', 'label' => 'لینک‌های ناموفق' ) );
		}

		// 5. Google opportunities (highest impressions first).
		foreach ( self::findings( array( 'GSC_CLICK_DROP' ), 'content', 2 ) as $f ) {
			$tasks[] = self::content_task( $f, 'refresh', 'افت کلیک: «%s» را بررسی و بازنویسی کنید', 'کلیک این صفحه نسبت به دوره قبل دست‌کم ۳۰٪ کم شده است.', 85, 40 );
		}
		foreach ( self::findings( array( 'GSC_NEAR_PAGE_ONE' ), 'content', 3 ) as $f ) {
			$tasks[] = self::content_task( $f, 'refresh', '«%s» را به صفحه اول برسانید', 'جایگاه ۸ تا ۲۰ با نمایش کافی؛ با تکمیل متن و ۱ تا ۲ لینک داخلی بیشترین بازده را دارد.', 80, 40 );
		}
		foreach ( self::findings( array( 'GSC_LOW_CTR' ), 'content', 2 ) as $f ) {
			$tasks[] = self::content_task( $f, 'meta', 'عنوان و توضیحات متای «%s» را جذاب‌تر کنید', 'رتبه خوب دارد اما کمتر از ۲٪ بینندگان کلیک می‌کنند. ۵ دقیقه کار، اثر سریع.', 75, 5 );
		}

		// 6. Refresh share of the monthly capacity.
		$policy  = PromptBuilder::policy();
		$share   = (int) Settings::get( 'policy.refresh_share', 40 );
		$per_wk  = $share > 0 ? max( 1, (int) ceil( max( 1, $policy['budget'] ) * $share / 100 / 4 ) ) : 0;
		foreach ( $per_wk > 0 ? self::refresh_candidates( $per_wk ) : array() as $r ) {
			$tasks[] = self::task( 'refresh:' . $r['id'], 'refresh', 'بازنویسی «' . $r['title'] . '»', $r['why'], 55 + min( 20, (int) ( $r['score'] / 5 ) ), 40, array( 'url' => $r['edit_url'], 'label' => 'ویرایش' ), array( 'kind' => 'refresh', 'id' => $r['id'], 'title' => $r['title'], 'url' => $r['url'], 'type' => 'post', 'reasons' => $r['reasons'] ) );
		}

		// 7. Orphans, within the weekly link budget.
		$orphans = max( 1, (int) floor( (int) Settings::get( 'policy.links_per_week', 15 ) / 5 ) );
		foreach ( self::findings( array( 'ORPHAN_POST', 'ORPHAN_PAGE', 'ORPHAN_PRODUCT' ), 'content', $orphans ) as $f ) {
			$tasks[] = self::content_task( $f, 'support', 'به «%s» لینک بدهید', 'هیچ صفحه‌ای به آن لینک نمی‌دهد؛ گوگل و خواننده پیدایش نمی‌کنند. از ۲ تا ۳ مقاله مرتبط لینک بدهید یا مقاله پشتیبان بنویسید.', 50, 10, '/orphans' );
		}

		// 8. New content, within the monthly budget.
		$left = $policy['budget'] - $policy['published'];
		if ( $left > 0 ) {
			$week_new = max( 1, (int) ceil( $policy['budget'] / 4 ) );
			$topics   = self::topics( min( $left, $week_new ) );
			foreach ( $topics as $t ) {
				$tasks[] = self::task( 'topic:' . $t['id'], 'write', 'نوشتن مقاله «' . $t['title'] . '»', sprintf( 'موضوع %s. سهمیه این ماه: %s از %s مقاله.', 'accepted' === $t['status'] ? 'پذیرفته‌شده' : 'پیشنهادی', PersianNormalizer::fa_number( $policy['published'] ), PersianNormalizer::fa_number( $policy['budget'] ) ), 'accepted' === $t['status'] ? 48 : 40, 60, array( 'route' => '/topics', 'label' => 'موضوعات پیشنهادی' ), array( 'kind' => 'topic', 'id' => $t['id'], 'title' => $t['title'] ) );
			}
			// Money pages without enough supporting articles come first.
			foreach ( array_slice( array_filter( \PaydarWP\PaydarPress\Strategy\Structure::clusters(), static fn( $c ) => $c['gap'] > 0 ), 0, 2 ) as $c ) {
				$tasks[] = self::task( 'cluster:' . $c['id'], 'write', 'مقاله پشتیبان برای «' . $c['name'] . '»', sprintf( '%s %s مقاله پشتیبان دارد (هدف %s). هر مقاله پشتیبان یک پرسش مشتری را جواب می‌دهد و به این صفحه لینک می‌دهد؛ این صفحه را برای «%s» قوی‌تر می‌کند.', \PaydarWP\PaydarPress\Strategy\Structure::PAGE_TYPES[ $c['type'] ] ?? '', PersianNormalizer::fa_number( $c['supports'] ), PersianNormalizer::fa_number( $c['target'] ), '' !== $c['main_keyword'] ? $c['main_keyword'] : $c['name'] ), 'high' === $c['priority'] ? 52 : 46, 60, array( 'route' => '/profile', 'label' => 'صفحات کلیدی' ), array( 'kind' => 'support', 'id' => $c['id'], 'title' => $c['name'], 'url' => $c['url'], 'type' => 'page', 'reasons' => array() ) );
			}
			if ( ! $topics ) {
				$gap = array_values( array_filter( \PaydarWP\PaydarPress\Strategy\Structure::categories(), static fn( $c ) => $c['gap'] > 0 ) );
				if ( $gap ) {
					$c       = $gap[0];
					$tasks[] = self::task( 'category:' . $c['id'], 'write', 'موضوع تازه برای دسته «' . $c['name'] . '»', sprintf( 'این دسته %s مطلب دارد و هدفش %s است (اهمیت: %s). از هوش مصنوعی برای همین دسته موضوع بگیرید.', PersianNormalizer::fa_number( $c['count'] ), PersianNormalizer::fa_number( $c['target'] ), \PaydarWP\PaydarPress\Strategy\Structure::IMPORTANCE_LABELS[ $c['importance'] ] ), 38, 10, array( 'route' => '/taxonomies', 'label' => 'دسته‌ها' ), array( 'kind' => 'category', 'id' => $c['id'], 'title' => $c['name'] ) );
				}
			}
			if ( ! $topics && empty( $gap ) ) {
				$tasks[] = self::task( 'topics:new', 'write', 'موضوع تازه انتخاب کنید', sprintf( 'از سهمیه این ماه %s مقاله باقی است و موضوع پذیرفته‌شده‌ای ندارید. از جست‌وجوهای بی‌مقاله سرچ کنسول یا «پیشنهاد موضوع» شروع کنید.', PersianNormalizer::fa_number( $left ) ), 35, 10, array( 'route' => GscRepository::has_data() ? '/gsc?tab=gaps' : '/topics', 'label' => GscRepository::has_data() ? 'جست‌وجوهای بی‌مقاله' : 'موضوعات پیشنهادی' ), array( 'kind' => 'topics' ) );
			}
		}

		// 9. Batch fixes.
		$alt = (int) ( $stats['by_code']['IMG_MISSING_ALT'] ?? 0 );
		if ( $alt > 0 ) {
			$tasks[] = self::task( 'images:alt', 'fix', sprintf( 'برای تصاویر %s مطلب متن جایگزین بنویسید', PersianNormalizer::fa_number( $alt ) ), 'متن جایگزین به نابینایان، جست‌وجوی تصویری و فهم هوش مصنوعی از صفحه کمک می‌کند.', 30, 15, array( 'route' => '/images', 'label' => 'سلامت تصاویر' ) );
		}
		$desc = (int) ( $stats['by_code']['EMPTY_CATEGORY_DESC'] ?? 0 );
		if ( $desc > 0 ) {
			$tasks[] = self::task( 'terms:desc', 'fix', sprintf( 'برای %s دسته متن معرفی بنویسید', PersianNormalizer::fa_number( $desc ) ), 'صفحه دسته بدون متن، برای گوگل یک فهرست خالی است.', 28, 15, array( 'route' => '/taxonomies', 'label' => 'دسته‌ها و برچسب‌ها' ) );
		}

		return $tasks;
	}

	/**
	 * A task.
	 *
	 * @param string     $key     Stable key.
	 * @param string     $type    setup|fix|publish|links|refresh|meta|support|write.
	 * @param string     $title   Title.
	 * @param string     $why     Why it matters.
	 * @param int        $impact  0–100.
	 * @param int        $minutes Estimated minutes.
	 * @param array      $action  route|url, label.
	 * @param array|null $ai      AI command descriptor for the UI.
	 */
	private static function task( string $key, string $type, string $title, string $why, int $impact, int $minutes, array $action, ?array $ai = null ): array {
		return compact( 'key', 'type', 'title', 'why', 'impact', 'minutes', 'action', 'ai' );
	}

	/**
	 * Task about one content item from a finding.
	 *
	 * @param array  $f       Finding (object_id, code).
	 * @param string $kind    refresh|meta|support.
	 * @param string $title   Title pattern (%s = item title).
	 * @param string $why     Why.
	 * @param int    $impact  Impact.
	 * @param int    $minutes Minutes.
	 * @param string $route   Page route instead of the editor.
	 */
	private static function content_task( array $f, string $kind, string $title, string $why, int $impact, int $minutes, string $route = '' ): array {
		$id   = (int) $f['object_id'];
		$name = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
		return self::task(
			$kind . ':' . $id,
			$kind,
			sprintf( $title, $name ),
			$why,
			$impact,
			$minutes,
			'' !== $route ? array( 'route' => $route, 'label' => 'محتوای یتیم' ) : array( 'url' => (string) get_edit_post_link( $id, 'raw' ), 'label' => 'ویرایش' ),
			array(
				'kind'    => $kind,
				'id'      => $id,
				'title'   => $name,
				'url'     => (string) get_permalink( $id ),
				'type'    => (string) get_post_type( $id ),
				'reasons' => array(),
			)
		);
	}

	/**
	 * Open findings, most important objects first (Search Console impressions, then id).
	 *
	 * @param string[] $codes Codes.
	 * @param string   $kind  Object kind.
	 * @param int      $limit Limit.
	 */
	private static function findings( array $codes, string $kind, int $limit ): array {
		global $wpdb;
		$table = Schema::table( 'audit_findings' );
		$in    = "'" . implode( "','", array_map( 'esc_sql', $codes ) ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT code, object_id, data FROM {$table} WHERE code IN ({$in}) AND object_kind = %s AND state = 'open' ORDER BY id LIMIT 200", $kind ), ARRAY_A );
		if ( 'content' === $kind && $rows ) {
			$metrics = GscRepository::page_metrics();
			usort( $rows, static fn( $a, $b ) => ( $metrics[ (int) $b['object_id'] ]['impressions'] ?? 0 ) <=> ( $metrics[ (int) $a['object_id'] ]['impressions'] ?? 0 ) );
		}
		return array_slice( (array) $rows, 0, $limit );
	}

	/**
	 * Drafts created by imports that are not published yet.
	 */
	private static function imported_drafts(): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page', 'product' ),
				'post_status'    => array( 'draft', 'pending' ),
				'posts_per_page' => 5,
				'meta_key'       => ImportService::META_IMPORT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		return array_map(
			static fn( $p ) => array(
				'id'       => $p->ID,
				'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'edit_url' => (string) get_edit_post_link( $p->ID, 'raw' ),
				'rewrite'  => '' !== (string) get_post_meta( $p->ID, '_paydarpress_rewrite_of', true ),
				'age_days' => (int) floor( ( time() - (int) get_post_modified_time( 'U', true, $p ) ) / DAY_IN_SECONDS ),
			),
			$posts
		);
	}

	/**
	 * Link suggestions by status.
	 *
	 * @param string $status Status.
	 */
	private static function count_links( string $status ): int {
		global $wpdb;
		$table = Schema::table( 'link_suggestions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}

	/**
	 * Highest refresh scores.
	 *
	 * @param int $limit Limit.
	 */
	private static function refresh_candidates( int $limit ): array {
		global $wpdb;
		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, refresh_score FROM {$table} WHERE status = 'publish' AND refresh_score >= 20 ORDER BY refresh_score DESC LIMIT %d", $limit ), ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'id'       => (int) $r['object_id'],
				'title'    => html_entity_decode( get_the_title( (int) $r['object_id'] ), ENT_QUOTES, 'UTF-8' ),
				'url'      => (string) get_permalink( (int) $r['object_id'] ),
				'edit_url' => (string) get_edit_post_link( (int) $r['object_id'], 'raw' ),
				'score'    => (int) $r['refresh_score'],
				'why'      => sprintf( 'امتیاز بازنویسی %s از ۱۰۰ (کهنگی، کم‌حجمی یا مشکلات سئو). بهتر کردن محتوای موجود معمولاً از مقاله جدید ارزش بیشتری دارد.', PersianNormalizer::fa_number( (int) $r['refresh_score'] ) ),
				'reasons'  => array(),
			),
			(array) $rows
		);
	}

	/**
	 * Accepted topics first, then new ones.
	 *
	 * @param int $limit Limit.
	 */
	private static function topics( int $limit ): array {
		global $wpdb;
		$table = Schema::table( 'topics' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title, status FROM {$table} WHERE status IN ('accepted','new') ORDER BY CASE status WHEN 'accepted' THEN 0 ELSE 1 END, id LIMIT %d", max( 1, $limit ) ), ARRAY_A );
	}
}
