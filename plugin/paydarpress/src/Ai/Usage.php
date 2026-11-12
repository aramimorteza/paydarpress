<?php
/**
 * AI usage log and monthly limits.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Ai;

use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Months follow the site's time zone. A limit of 0 turns that kind of request off.
 */
final class Usage {

	/**
	 * Start of the current month in UTC (for comparing with created_at).
	 */
	public static function month_start_utc(): string {
		$local = new \DateTimeImmutable( 'first day of this month 00:00:00', wp_timezone() );
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Counts this month.
	 *
	 * @return array{requests: int, articles: int, input_tokens: int, output_tokens: int, errors: int}
	 */
	public static function month(): array {
		global $wpdb;
		$table = Schema::table( 'ai_usage' );
		$tasks = "'" . implode( "','", AiClient::ARTICLE_TASKS ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS requests,
					SUM(CASE WHEN status = 'ok' AND task IN ({$tasks}) THEN 1 ELSE 0 END) AS articles,
					SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens,
					SUM(CASE WHEN status <> 'ok' THEN 1 ELSE 0 END) AS errors
				FROM {$table} WHERE created_at >= %s AND task <> 'test'",
				self::month_start_utc()
			),
			ARRAY_A
		);
		return array(
			'requests'      => (int) ( $row['requests'] ?? 0 ),
			'articles'      => (int) ( $row['articles'] ?? 0 ),
			'input_tokens'  => (int) ( $row['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $row['output_tokens'] ?? 0 ),
			'errors'        => (int) ( $row['errors'] ?? 0 ),
		);
	}

	/**
	 * Throws when the request would exceed a monthly limit.
	 *
	 * @param string $task Task key.
	 * @throws RuntimeException When over the limit.
	 */
	public static function assert_allowed( string $task ): void {
		if ( 'test' === $task ) {
			return;
		}
		$month    = self::month();
		$requests = (int) Settings::get( 'ai.monthly_requests', 300 );
		$articles = (int) Settings::get( 'ai.monthly_articles', 30 );

		if ( $month['requests'] >= $requests ) {
			throw new RuntimeException( sprintf( 'سقف ماهانه %s درخواست هوش مصنوعی پر شده است. در «تنظیمات ← هوش مصنوعی» سقف را بیشتر کنید یا تا ماه بعد صبر کنید.', PersianNormalizer::fa_number( $requests ) ) );
		}
		if ( in_array( $task, AiClient::ARTICLE_TASKS, true ) && $month['articles'] >= $articles ) {
			throw new RuntimeException( sprintf( 'سقف ماهانه %s مقاله پر شده است. در «تنظیمات ← هوش مصنوعی» سقف را بیشتر کنید یا تا ماه بعد صبر کنید.', PersianNormalizer::fa_number( $articles ) ) );
		}
	}

	/**
	 * Records one request.
	 *
	 * @param string      $task      Task.
	 * @param array       $conn      Connection (key is not stored).
	 * @param int         $in        Input tokens.
	 * @param int         $out       Output tokens.
	 * @param string      $status    ok|error.
	 * @param string|null $error     Error text.
	 * @param int|null    $object_id Related post/term.
	 * @param float       $start     microtime() at start.
	 */
	public static function record( string $task, array $conn, int $in, int $out, string $status, ?string $error, ?int $object_id, float $start ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'ai_usage' ),
			array(
				'task'          => sanitize_key( $task ),
				'provider'      => sanitize_key( (string) $conn['provider'] ),
				'model'         => mb_substr( (string) $conn['model'], 0, 100 ),
				'input_tokens'  => max( 0, $in ),
				'output_tokens' => max( 0, $out ),
				'status'        => 'ok' === $status ? 'ok' : 'error',
				'error'         => null === $error ? null : mb_substr( $error, 0, 255 ),
				'object_id'     => $object_id,
				'duration_ms'   => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'user_id'       => get_current_user_id(),
				'created_at'    => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Latest requests for the usage table.
	 *
	 * @param int $limit Rows.
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;
		$table = Schema::table( 'ai_usage' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, task, model, input_tokens, output_tokens, status, error, object_id, duration_ms, created_at FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		return array_map(
			static fn( $r ) => array(
				'id'            => (int) $r['id'],
				'task'          => (string) $r['task'],
				'task_label'    => AiClient::TASK_LABELS[ $r['task'] ] ?? $r['task'],
				'model'         => (string) $r['model'],
				'input_tokens'  => (int) $r['input_tokens'],
				'output_tokens' => (int) $r['output_tokens'],
				'status'        => (string) $r['status'],
				'error'         => $r['error'],
				'object_id'     => null === $r['object_id'] ? null : (int) $r['object_id'],
				'object_title'  => null === $r['object_id'] ? null : html_entity_decode( get_the_title( (int) $r['object_id'] ), ENT_QUOTES, 'UTF-8' ),
				'duration_ms'   => (int) $r['duration_ms'],
				'created_at'    => gmdate( 'c', (int) strtotime( $r['created_at'] . ' UTC' ) ),
			),
			(array) $rows
		);
	}

	/**
	 * Deletes rows older than the activity retention period.
	 *
	 * @param int $days Days to keep.
	 */
	public static function prune( int $days ): void {
		global $wpdb;
		$table = Schema::table( 'ai_usage' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - max( 90, $days ) * DAY_IN_SECONDS ) ) );
	}
}
