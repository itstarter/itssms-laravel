<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use ItsStarter\Sms\Client;
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
use Psr\Log\AbstractLogger;

const TEST_KEY = 'itssms_live_SECRETKEY123';

function sendOk(array $overrides = []): array
{
    return array_merge([
        'id' => 'cmp_01JB8X2K9Q', 'status' => 'queued', 'recipients' => 2, 'segments' => 1,
        'credits_charged' => 2, 'credits_remaining' => 1498, 'invalid_numbers' => [], 'duplicates_skipped' => 0,
    ], $overrides);
}

function errorBody(string $code, string $message = 'Something went wrong'): array
{
    return ['error' => ['code' => $code, 'message' => $message]];
}

function makeClient(array $args = []): Client
{
    return new Client(
        http: Http::getFacadeRoot(),
        apiKey: array_key_exists('apiKey', $args) ? $args['apiKey'] : TEST_KEY,
        baseUrl: $args['baseUrl'] ?? 'https://sms.example.test/api/v1',
        timeout: $args['timeout'] ?? 15,
        maxRetries: $args['maxRetries'] ?? 2,
        defaultSender: $args['defaultSender'] ?? null,
        logger: $args['logger'] ?? null,
    );
}

beforeEach(function () {
    Sleep::fake();
});

it('sends to the right URL with the right headers and body', function () {
    Http::fake(['*' => Http::response(sendOk(), 200, ['X-Request-Id' => 'req_1'])]);

    $result = makeClient(['defaultSender' => 'BillBook'])->send(
        to: ['0771234567', '+94712345678'],
        message: 'Hello',
        type: 'promotional',
        reference: 'INV-1042',
        scheduleAt: new DateTimeImmutable('2026-11-07T10:00:00+05:30'),
    );

    expect($result)->toBeInstanceOf(SendResult::class)
        ->and($result->id)->toBe('cmp_01JB8X2K9Q')
        ->and($result->creditsRemaining)->toBe(1498);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://sms.example.test/api/v1/sms/send'
            && $request->hasHeader('Authorization', 'Bearer '.TEST_KEY)
            && $request->hasHeader('Accept', 'application/json')
            && str_contains($request->header('Content-Type')[0], 'application/json')
            && $request->hasHeader('User-Agent', 'itssms-laravel/'.Client::VERSION)
            && Str::isUuid($request->header('Idempotency-Key')[0])
            && $request->data() === [
                'to' => ['94771234567', '94712345678'],
                'message' => 'Hello',
                'sender' => 'BillBook',
                'type' => 'promotional',
                'reference' => 'INV-1042',
                'schedule_at' => '2026-11-07T10:00:00+05:30',
            ];
    });
});

it('omits optional fields and accepts a single string recipient', function () {
    Http::fake(['*' => Http::response(sendOk())]);

    makeClient()->send('0771234567', 'Hi');

    Http::assertSent(fn (Request $r) => $r->data() === [
        'to' => ['94771234567'],
        'message' => 'Hi',
        'type' => 'transactional',
    ]);
});

it('accepts a string schedule_at as is', function () {
    Http::fake(['*' => Http::response(sendOk())]);

    makeClient()->send('0771234567', 'Hi', scheduleAt: '2026-11-07T10:00:00+05:30');

    Http::assertSent(fn (Request $r) => $r->data()['schedule_at'] === '2026-11-07T10:00:00+05:30');
});

it('normalises and de-duplicates numbers', function () {
    Http::fake(['*' => Http::response(sendOk())]);

    makeClient()->send(['0771234567', '94771234567', '+94 77 123 4567', '0712345678'], 'Hi');

    Http::assertSent(fn (Request $r) => $r->data()['to'] === ['94771234567', '94712345678']);
});

it('splits more than 1,000 numbers into sequential requests with distinct idempotency keys', function () {
    Http::fake(['*' => Http::sequence()->push(sendOk(['id' => 'cmp_a']))->push(sendOk(['id' => 'cmp_b']))]);

    $numbers = array_map(fn (int $i) => '947'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 1001));

    $results = makeClient()->send($numbers, 'Hi');

    expect($results)->toBeArray()->toHaveCount(2)
        ->and($results[0]->id)->toBe('cmp_a')
        ->and($results[1]->id)->toBe('cmp_b');

    Http::assertSentCount(2);

    $recorded = Http::recorded();
    expect(count($recorded[0][0]->data()['to']))->toBe(1000)
        ->and(count($recorded[1][0]->data()['to']))->toBe(1)
        ->and($recorded[0][0]->header('Idempotency-Key')[0])->not->toBe($recorded[1][0]->header('Idempotency-Key')[0]);
});

