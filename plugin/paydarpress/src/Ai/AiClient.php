<?php
/**
 * Sends requests to the configured AI service (OpenAI-compatible or Anthropic), enforces the
 * monthly limits and records usage.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Ai;

use PaydarWP\PaydarPress\Core\Settings;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * One request = one call to complete(). Tasks that create an article are counted against
 * `ai.monthly_articles`; every request is counted against `ai.monthly_requests`.
 */
final class AiClient {

	/** Tasks that count as one article. */
	public const ARTICLE_TASKS = array( 'article', 'rewrite', 'merge' );

	public const TASK_LABELS = array(
		'test'       => 'آزمایش اتصال',
		'topics'     => 'پیشنهاد موضوع',
		'article'    => 'نوشتن مقاله',
		'rewrite'    => 'بازنویسی مقاله',
		'meta'       => 'عنوان و توضیحات متا',
		'anchors'    => 'متن لینک',
		'term'       => 'متن دسته‌بندی',
		'alt'        => 'متن جایگزین تصویر',
		'structure'  => 'بررسی ساختار دسته‌ها',
		'merge'      => 'ادغام دو مقاله',
		'classify'   => 'طبقه‌بندی مطالب',
		'cluster'    => 'نقشه خوشه محتوایی',
	);

	private const TIMEOUT = 180;

	/** @var int Timeout of the current request (long articles need more). */
	private $timeout = self::TIMEOUT;

	/** @var array|null */
	private $conn;

	/**
	 * @param array|null $connection Connection override (tests); defaults to stored credentials.
	 */
	public function __construct( ?array $connection = null ) {
		$this->conn = $connection ?? Credentials::connection();
	}

	/**
	 * Whether a connection is configured.
	 */
	public function ready(): bool {
		return null !== $this->conn;
	}

	/**
	 * Sends a prompt and returns the reply.
	 *
	 * @param string $task   Task key (see TASK_LABELS).
	 * @param string $system System instructions.
	 * @param string $user   User message.
	 * @param array  $opts   max_tokens, object_id, json (bool: ask for a JSON reply).
	 * @return array{text: string, input_tokens: int, output_tokens: int, model: string}
	 * @throws RuntimeException With a Persian message the user can act on.
	 */
	public function complete( string $task, string $system, string $user, array $opts = array() ): array {
		if ( null === $this->conn ) {
			throw new RuntimeException( 'اتصال هوش مصنوعی تنظیم نشده است. در «تنظیمات ← هوش مصنوعی» کلید API و مدل را وارد کنید.' );
		}
		Usage::assert_allowed( $task );

		$max   = (int) ( $opts['max_tokens'] ?? Settings::get( 'ai.max_output_tokens', 16000 ) );
		$start         = microtime( true );
		$this->timeout = max( 30, min( 600, (int) ( $opts['timeout'] ?? self::TIMEOUT ) ) );
		try {
			$result = 'anthropic' === $this->conn['provider']
				? $this->anthropic( $system, $user, $max )
				: $this->openai( $system, $user, $max, ! empty( $opts['json'] ) );
		} catch ( RuntimeException $e ) {
			Usage::record( $task, $this->conn, 0, 0, 'error', $e->getMessage(), $opts['object_id'] ?? null, $start );
			throw $e;
		}
		$usage_id = Usage::record( $task, $this->conn, $result['input_tokens'], $result['output_tokens'], 'ok', null, $opts['object_id'] ?? null, $start );

		return $result + array(
			'model'    => $this->conn['model'],
			'usage_id' => $usage_id,
		);
	}

	/**
	 * Lists model ids the service offers (not counted as usage).
	 *
	 * @return string[]
	 * @throws RuntimeException On failure.
	 */
	public function models(): array {
		if ( null === $this->conn ) {
			throw new RuntimeException( 'ابتدا کلید API را ذخیره کنید.' );
		}
		$headers = 'anthropic' === $this->conn['provider']
			? array( 'x-api-key' => $this->conn['key'], 'anthropic-version' => '2023-06-01' )
			: array( 'Authorization' => 'Bearer ' . $this->conn['key'] );

		$body = $this->send( 'GET', '/models', $headers, null );
		$ids  = array_values( array_filter( array_map( static fn( $m ) => is_array( $m ) ? (string) ( $m['id'] ?? '' ) : '', (array) ( $body['data'] ?? array() ) ) ) );
		sort( $ids );
		return array_slice( $ids, 0, 300 );
	}

