<?php

declare(strict_types=1);

namespace ItsStarter\Sms;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use ItsStarter\Sms\Data\Balance;
use ItsStarter\Sms\Data\Sender;
use ItsStarter\Sms\Data\SendResult;
use ItsStarter\Sms\Data\SendStatus;
use ItsStarter\Sms\Data\WebhookEvent;
use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\Exceptions\WebhookException;
use ItsStarter\Sms\Support\PhoneNumber;
use ItsStarter\Sms\Support\Segments;
use ItsStarter\Sms\Webhooks\WebhookVerifier;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Entry point behind the Sms facade. The default account comes from the top level of
 * config/itssms.php, named accounts from `accounts`. Each account gets its own Client and key.
 */
class SmsManager
{
    public function __construct(protected readonly Container $container) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [];
    }

    public function __serialize(): array
    {
        throw new LogicException('ItsSms SmsManager cannot be serialised');
    }

    /**
     * A named account from config `accounts`, or the default account when no name is given.
     */
    public function account(?string $name = null): Account
    {
        return new Account($this, $name);
    }

    /**
     * @param  string|list<string>  $to
     */
    public function to(string|array $to): PendingSms
    {
        return $this->account()->to($to);
    }

    /**
     * Shortcut for to($to)->message($message)->send().
     *
     * @param  string|list<string>  $to
     * @return SendResult|list<SendResult>
     */
    public function send(string|array $to, string $message): SendResult|array
    {
        return $this->account()->send($to, $message);
    }

    public function status(string $id): SendStatus
    {
        return $this->account()->status($id);
    }

    public function balance(): Balance
    {
        return $this->account()->balance();
    }

    /**
     * @return list<Sender>
     */
    public function senders(): array
    {
        return $this->account()->senders();
    }

    /**
     * Normalised 94XXXXXXXXX number, or null when it is not a Sri Lankan mobile. No network.
     */
    public function normalizeNumber(string $number): ?string
    {
        return PhoneNumber::normalize($number);
    }

    /**
     * Encoding and segment count for a message. No network.
     */
    public function countSegments(string $message): Segments
    {
        return Segments::count($message);
    }

    /**
     * Verify a webhook request by hand and return its event.
     *
     * @throws WebhookException when the signature, timestamp or body is not acceptable
     * @throws ConfigurationException when no webhook secret is configured
     */
    public function verifyWebhook(Request $request): WebhookEvent
    {
        $config = $this->config();
        $secret = $config->get('itssms.webhook.secret');
        $tolerance = $config->get('itssms.webhook.tolerance', 300);
        $body = $request->getContent();

        WebhookVerifier::verify(
            $body,
            $request->header('X-ITS-Timestamp'),
            $request->header('X-ITS-Signature'),
            is_string($secret) ? $secret : null,
            is_numeric($tolerance) ? (int) $tolerance : 300,
        );

        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['event']) || ! is_string($data['event']) || $data['event'] === '') {
            throw new WebhookException('The webhook body is not a valid event.', 400, 'invalid_payload');
        }

        /** @var array<string, mixed> $data */
        return WebhookEvent::fromArray($data);
    }

    /**
     * @internal Used by PendingSms.
     *
     * @return SendResult|list<SendResult>
     */
    public function sendOn(?string $account, PendingSms $sms): SendResult|array
    {
        return $this->client($account)->send(
            $sms->getRawRecipients(),
            $sms->getMessage(),
            $sms->getSender(),
            $sms->getType(),
            $sms->getReference(),
            $sms->getScheduleAt(),
            $sms->getIdempotencyKey(),
        );
    }

    /** @internal Used by Account. */
    public function statusOn(?string $account, string $id): SendStatus
    {
        return $this->client($account)->status($id);
    }

    /** @internal Used by Account. */
    public function balanceOn(?string $account): Balance
    {
        return $this->client($account)->balance();
    }

    /**
     * @internal Used by Account.
     *
     * @return list<Sender>
     */
    public function sendersOn(?string $account): array
    {
        return $this->client($account)->senders();
    }

    /**
     * Connection settings for one account. Named accounts inherit base_url, timeout and
     * max_retries from the top level, but never the key or the sender.
     *
     * @return array{api_key: ?string, sender: ?string, base_url: string, timeout: int, max_retries: int}
     */
    protected function settings(?string $name): array
    {
        $root = $this->config()->get('itssms');
        $root = is_array($root) ? $root : [];
        $source = $root;

        if ($name !== null) {
            $accounts = is_array($root['accounts'] ?? null) ? $root['accounts'] : [];

            if (! is_array($accounts[$name] ?? null)) {
                throw new ConfigurationException(
                    "No ITS SMS account named \"{$name}\" in config/itssms.php `accounts`.",
                    0,
                    'unknown_account',
                );
            }

            $source = $accounts[$name];
        }

        $baseUrl = $source['base_url'] ?? $root['base_url'] ?? null;
        $timeout = $source['timeout'] ?? $root['timeout'] ?? 15;
        $retries = $source['max_retries'] ?? $root['max_retries'] ?? 2;

        return [
            'api_key' => $this->text($source['api_key'] ?? null),
            'sender' => $this->text($source['sender'] ?? null),
            'base_url' => $this->text($baseUrl) ?? 'https://my.itstarter.cc/api/v1',
            'timeout' => is_numeric($timeout) ? (int) $timeout : 15,
            'max_retries' => is_numeric($retries) ? (int) $retries : 2,
        ];
    }

    protected function httpFactory(): Factory
    {
        return $this->container->make(Factory::class);
    }

    private function client(?string $name): Client
    {
        // Built per call, never cached, so runtime config changes (multi-tenant, Octane) apply.
        return $this->buildClient($this->settings($name));
    }

    /**
     * @param  array{api_key: ?string, sender: ?string, base_url: string, timeout: int, max_retries: int}  $settings
     */
    private function buildClient(array $settings): Client
    {
        return new Client(
            http: $this->httpFactory(),
            apiKey: $settings['api_key'],
            baseUrl: $settings['base_url'],
            timeout: $settings['timeout'],
            maxRetries: $settings['max_retries'],
            defaultSender: $settings['sender'],
            logger: $this->container->bound(LoggerInterface::class) ? $this->container->make(LoggerInterface::class) : null,
        );
    }

    private function config(): Repository
    {
        return $this->container->make(Repository::class);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