it('returns a single result for exactly 1,000 numbers', function () {
    Http::fake(['*' => Http::response(sendOk())]);

    $numbers = array_map(fn (int $i) => '947'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 1000));

    expect(makeClient()->send($numbers, 'Hi'))->toBeInstanceOf(SendResult::class);
    Http::assertSentCount(1);
});

it('retries a 503 and reuses the same Idempotency-Key', function () {
    Http::fake(['*' => Http::sequence()
        ->push(errorBody('server_error'), 503)
        ->push(sendOk(), 200)]);

    $result = makeClient()->send('0771234567', 'Hi');

    expect($result)->toBeInstanceOf(SendResult::class);
    Http::assertSentCount(2);

    $recorded = Http::recorded();
    expect($recorded[0][0]->header('Idempotency-Key')[0])->toBe($recorded[1][0]->header('Idempotency-Key')[0]);

    Sleep::assertSequence([Sleep::for(500)->milliseconds()]);
});

it('backs off 0.5, 1 then 2 seconds and gives up with the last error', function () {
    Http::fake(['*' => Http::response(errorBody('server_error', 'down'), 500, ['X-Request-Id' => 'req_9'])]);

    expect(fn () => makeClient(['maxRetries' => 3])->send('0771234567', 'Hi'))
        ->toThrow(ServerException::class, 'down');

    Http::assertSentCount(4);
    Sleep::assertSequence([
        Sleep::for(500)->milliseconds(),
        Sleep::for(1000)->milliseconds(),
        Sleep::for(2000)->milliseconds(),
    ]);
});

