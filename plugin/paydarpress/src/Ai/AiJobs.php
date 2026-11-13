<?php
/**
 * Automatic mode: sends a PromptBuilder prompt to the configured AI service in the background
 * and keeps the reply until the owner reviews it.
 *
 * The reply is never applied here. It goes through ImportService::preview() exactly like a
 * pasted reply (duplicate, link, slug and policy checks), and only what the owner ticks is
 * imported, always as drafts / pending items.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Ai;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Import\ImportParser;
use PaydarWP\PaydarPress\Queue\Queue;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Job states: queued → running → ready | failed; ready → imported | discarded.
 */
final class AiJobs {

	public const ACTION = 'paydarpress_ai_job';

	/** Jobs waiting or running at the same time (protects the monthly budget from double clicks). */
	public const MAX_ACTIVE = 3;

	/** A running job older than this is considered dead (PHP was killed). */
	private const STALE = 900;

	/** Seconds the background queue gets before the open screen runs the job itself. */
	private const INLINE_AFTER = 4;

	public const STATUS_LABELS = array(
		'queued'    => 'در صف',
		'running'   => 'در حال نوشتن',
		'ready'     => 'آماده بررسی',
		'failed'    => 'ناموفق',
		'imported'  => 'وارد شد',
		'discarded' => 'کنار گذاشته شد',
	);

	/**
	 * Registers the background action.
	 */
	public function register(): void {
		add_action( self::ACTION, array( self::class, 'run' ) );
	}

	/**
	 * Creates a job for a task (validates everything that can fail before spending a request).
	 *
	 * @param string $task Task (PromptBuilder::TASKS).
	 * @param array  $args Task arguments.
	 * @return array Public job view.
	 * @throws RuntimeException With a Persian message.
	 */
	public static function start( string $task, array $args ): array {
		global $wpdb;

		if ( null === Credentials::connection() ) {
			throw new RuntimeException( 'اتصال هوش مصنوعی تنظیم نشده است. در «تنظیمات ← هوش مصنوعی» کلید API و مدل را وارد کنید.' );
		}
		$prompt = PromptBuilder::build( $task, $args );
		Usage::assert_allowed( $task );

		$table = Schema::table( 'ai_jobs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','running')" );
		if ( $active >= self::MAX_ACTIVE ) {
			throw new RuntimeException( sprintf( '%s کار هوش مصنوعی در حال انجام است. صبر کنید یکی تمام شود و دوباره بزنید.', PersianNormalizer::fa_number( $active ) ) );
		}

		$same = self::find_active( $task, $args );
		if ( null !== $same ) {
			return $same;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'task'       => $task,
				'args'       => wp_json_encode( self::clean_args( $args ) ),
				'title'      => mb_substr( (string) $prompt['title'], 0, 250 ),
				'object_id'  => $prompt['object_id'],
				'status'     => 'queued',
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			throw new RuntimeException( 'ذخیره کار ممکن نشد؛ جدول‌های افزونه را بررسی کنید (غیرفعال و فعال‌سازی دوباره).' );
		}

		Queue::schedule( self::ACTION, array( $id ), 0, false );
		Activity::log( 'ai.job_started', sprintf( 'ساخت خودکار «%s» شروع شد.', $prompt['title'] ) );

		return self::get( $id );
	}