	/**
	 * Chat Completions (OpenAI and compatible gateways).
	 */
	private function openai( string $system, string $user, int $max, bool $json ): array {
		$payload = array(
			'model'      => $this->conn['model'],
			'messages'   => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			'max_tokens' => $max,
		);
		if ( $json ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
		}
		$headers = array( 'Authorization' => 'Bearer ' . $this->conn['key'] );

		try {
			$body = $this->send( 'POST', '/chat/completions', $headers, $payload );
		} catch ( RuntimeException $e ) {
			// Newer OpenAI models reject max_tokens / response_format; retry once without them.
			if ( false === strpos( $e->getMessage(), '[400]' ) ) {
				throw $e;
			}
			unset( $payload['max_tokens'], $payload['response_format'] );
			$payload['max_completion_tokens'] = $max;
			$body = $this->send( 'POST', '/chat/completions', $headers, $payload );
		}

		$text = (string) ( $body['choices'][0]['message']['content'] ?? '' );
		if ( '' === trim( $text ) ) {
			throw new RuntimeException( 'سرویس پاسخ خالی برگرداند. مدل دیگری را امتحان کنید.' );
		}
		return array(
			'text'          => $text,
			'input_tokens'  => (int) ( $body['usage']['prompt_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $body['usage']['completion_tokens'] ?? 0 ),
			'truncated'     => 'length' === ( $body['choices'][0]['finish_reason'] ?? '' ),
		);
	}

	/**
	 * Anthropic Messages API.
	 */
	private function anthropic( string $system, string $user, int $max ): array {
		$body = $this->send(
			'POST',
			'/messages',
			array(
				'x-api-key'         => $this->conn['key'],
				'anthropic-version' => '2023-06-01',
			),
			array(
				'model'      => $this->conn['model'],
				'max_tokens' => $max,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
			)
		);

		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) $block['text'];
			}
		}
		if ( '' === trim( $text ) ) {
			throw new RuntimeException( 'سرویس پاسخ خالی برگرداند. مدل دیگری را امتحان کنید.' );
		}
		return array(
			'text'          => $text,
			'input_tokens'  => (int) ( $body['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ),
			'truncated'     => 'max_tokens' === ( $body['stop_reason'] ?? '' ),
		);
	}

	/**
	 * HTTP request with readable errors. The key is never included in error messages.
	 *
	 * @param string     $method  GET|POST.
	 * @param string     $path    Path after the base URL.
	 * @param array      $headers Headers.
	 * @param array|null $payload JSON body.
	 * @return array Decoded JSON.
	 * @throws RuntimeException On failure.
	 */
	private function send( string $method, string $path, array $headers, ?array $payload ): array {
		$args = array(
			'method'      => $method,
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'headers'     => $headers + array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( rtrim( $this->conn['base_url'], '/' ) . $path, $args );
		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			if ( false !== stripos( $msg, 'timed out' ) ) {
				throw new RuntimeException( 'پاسخ سرویس بیش از حد طول کشید. دوباره تلاش کنید یا مدل سریع‌تری انتخاب کنید.' );
			}
			throw new RuntimeException( 'سرور سایت به سرویس هوش مصنوعی وصل نشد. اگر هاست در ایران است، اتصال مستقیم به سرویس‌های خارجی معمولاً بسته است؛ از یک درگاه سازگار با OpenAI که از ایران در دسترس است استفاده کنید. (' . mb_substr( $msg, 0, 120 ) . ')' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 && is_array( $body ) ) {
			return $body;
		}

		$detail = is_array( $body ) ? (string) ( $body['error']['message'] ?? $body['message'] ?? '' ) : '';
		$detail = mb_substr( str_replace( (string) ( $this->conn['key'] ?? '' ), '***', wp_strip_all_tags( $detail ) ), 0, 160 );
		$hints  = array(
			400 => 'درخواست پذیرفته نشد؛ نام مدل یا تنظیمات را بررسی کنید.',
			401 => 'کلید API نادرست یا باطل است.',
			402 => 'اعتبار حساب سرویس تمام شده است.',
			403 => 'سرویس دسترسی را رد کرد. ممکن است کلید اجازه این مدل را نداشته باشد یا دسترسی از کشور سرور مسدود باشد.',
			404 => 'آدرس سرویس یا نام مدل پیدا نشد.',
			429 => 'محدودیت تعداد درخواست یا اعتبار سرویس؛ چند دقیقه بعد تلاش کنید.',
		);
		$hint = $hints[ $code ] ?? ( $code >= 500 ? 'سرویس موقتاً در دسترس نیست؛ کمی بعد تلاش کنید.' : ( is_array( $body ) ? 'خطای ناشناخته از سرویس.' : 'پاسخ سرویس قابل خواندن نبود؛ آدرس سرویس را بررسی کنید (باید به /v1 ختم شود).' ) );

		throw new RuntimeException( $hint . ( '' !== $detail ? ' — ' . $detail : '' ) . " [{$code}]" );
	}
}