it('uses max_retries to bound the attempts', function () {
    Http::fake(['*' => Http::response(errorBody('server_error'), 500)]);

    expect(fn () => makeClient(['maxRetries' => 2])->balance())->toThrow(ServerException::class);

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('does not retry when max_retries is 0', function () {
    Http::fake(['*' => Http::response(errorBody('server_error'), 500)]);

    expect(fn () => makeClient(['maxRetries' => 0])->balance())->toThrow(ServerException::class);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('does not retry a 422', function () {
    Http::fake(['*' => Http::response(errorBody('message_too_long'), 422)]);

    expect(fn () => makeClient()->send('0771234567', 'Hi'))->toThrow(ValidationException::class);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('honours Retry-After on a 429', function () {
    Http::fake(['*' => Http::sequence()
        ->push(errorBody('rate_limited'), 429, ['Retry-After' => '3'])
        ->push(sendOk(), 200)]);

    makeClient()->send('0771234567', 'Hi');

    Sleep::assertSequence([Sleep::for(3000)->milliseconds()]);
});

it('caps an absurd Retry-After and ignores an unparseable one', function () {
    Http::fake(['*' => Http::sequence()
        ->push(errorBody('rate_limited'), 429, ['Retry-After' => '9999'])
        ->push(errorBody('rate_limited'), 429, ['Retry-After' => 'soon'])
        ->push(['credits' => 1], 200)]);

    makeClient()->balance();

    Sleep::assertSequence([Sleep::for(60000)->milliseconds(), Sleep::for(1000)->milliseconds()]);
});

it('understands an HTTP-date Retry-After', function () {
    $date = gmdate('D, d M Y H:i:s', time() + 5).' GMT';
    Http::fake(['*' => Http::sequence()
        ->push(errorBody('rate_limited'), 429, ['Retry-After' => $date])
        ->push(['credits' => 1], 200)]);

    makeClient()->balance();

    Sleep::assertSleptTimes(1);
});

it('exposes retryAfter on a rate limit exception', function () {
    Http::fake(['*' => Http::response(errorBody('rate_limited'), 429, ['Retry-After' => '7'])]);

    try {
        makeClient(['maxRetries' => 0])->balance();
        $this->fail('Expected exception');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(7)->and($e->status)->toBe(429);
    }
});

it('maps each HTTP error to the right exception with code and request id', function (int $status, string $code, string $class) {
    Http::fake(['*' => Http::response(errorBody($code, 'Nope'), $status, ['X-Request-Id' => 'req_abc'])]);

    try {
        makeClient(['maxRetries' => 0])->balance();
        $this->fail('Expected exception');
    } catch (ItsSmsException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->status)->toBe($status)
            ->and($e->errorCode)->toBe($code)
            ->and($e->requestId)->toBe('req_abc')
            ->and($e->getMessage())->toBe('Nope');
    }
})->with([
    [401, 'invalid_api_key', AuthException::class],
    [401, 'api_key_revoked', AuthException::class],
    [402, 'insufficient_credits', CreditException::class],
    [402, 'sender_credits_empty', CreditException::class],
    [402, 'plan_lapsed', CreditException::class],
    [403, 'sender_not_allowed', PermissionException::class],
    [403, 'account_suspended', PermissionException::class],
    [403, 'scope_missing', PermissionException::class],
    [409, 'blackout_period', ConflictException::class],
    [409, 'idempotency_conflict', ConflictException::class],
    [422, 'invalid_number', ValidationException::class],
    [422, 'sender_not_approved', ValidationException::class],
    [429, 'rate_limited', RateLimitException::class],
    [500, 'server_error', ServerException::class],
    [502, 'server_error', ServerException::class],
]);

it('falls back to a generic message and status-based code when the body is not the error shape', function () {
    Http::fake(['*' => Http::response('<html>Bad gateway</html>', 502, ['X-Request-Id' => 'req_x'])]);

    try {
        makeClient(['maxRetries' => 0])->balance();
        $this->fail('Expected exception');
    } catch (ServerException $e) {
        expect($e->errorCode)->toBe('server_error')
            ->and($e->status)->toBe(502)
            ->and($e->requestId)->toBe('req_x')
            ->and($e->getMessage())->toContain('502');
    }
});

it('uses the base exception for unexpected statuses', function () {
    Http::fake(['*' => Http::response('', 404)]);

    try {
        makeClient()->balance();
        $this->fail('Expected exception');
    } catch (ItsSmsException $e) {
        expect(get_class($e))->toBe(ItsSmsException::class)->and($e->status)->toBe(404);
    }
});

it('turns a timeout into a NetworkException after retrying', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    try {
        makeClient()->balance();
        $this->fail('Expected exception');
    } catch (NetworkException $e) {
        expect($e->status)->toBe(0)->and($e->errorCode)->toBe('network_error');
    }

    Sleep::assertSleptTimes(2);
});

it('recovers when a network error is followed by success', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw new ConnectionException('Connection refused');
        }

        return Http::response(['credits' => 5]);
    });

    expect(makeClient()->balance()->credits)->toBe(5);
});

it('treats an unparseable success body as a server error', function () {
    Http::fake(['*' => Http::response('not json', 200)]);

    expect(fn () => makeClient()->balance())->toThrow(ServerException::class);
    Http::assertSentCount(1);
});

it('throws a configuration error on first use when the key is missing, not at construction', function (?string $key) {
    Http::fake();

    $client = makeClient(['apiKey' => $key]);

    expect(fn () => $client->balance())->toThrow(ConfigurationException::class);
    Http::assertNothingSent();
})->with([null, '']);

it('validates locally before any network call', function (Closure $call, string $message) {
    Http::fake();

    expect($call)->toThrow(ValidationException::class, $message);
    Http::assertNothingSent();
})->with([
    'no recipients' => [fn () => makeClient()->send([], 'Hi'), 'recipient'],
    'blank message' => [fn () => makeClient()->send('0771234567', "  \n "), 'Message'],
    'sender too long' => [fn () => makeClient()->send('0771234567', 'Hi', 'TwelveChars!!'), 'Sender'],
    'sender bad chars' => [fn () => makeClient()->send('0771234567', 'Hi', 'Bill@Book'), 'Sender'],
    'bad type' => [fn () => makeClient()->send('0771234567', 'Hi', type: 'urgent'), 'type'],
    'blank status id' => [fn () => makeClient()->status(' '), 'id'],
]);

