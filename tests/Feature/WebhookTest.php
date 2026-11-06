<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use ItsStarter\Sms\Data\WebhookEvent;
use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\Exceptions\WebhookException;
use ItsStarter\Sms\Facades\Sms;
use ItsStarter\Sms\Webhooks\Events\MessageDelivered;
use ItsStarter\Sms\Webhooks\Events\MessageFailed;
use ItsStarter\Sms\Webhooks\Events\MessageSubmitted;
use ItsStarter\Sms\Webhooks\WebhookController;
use ItsStarter\Sms\Webhooks\WebhookVerifier;

const WH_SECRET = 'whsec_test';

function webhookBody(array $overrides = []): string
{
    return json_encode(array_merge([
        'event' => 'message.delivered', 'id' => 'cmp_01JB8X2K9Q', 'to' => '94771234567',
        'status' => 'delivered', 'reference' => 'INV-1042', 'timestamp' => '2026-10-30T10:01:12+05:30',
    ], $overrides));
}

function signedHeaders(string $body, ?int $timestamp = null, string $secret = WH_SECRET): array
{
    $timestamp ??= time();

    return [
        'X-ITS-Timestamp' => (string) $timestamp,
        'X-ITS-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

function postWebhook($test, string $body, array $headers)
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', '/itssms/webhook', [], [], [], $server, $body);
}

it('registers the route when a path is configured', function () {
    expect(Route::has('itssms.webhook'))->toBeTrue();
});

it('accepts a valid call and dispatches the matching event', function (string $type, string $class) {
    Event::fake([MessageSubmitted::class, MessageDelivered::class, MessageFailed::class]);
    $body = webhookBody(['event' => $type]);

    postWebhook($this, $body, signedHeaders($body))->assertOk()->assertJson(['received' => true]);

    Event::assertDispatched($class, function ($event) use ($type) {
        return $event->event instanceof WebhookEvent
            && $event->event->type === $type
            && $event->event->id === 'cmp_01JB8X2K9Q'
            && $event->event->to === '94771234567'
            && $event->event->status === 'delivered'
            && $event->event->reference === 'INV-1042'
            && $event->event->timestamp?->format('Y-m-d') === '2026-10-30'
            && $event->event->payload['event'] === $type;
    });
})->with([
    ['message.submitted', MessageSubmitted::class],
    ['message.delivered', MessageDelivered::class],
    ['message.failed', MessageFailed::class],
]);

it('acknowledges unknown event types and dispatches nothing', function () {
    Event::fake([MessageSubmitted::class, MessageDelivered::class, MessageFailed::class]);
    $body = webhookBody(['event' => 'message.something_new']);

    postWebhook($this, $body, signedHeaders($body))->assertOk();

    Event::assertNothingDispatched();
});

it('rejects a tampered body with 401', function () {
    Event::fake();
    $headers = signedHeaders(webhookBody());

    postWebhook($this, webhookBody(['status' => 'failed']), $headers)->assertUnauthorized();

    Event::assertNotDispatched(MessageDelivered::class);
});

it('rejects a wrong secret with 401', function () {
    $body = webhookBody();

    postWebhook($this, $body, signedHeaders($body, null, 'other_secret'))->assertUnauthorized();
});

it('rejects an old or future timestamp with 401', function () {
    $body = webhookBody();

    postWebhook($this, $body, signedHeaders($body, time() - 301))->assertUnauthorized()->assertJsonPath('error.code', 'stale_timestamp');
    postWebhook($this, $body, signedHeaders($body, time() + 301))->assertUnauthorized();
    postWebhook($this, $body, signedHeaders($body, time() - 299))->assertOk();
});

it('rejects missing or malformed headers with 401', function () {
    $body = webhookBody();

    postWebhook($this, $body, [])->assertUnauthorized();
    postWebhook($this, $body, ['X-ITS-Timestamp' => 'abc', 'X-ITS-Signature' => 'sha256=00'])->assertUnauthorized();
    postWebhook($this, $body, ['X-ITS-Timestamp' => (string) time()])->assertUnauthorized();
});

it('rejects every call when no secret is configured', function () {
    config(['itssms.webhook.secret' => null]);
    $body = webhookBody();

    postWebhook($this, $body, signedHeaders($body, null, ''))->assertUnauthorized();
    postWebhook($this, $body, signedHeaders($body))->assertUnauthorized();
});

it('does not echo the secret or the expected signature in a rejection', function () {
    $body = webhookBody();
    $response = postWebhook($this, $body, signedHeaders($body, null, 'other_secret'));

    expect($response->getContent())->not->toContain(WH_SECRET)->not->toContain('sha256=');
});

it('answers 400 for a signed body that is not an event', function () {
    $body = '{"hello":"world"}';
    postWebhook($this, $body, signedHeaders($body))->assertStatus(400);

    $body = 'not json';
    postWebhook($this, $body, signedHeaders($body))->assertStatus(400);
});

it('refuses to dispatch when the controller runs without the middleware', function () {
    $response = app(WebhookController::class)(
        Request::create('/x', 'POST'),
        app(Dispatcher::class),
    );

    expect($response->getStatusCode())->toBe(401);
});

it('verifies by hand with Sms::verifyWebhook', function () {
    $body = webhookBody();
    $headers = signedHeaders($body);
    $request = Request::create('/x', 'POST', [], [], [], [
        'HTTP_X_ITS_TIMESTAMP' => $headers['X-ITS-Timestamp'],
        'HTTP_X_ITS_SIGNATURE' => $headers['X-ITS-Signature'],
    ], $body);

    $event = Sms::verifyWebhook($request);

    expect($event)->toBeInstanceOf(WebhookEvent::class)->and($event->type)->toBe('message.delivered');
});

it('throws from Sms::verifyWebhook on a bad signature', function () {
    $request = Request::create('/x', 'POST', [], [], [], [
        'HTTP_X_ITS_TIMESTAMP' => (string) time(), 'HTTP_X_ITS_SIGNATURE' => 'sha256=bad',
    ], webhookBody());

    Sms::verifyWebhook($request);
})->throws(WebhookException::class);

it('throws a configuration error from verifyWebhook when the secret is missing', function () {
    config(['itssms.webhook.secret' => '']);

    Sms::verifyWebhook(Request::create('/x', 'POST', [], [], [], [], webhookBody()));
})->throws(ConfigurationException::class, 'webhook secret');

it('accepts an upper-case hex signature and applies tolerance against an injected clock', function () {
    $body = webhookBody();
    $signature = 'sha256='.strtoupper(hash_hmac('sha256', '1000.'.$body, WH_SECRET));

    expect(fn () => WebhookVerifier::verify($body, '1000', $signature, WH_SECRET, 300, 1100))->not->toThrow(WebhookException::class)
        ->and(fn () => WebhookVerifier::verify($body, '1000', $signature, WH_SECRET, 300, 1400))->toThrow(WebhookException::class);
});

it('parses a webhook event with a bad timestamp and no reference', function () {
    $event = WebhookEvent::fromArray(['event' => 'message.failed', 'id' => 'x', 'timestamp' => 'not a date']);

    expect($event->timestamp)->toBeNull()->and($event->reference)->toBeNull()->and($event->type)->toBe('message.failed');
});

it('logs one warning when the secret is missing, without secrets', function () {
    config(['itssms.webhook.secret' => null]);
    Log::shouldReceive('warning')->once()->with('ITS SMS webhook rejected: webhook secret is not configured.');
    $body = webhookBody();

    postWebhook($this, $body, signedHeaders($body))->assertUnauthorized();
});