	/**
	 * Runs a job (background action, or the polling screen when the queue does not run).
	 *
	 * @param int $id Job id.
	 */
	public static function run( $id ): void {
		global $wpdb;
		$id    = (int) $id;
		$table = Schema::table( 'ai_jobs' );
		$now   = current_time( 'mysql', true );

		// Atomic claim: only one process runs a job.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', started_at = %s WHERE id = %d AND status = 'queued'", $now, $id ) );
		if ( 1 !== (int) $claimed ) {
			return;
		}

		$row = self::row( $id );
		if ( null === $row ) {
			return;
		}
		$task    = (string) $row['task'];
		$long    = in_array( $task, AiClient::ARTICLE_TASKS, true ) || 'topics' === $task;
		$started = microtime( true );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $long ? 420 : 200 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

		$fields   = array();
		$usage_id = 0;
		try {
			$prompt = PromptBuilder::build( $task, (array) json_decode( (string) $row['args'], true ) );
			$result = ( new AiClient() )->complete(
				$task,
				$prompt['system'],
				$prompt['user'],
				array(
					'json'      => true,
					'object_id' => $prompt['object_id'],
					'timeout'   => $long ? 360 : 180,
				)
			);
			$usage_id = (int) ( $result['usage_id'] ?? 0 );
			$fields   = array(
				'response'      => $result['text'],
				'model'         => mb_substr( (string) $result['model'], 0, 120 ),
				'input_tokens'  => (int) $result['input_tokens'],
				'output_tokens' => (int) $result['output_tokens'],
			);

			if ( ! empty( $result['truncated'] ) ) {
				throw new RuntimeException(
					sprintf(
						'پاسخ به سقف طول خروجی (%s توکن) رسید و ناقص ماند. در «تنظیمات ← هوش مصنوعی» حداکثر توکن خروجی را بیشتر کنید (برای مقاله کامل دست‌کم ۱۶۰۰۰) و دوباره بزنید.',
						PersianNormalizer::fa_number( (int) Settings::get( 'ai.max_output_tokens', 16000 ) )
					)
				);
			}
			self::validate( $task, $result['text'] );

			$fields['status'] = 'ready';
			Activity::log( 'ai.job_ready', sprintf( 'پاسخ هوش مصنوعی برای «%s» آماده بررسی است (%s ثانیه).', $row['title'], PersianNormalizer::fa_number( (int) round( microtime( true ) - $started ) ) ) );
		} catch ( Throwable $e ) {
			$fields['status'] = 'failed';
			$fields['error']  = mb_substr( $e->getMessage(), 0, 1000 );
			Usage::mark_unusable( $usage_id, $e->getMessage() );
			Activity::log( 'ai.job_failed', sprintf( 'ساخت خودکار «%s» ناموفق بود: %s', $row['title'], mb_substr( $e->getMessage(), 0, 200 ) ) );
		}

		$fields['finished_at'] = current_time( 'mysql', true );
		$wpdb->update( $table, $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Checks that the reply can be imported and contains what the task asked for.
	 *
	 * @param string $task Task.
	 * @param string $text Reply.
	 * @throws RuntimeException When it cannot be used.
	 */
	public static function validate( string $task, string $text ): void {
		try {
			$parsed = ImportParser::parse( $text );
		} catch ( RuntimeException $e ) {
			if ( 0 === strpos( $e->getMessage(), 'هوش مصنوعی چیزی برای ورود نساخت' ) ) {
				throw $e;
			}
			throw new RuntimeException( 'پاسخ هوش مصنوعی قالب درست نداشت و قابل ورود نیست. دوباره بزنید؛ اگر تکرار شد مدل قوی‌تری انتخاب کنید. (' . mb_substr( $e->getMessage(), 0, 160 ) . ')' );
		}

		$need = array(
			'topics'    => array( 'topics' ),
			'article'   => array( 'articles' ),
			'rewrite'   => array( 'articles' ),
			'merge'     => array( 'articles' ),
			'term'      => array( 'term_descriptions' ),
			'meta'      => array( 'seo_updates' ),
			'structure' => array( 'structure_suggestions' ),
		);
		$sections = $need[ $task ] ?? array();
		foreach ( $sections as $section ) {
			if ( ! empty( $parsed[ $section ] ) ) {
				return;
			}
		}
		if ( array() !== $sections ) {
			$note = trim( (string) ( $parsed['notes'] ?? '' ) );
			throw new RuntimeException( 'پاسخ هوش مصنوعی موردی برای ورود نداشت.' . ( '' !== $note ? ' توضیح هوش مصنوعی: ' . mb_substr( $note, 0, 400 ) : '' ) );
		}
	}

	/**
	 * Job state for polling. Runs the job in this request when the background queue has not
	 * picked it up (hosts without working WP-Cron), and fails jobs whose process died.
	 *
	 * @param int $id Job id.
	 * @return array|null
	 */
	public static function poll( int $id ): ?array {
		global $wpdb;
		$row = self::row( $id );
		if ( null === $row ) {
			return null;
		}

		$age = time() - strtotime( $row['created_at'] . ' UTC' );
		if ( 'queued' === $row['status'] && $age >= self::INLINE_AFTER ) {
			self::run( $id );
		} elseif ( 'running' === $row['status'] && null !== $row['started_at'] && time() - strtotime( $row['started_at'] . ' UTC' ) > self::STALE ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::table( 'ai_jobs' ),
				array(
					'status'      => 'failed',
					'error'       => 'اجرای کار نیمه‌کاره متوقف شد (احتمالاً محدودیت زمان اجرای هاست). دوباره بزنید.',
					'finished_at' => current_time( 'mysql', true ),
				),
				array(
					'id'     => $id,
					'status' => 'running',
				)
			);
		}

		return self::get( $id );
	}

	/**
	 * Raw reply of a job that is ready for review.
	 *
	 * @param int $id Job id.
	 * @throws RuntimeException When there is nothing to review.
	 */
	public static function response( int $id ): string {
		$row = self::row( $id );
		if ( null === $row || ! in_array( $row['status'], array( 'ready', 'imported' ), true ) || '' === (string) $row['response'] ) {
			throw new RuntimeException( 'پاسخی برای بررسی پیدا نشد.' );
		}
		return (string) $row['response'];
	}

	/**
	 * Marks a job as imported (after commit) or discarded.
	 *
	 * @param int    $id     Job id.
	 * @param string $status imported|discarded.
	 */
	public static function close( int $id, string $status ): void {
		global $wpdb;
		if ( ! in_array( $status, array( 'imported', 'discarded' ), true ) ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'ai_jobs' ),
			array(
				'status'      => $status,
				'imported_at' => 'imported' === $status ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Retries a failed job (a new request, counted again).
	 *
	 * @param int $id Job id.
	 * @return array
	 * @throws RuntimeException When the job cannot be retried.
	 */
	public static function retry( int $id ): array {
		$row = self::row( $id );
		if ( null === $row || 'failed' !== $row['status'] ) {
			throw new RuntimeException( 'فقط کار ناموفق را می‌توان دوباره اجرا کرد.' );
		}
		self::close_failed( $id );
		return self::start( (string) $row['task'], (array) json_decode( (string) $row['args'], true ) );
	}

	/**
	 * Recent jobs (without the reply).
	 *
	 * @param int $limit Limit.
	 * @return array[]
	 */
	public static function recent( int $limit = 30 ): array {
		global $wpdb;
		$table = Schema::table( 'ai_jobs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status <> 'discarded' ORDER BY id DESC LIMIT %d", max( 1, min( 100, $limit ) ) ), ARRAY_A );
		return array_values( array_filter( array_map( static fn( $r ) => self::get( (int) $r['id'] ), (array) $rows ) ) );
	}

	/**
	 * Number of replies waiting for review.
	 */
	public static function ready_count(): int {
		global $wpdb;
		$table = Schema::table( 'ai_jobs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'ready'" );
	}

	/**
	 * Public view of a job (never the reply itself).
	 *
	 * @param int $id Job id.
	 */
	public static function get( int $id ): ?array {
		$row = self::row( $id );
		if ( null === $row ) {
			return null;
		}
		return array(
			'id'            => (int) $row['id'],
			'task'          => $row['task'],
			'task_label'    => AiClient::TASK_LABELS[ $row['task'] ] ?? $row['task'],
			'title'         => $row['title'],
			'object_id'     => null !== $row['object_id'] ? (int) $row['object_id'] : null,
			'status'        => $row['status'],
			'status_label'  => self::STATUS_LABELS[ $row['status'] ] ?? $row['status'],
			'error'         => $row['error'],
			'model'         => $row['model'],
			'input_tokens'  => (int) $row['input_tokens'],
			'output_tokens' => (int) $row['output_tokens'],
			'created_at'    => self::iso( $row['created_at'] ),
			'started_at'    => self::iso( $row['started_at'] ),
			'finished_at'   => self::iso( $row['finished_at'] ),
			'seconds'       => $row['finished_at'] && $row['started_at'] ? max( 0, strtotime( $row['finished_at'] ) - strtotime( $row['started_at'] ) ) : null,
		);
	}

	/**
	 * Deletes old jobs (replies may hold full articles; no reason to keep them long).
	 *
	 * @param int $days Retention.
	 */
	public static function prune( int $days ): void {
		global $wpdb;
		$table  = Schema::table( 'ai_jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 30, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s AND status <> 'ready'", $cutoff ) );
	}

	/**
	 * Active job with the same task and arguments (double click protection).
	 *
	 * @param string $task Task.
	 * @param array  $args Args.
	 */
	private static function find_active( string $task, array $args ): ?array {
		global $wpdb;
		$table = Schema::table( 'ai_jobs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE task = %s AND args = %s AND status IN ('queued','running') ORDER BY id DESC LIMIT 1", $task, wp_json_encode( self::clean_args( $args ) ) ) );
		return $id ? self::get( (int) $id ) : null;
	}

	/**
	 * Keeps only scalar task arguments (ids, short text).
	 *
	 * @param array $args Args.
	 */
	private static function clean_args( array $args ): array {
		$out = array();
		foreach ( $args as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( is_scalar( $v ) ) {
				$out[ $key ] = is_string( $v ) ? mb_substr( sanitize_text_field( $v ), 0, 300 ) : $v;
			} elseif ( is_array( $v ) ) {
				$out[ $key ] = array_slice( array_values( array_map( static fn( $x ) => mb_substr( sanitize_text_field( (string) $x ), 0, 200 ), array_filter( $v, 'is_scalar' ) ) ), 0, 10 );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Hides a failed job once it has been retried.
	 *
	 * @param int $id Job id.
	 */
	private static function close_failed( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'ai_jobs' ), array( 'status' => 'discarded' ), array( 'id' => $id, 'status' => 'failed' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * UTC MySQL datetime → ISO 8601 for the browser.
	 *
	 * @param string|null $utc Datetime.
	 */
	private static function iso( ?string $utc ): ?string {
		return $utc ? gmdate( 'c', (int) strtotime( $utc . ' UTC' ) ) : null;
	}

	/**
	 * Raw row.
	 *
	 * @param int $id Job id.
	 */
	private static function row( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'ai_jobs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