it('lists every invalid number in the validation exception', function () {
    Http::fake();

    try {
        makeClient()->send(['0771234567', '0112345678', '+14155551234'], 'Hi');
        $this->fail('Expected exception');
    } catch (ValidationException $e) {
        expect($e->invalidNumbers)->toBe(['0112345678', '+14155551234'])
            ->and($e->errorCode)->toBe('invalid_number')
            ->and($e->status)->toBe(422)
            ->and($e->getMessage())->toContain('0112345678');
    }

    Http::assertNothingSent();
});

it('accepts a valid sender name', function () {
    Http::fake(['*' => Http::response(sendOk())]);

    makeClient()->send('0771234567', 'Hi', 'Bill-Book.1');

    Http::assertSent(fn (Request $r) => $r->data()['sender'] === 'Bill-Book.1');
});

it('logs a warning above six segments without leaking the key', function () {
    Http::fake(['*' => Http::response(sendOk())]);
    $logger = new class extends AbstractLogger
    {
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message, $context];
        }
    };

    makeClient(['logger' => $logger])->send('0771234567', str_repeat('a', 1000));
    makeClient(['logger' => $logger])->send('0771234567', 'short');

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0][0])->toBe('warning')
        ->and(json_encode($logger->records))->not->toContain(TEST_KEY);
});

it('never puts the API key in exception messages', function () {
    $key = TEST_KEY;

    $cases = [
        'network' => fn () => Http::fake(fn () => throw new ConnectionException("cURL error: could not reach host with Bearer $key")),
        'server body echoing the key' => fn () => Http::fake(['*' => Http::response(errorBody('invalid_api_key', "Bad key $key"), 401)]),
        'bad body' => fn () => Http::fake(['*' => Http::response("oops $key", 502)]),
    ];

    foreach ($cases as $name => $arrange) {
        $arrange();

        try {
            makeClient(['maxRetries' => 0])->balance();
            $this->fail("Expected exception for $name");
        } catch (ItsSmsException $e) {
            $dump = $e->getMessage().' '.$e->errorCode.' '.($e->getPrevious()?->getMessage() ?? '');
            expect($dump)->not->toContain($key, "key leaked in case: $name");
        }
    }
});

it('reads status', function () {
    Http::fake(['*' => Http::response([
        'id' => 'cmp_01JB8X2K9Q', 'status' => 'completed', 'reference' => 'INV-1042',
        'messages' => [
            ['to' => '94771234567', 'status' => 'delivered', 'delivered_at' => '2026-10-30T10:01:12+05:30'],
            ['to' => '94712345678', 'status' => 'failed', 'error' => 'number_unreachable'],
        ],
    ])]);

    $status = makeClient()->status('cmp_01JB8X2K9Q');

    expect($status)->toBeInstanceOf(SendStatus::class)
        ->and($status->reference)->toBe('INV-1042')
        ->and($status->messages)->toHaveCount(2)
        ->and($status->messages[0]->deliveredAt?->format('c'))->toBe('2026-10-30T10:01:12+05:30')
        ->and($status->messages[1]->error)->toBe('number_unreachable')
        ->and($status->messages[1]->deliveredAt)->toBeNull();

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://sms.example.test/api/v1/sms/cmp_01JB8X2K9Q'
        && ! $r->hasHeader('Idempotency-Key'));
});

it('url-encodes the status id', function () {
    Http::fake(['*' => Http::response(['id' => 'a/b', 'status' => 'queued'])]);

    makeClient()->status('a/b');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sms/a%2Fb'));
});

it('reads balance', function () {
    Http::fake(['*' => Http::response(['credits' => 1498, 'plan' => 'Startup', 'plan_renews_at' => '2026-11-27', 'low_balance' => true])]);

    $balance = makeClient()->balance();

    expect($balance)->toBeInstanceOf(Balance::class)
        ->and($balance->credits)->toBe(1498)
        ->and($balance->plan)->toBe('Startup')
        ->and($balance->planRenewsAt?->format('Y-m-d'))->toBe('2026-11-27')
        ->and($balance->lowBalance)->toBeTrue();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://sms.example.test/api/v1/balance');
});

it('tolerates a missing or odd plan date in balance', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['credits' => 1])
        ->push(['credits' => 1, 'plan_renews_at' => 'not a date'])]);

    expect(makeClient()->balance()->planRenewsAt)->toBeNull()
        ->and(makeClient()->balance()->planRenewsAt)->toBeNull();
});

