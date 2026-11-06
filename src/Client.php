<?php

declare(strict_types=1);

namespace ItsStarter\Sms;

use Closure;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use ItsStarter\Sms\Data\Balance;
use ItsStarter\Sms\Data\Sender;
use ItsStarter\Sms\Data\SendResult;
use ItsStarter\Sms\Data\SendStatus;
use ItsStarter\Sms\Exceptions\AuthException;
use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\Exceptions\ConflictException;
use ItsStarter\Sms\Exceptions\CreditException;
use ItsStarter\Sms\Exceptions\ItsSmsException;
use ItsStarter\Sms\Exceptions\NetworkException;
use ItsStarter\Sms\Exceptions\PermissionException;
use ItsStarter\Sms\Exceptions\RateLimitException;
use ItsStarter\Sms\Exceptions\ServerException;
use ItsStarter\Sms\Exceptions\ValidationException;
use ItsStarter\Sms\Support\PhoneNumber;
use ItsStarter\Sms\Support\Segments;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Low-level client for the ITS SMS API (contract v1).
 *
 * One instance holds one API key. It keeps no state between calls.
 */
final class Client
{
    public const VERSION = '0.1.0';

    public const MAX_BATCH = 1000;

    /** Seconds to wait before retry 1, 2, 3...; the last value repeats. */
    private const BACKOFF = [0.5, 1.0, 2.0];

    /** Upper bound for a server supplied Retry-After, in seconds. */
    private const MAX_RETRY_AFTER = 60;

    private const WARN_SEGMENTS = 6;

    /** The API key sits in a closure so var_export() and similar dumps cannot print it. */
    private readonly Closure $apiKey;