it('reads senders', function () {
    Http::fake(['*' => Http::response([
        ['name' => 'BillBook', 'type' => 'transactional', 'status' => 'active', 'credits' => 500],
        ['name' => 'Promo', 'type' => 'promotional', 'status' => 'pending', 'credits' => null],
    ])]);

    $senders = makeClient()->senders();

    expect($senders)->toHaveCount(2)
        ->and($senders[0])->toBeInstanceOf(Sender::class)
        ->and($senders[0]->credits)->toBe(500)
        ->and($senders[1]->credits)->toBeNull();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://sms.example.test/api/v1/senders');
});

it('reads odd status or sender payloads without failing', function () {
    Http::fake(['*' => Http::sequence()->push(['id' => 'x', 'status' => 'queued', 'messages' => ['junk']])->push(['junk'])]);

    expect(makeClient()->status('x')->messages)->toBe([])
        ->and(makeClient()->senders())->toBe([]);
});

it('keeps the low-level request channel neutral', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    $data = makeClient()->request('POST', '/anything', ['a' => 1], ['X-Extra' => 'yes']);

    expect($data)->toBe(['ok' => true]);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://sms.example.test/api/v1/anything'
        && $r->hasHeader('X-Extra', 'yes') && $r->data() === ['a' => 1]);
});

it('works with a container-built factory and trailing-slash base url', function () {
    Http::fake(['*' => Http::response(['credits' => 1])]);

    $client = new Client(app(Factory::class), TEST_KEY, 'https://sms.example.test/api/v1/');

    expect($client->balance()->credits)->toBe(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://sms.example.test/api/v1/balance');
});

it('hides the api key from dumps and refuses serialisation', function () {
    $client = makeClient();

    expect(print_r($client, true))->not->toContain(TEST_KEY)
        ->and(@var_export($client, true))->not->toContain(TEST_KEY)
        ->and(fn () => serialize($client))->toThrow(LogicException::class, 'ItsSms Client cannot be serialised');
});

it('reuses a caller idempotency key across two send calls', function () {
    Http::fake(['*' => Http::response(sendOk())]);

    makeClient()->send('0771234567', 'Hi', idempotencyKey: 'order-1042');
    makeClient()->send('0771234567', 'Hi', idempotencyKey: 'order-1042');

    $recorded = Http::recorded();
    expect($recorded[0][0]->header('Idempotency-Key')[0])->toBe('order-1042')
        ->and($recorded[1][0]->header('Idempotency-Key')[0])->toBe('order-1042');
});

it('suffixes a caller idempotency key per batch', function () {
    Http::fake(['*' => Http::response(sendOk())]);

    $numbers = array_map(fn (int $i) => '947'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 1001));
    makeClient()->send($numbers, 'Hi', idempotencyKey: 'bulk');

    $recorded = Http::recorded();
    expect($recorded[0][0]->header('Idempotency-Key')[0])->toBe('bulk:0')
        ->and($recorded[1][0]->header('Idempotency-Key')[0])->toBe('bulk:1');
});

it('rejects an idempotency key over 255 characters', function () {
    Http::fake();

    makeClient()->send('0771234567', 'Hi', idempotencyKey: str_repeat('a', 256));
})->throws(ValidationException::class);

it('exposes the results of earlier batches when a later batch fails', function () {
    Http::fake(['*' => Http::sequence()->push(sendOk(['id' => 'cmp_a']))->push(errorBody('invalid_api_key'), 401)]);

    $numbers = array_map(fn (int $i) => '947'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 1001));

    try {
        makeClient()->send($numbers, 'Hi');
        $this->fail('Expected an exception.');
    } catch (AuthException $e) {
        expect($e->sentResults)->toHaveCount(1)->and($e->sentResults[0]->id)->toBe('cmp_a');
    }
});

it('never follows a redirect', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example.test/'])]);

    try {
        makeClient()->balance();
        $this->fail('Expected an exception.');
    } catch (ItsSmsException $e) {
        expect($e->errorCode)->toBe('unexpected_redirect')->and($e->status)->toBe(302);
    }

    Http::assertSentCount(1);
});