    public function __construct(
        private readonly Factory $http,
        ?string $apiKey,
        private readonly string $baseUrl = 'https://sms.itstarter.cc/api/v1',
        private readonly int $timeout = 15,
        private readonly int $maxRetries = 2,
        private readonly ?string $defaultSender = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->apiKey = fn (): ?string => $apiKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => '***', 'baseUrl' => $this->baseUrl, 'timeout' => $this->timeout, 'maxRetries' => $this->maxRetries];
    }

    public function __serialize(): array
    {
        throw new LogicException('ItsSms Client cannot be serialised');
    }

    /**
     * Send a message. Numbers are normalised, de-duplicated and validated locally first.
     *
     * Returns one SendResult, or a list of them when more than 1,000 numbers
     * were split into several sequential requests.
     *
     * $idempotencyKey makes your own retries safe: it is sent as is for one batch, or as
     * "{key}:{batch index}" for several. Without it each batch gets a random UUID.
     * If a later batch fails, the thrown exception carries the earlier results in $sentResults.
     *
     * @param  string|list<string>  $to
     * @return SendResult|list<SendResult>
     */
    public function send(
        string|array $to,
        string $message,
        ?string $sender = null,
        string $type = 'transactional',
        ?string $reference = null,
        DateTimeInterface|string|null $scheduleAt = null,
        ?string $idempotencyKey = null,
    ): SendResult|array {
        $numbers = $this->normalizeRecipients($to);
        $sender ??= $this->defaultSender;

        $this->validateMessage($message);
        $this->validateSender($sender);

        if (! in_array($type, ['transactional', 'promotional'], true)) {
            throw new ValidationException('The type must be "transactional" or "promotional".');
        }

        $segments = Segments::count($message)->segments;
        if ($segments > self::WARN_SEGMENTS) {
            $this->logger?->warning("ITS SMS message spans {$segments} segments.", ['segments' => $segments]);
        }

        $payload = array_filter([
            'message' => $message,
            'sender' => $sender,
            'type' => $type,
            'reference' => $reference,
            'schedule_at' => $scheduleAt instanceof DateTimeInterface ? $scheduleAt->format(DATE_ATOM) : $scheduleAt,
        ], fn (mixed $value) => $value !== null);

        $results = [];

        $batches = array_chunk($numbers, self::MAX_BATCH);

        foreach ($batches as $i => $batch) {
            $key = $idempotencyKey === null
                ? (string) Str::uuid()
                : (count($batches) === 1 ? $idempotencyKey : "{$idempotencyKey}:{$i}");

            if (strlen($key) < 1 || strlen($key) > 255) {
                throw new ValidationException('The idempotency key must be 1 to 255 characters.');
            }

            try {
                $data = $this->request('POST', '/sms/send', ['to' => $batch] + $payload, ['Idempotency-Key' => $key]);
            } catch (ItsSmsException $e) {
                $e->sentResults = $results;

                throw $e;
            }

            $results[] = SendResult::fromArray($data);
        }

        return count($results) === 1 ? $results[0] : $results;
    }

    public function status(string $id): SendStatus
    {
        if (trim($id) === '') {
            throw new ValidationException('The message id must not be empty.');
        }

        return SendStatus::fromArray($this->request('GET', '/sms/'.rawurlencode($id)));
    }

    public function balance(): Balance
    {
        return Balance::fromArray($this->request('GET', '/balance'));
    }

    /**
     * @return list<Sender>
     */
    public function senders(): array
    {
        $senders = [];

        foreach ($this->request('GET', '/senders') as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $senders[] = Sender::fromArray($row);
            }
        }

        return $senders;
    }

    /**
     * Channel-neutral request: auth, headers, retries and error mapping. Knows nothing about SMS.
     *
     * One Idempotency-Key (or any other header) passed in $headers is reused on every retry.
     *
     * @param  array<string, mixed>  $data  JSON body, or the query string for GET.
     * @param  array<string, string>  $headers
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $data = [], array $headers = []): array
    {
        $key = $this->resolveApiKey();
        $method = strtoupper($method);
        $options = $data === [] ? [] : ($method === 'GET' ? ['query' => $data] : ['json' => $data]);
        $attempt = 0;

        while (true) {
            $retryAfter = null;

            try {
                $response = $this->pending($key, $headers)->send($method, ltrim($path, '/'), $options);
                $error = $response->successful() ? null : $this->mapError($response, $key);
                $retryAfter = $response->successful() ? null : $this->retryAfter($response);
            } catch (ConnectionException $e) {
                $response = null;
                $error = new NetworkException(
                    'Could not reach the ITS SMS API: '.$this->redact($e->getMessage(), $key),
                );
            }

            if ($error === null) {
                /** @var Response $response */
                return $this->decode($response);
            }

            if (! $this->isRetryable($error) || $attempt >= $this->maxRetries) {
                throw $error;
            }

            Sleep::for((int) round(($retryAfter ?? self::BACKOFF[min($attempt, count(self::BACKOFF) - 1)]) * 1000))->milliseconds();
            $attempt++;
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function pending(string $key, array $headers): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($key)
            ->withHeaders($headers + ['User-Agent' => 'itssms-laravel/'.self::VERSION])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->withoutRedirecting();
    }

    private function resolveApiKey(): string
    {
        $key = ($this->apiKey)();

        if ($key === null || $key === '') {
            throw new ConfigurationException(
                'No ITS SMS API key configured. Set ITSSMS_API_KEY or the "api_key" option in config/itssms.php.',
            );
        }

        return $key;
    }

    private function isRetryable(ItsSmsException $e): bool
    {
        return $e instanceof NetworkException || $e instanceof RateLimitException || $e instanceof ServerException;
    }

    /**
     * @return array<mixed>
     */
    private function decode(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new ServerException(
                'The ITS SMS API returned an unreadable response.',
                $response->status(),
                'invalid_response',
                $this->requestId($response),
            );
        }

        return $body;
    }

    private function mapError(Response $response, string $key): ItsSmsException
    {
        $status = $response->status();
        $requestId = $this->requestId($response);
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        $code = isset($error['code']) && is_string($error['code']) && $error['code'] !== ''
            ? $error['code']
            : $this->defaultCode($status);
        $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== ''
            ? $error['message']
            : "The ITS SMS API returned HTTP {$status}.";
        $message = $this->redact($message, $key);

        return match (true) {
            $status === 401 => new AuthException($message, $status, $code, $requestId),
            $status === 402 => new CreditException($message, $status, $code, $requestId),
            $status === 403 => new PermissionException($message, $status, $code, $requestId),
            $status === 409 => new ConflictException($message, $status, $code, $requestId),
            $status === 422 => new ValidationException($message, $status, $code, $requestId),
            $status === 429 => new RateLimitException($message, $status, $code, $requestId, null, $this->retryAfter($response)),
            $status >= 500 => new ServerException($message, $status, $code, $requestId),
            default => new ItsSmsException($message, $status, $code, $requestId),
        };
    }

    private function defaultCode(int $status): string
    {
        return match (true) {
            $status === 401 => 'invalid_api_key',
            $status === 402 => 'insufficient_credits',
            $status === 403 => 'permission_denied',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_failed',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            $status >= 300 && $status < 400 => 'unexpected_redirect',
            default => 'http_error',
        };
    }

    private function requestId(Response $response): ?string
    {
        $id = $response->header('X-Request-Id');

        return $id === '' ? null : $id;
    }

    /**
     * Retry-After in seconds (integer or HTTP-date), capped; null when absent or unreadable.
     */
    private function retryAfter(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return min((int) $value, self::MAX_RETRY_AFTER);
        }

        $time = strtotime($value);

        if ($time === false) {
            return null;
        }

        return min(max($time - Date::now()->getTimestamp(), 0), self::MAX_RETRY_AFTER);
    }

    private function redact(string $text, string $key): string
    {
        return str_replace($key, '[redacted]', $text);
    }

    /**
     * @param  string|list<string>  $to
     * @return list<string>
     */
    private function normalizeRecipients(string|array $to): array
    {
        $raw = is_array($to) ? $to : [$to];
        $valid = [];
        $invalid = [];

        foreach ($raw as $number) {
            $normalized = PhoneNumber::normalize((string) $number);

            if ($normalized === null) {
                $invalid[] = (string) $number;
            } else {
                $valid[$normalized] = $normalized;
            }
        }

        if ($invalid !== []) {
            throw new ValidationException(
                'Invalid Sri Lankan mobile number(s): '.implode(', ', $invalid),
                422,
                'invalid_number',
                null,
                null,
                $invalid,
            );
        }

        if ($valid === []) {
            throw new ValidationException('At least one recipient is required.');
        }

        return array_values($valid);
    }

    private function validateMessage(string $message): void
    {
        if (trim($message) === '') {
            throw new ValidationException('Message must not be empty.');
        }
    }

    private function validateSender(?string $sender): void
    {
        if ($sender !== null && preg_match('/^[A-Za-z0-9 ._-]{1,11}$/', $sender) !== 1) {
            throw new ValidationException(
                'Sender must be 1 to 11 characters: letters, digits, space, ".", "-" or "_".',
            );
        }
    }
}
