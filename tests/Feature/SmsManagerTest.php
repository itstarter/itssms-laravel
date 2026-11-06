<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use ItsStarter\Sms\Account;
use ItsStarter\Sms\Data\Balance;
use ItsStarter\Sms\Data\Sender;
use ItsStarter\Sms\Data\SendResult;
use ItsStarter\Sms\Data\SendStatus;
use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\Exceptions\ValidationException;
use ItsStarter\Sms\Facades\Sms;
use ItsStarter\Sms\PendingSms;
use ItsStarter\Sms\SmsManager;

function apiOk(): array
{
    return [
        'id' => 'cmp_1', 'status' => 'queued', 'recipients' => 1, 'segments' => 1,
        'credits_charged' => 1, 'credits_remaining' => 99, 'invalid_numbers' => [], 'duplicates_skipped' => 0,
    ];
}

beforeEach(function () {
    Sleep::fake();
});

it('resolves the manager through the facade and the container', function () {
    expect(app('itssms'))->toBeInstanceOf(SmsManager::class)
        ->and(app(SmsManager::class))->toBe(app('itssms'))
        ->and(Sms::account())->toBeInstanceOf(Account::class);
});

it('sends with the fluent builder using the default account', function () {
    Http::fake(['*' => Http::response(apiOk())]);

    $result = Sms::to(['0771234567', '+94712345678'])
        ->message('Hello')
        ->from('BillBook')
        ->reference('INV-1')
        ->promotional()
        ->at('2026-12-01T10:00:00+05:30')
        ->send();

    expect($result)->toBeInstanceOf(SendResult::class);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://sms.example.test/api/v1/sms/send'
            && $request->header('Authorization')[0] === 'Bearer itssms_live_DEFAULTKEY'
            && $request['to'] === ['94771234567', '94712345678']
            && $request['sender'] === 'BillBook'
            && $request['reference'] === 'INV-1'
            && $request['type'] === 'promotional'
            && $request['schedule_at'] === '2026-12-01T10:00:00+05:30';
    });
});

it('can switch back to transactional and accepts a Carbon schedule', function () {
    Http::fake(['*' => Http::response(apiOk())]);

    Sms::to('0771234567')->message('Hi')->promotional()->transactional()->at(now()->addHour())->send();

    Http::assertSent(fn (Request $request) => $request['type'] === 'transactional' && str_contains($request['schedule_at'], 'T'));
});

it('supports the send shortcut', function () {
    Http::fake(['*' => Http::response(apiOk())]);

    Sms::send('0771234567', 'Hello from BillBook');

    Http::assertSent(fn (Request $request) => $request['message'] === 'Hello from BillBook' && $request['to'] === ['94771234567']);
});

it('uses the default sender from config', function () {
    config(['itssms.sender' => 'DefaultCo']);
    Http::fake(['*' => Http::response(apiOk())]);

    Sms::send('0771234567', 'Hi');

    Http::assertSent(fn (Request $request) => $request['sender'] === 'DefaultCo');
});

it('reads status, balance and senders', function () {
    Http::fake([
        '*/sms/cmp_1' => Http::response(['id' => 'cmp_1', 'status' => 'completed', 'messages' => []]),
        '*/balance' => Http::response(['credits' => 1498, 'plan' => 'Startup']),
        '*/senders' => Http::response([['name' => 'BillBook', 'type' => 'transactional', 'status' => 'active', 'credits' => null]]),
    ]);

    expect(Sms::status('cmp_1'))->toBeInstanceOf(SendStatus::class)
        ->and(Sms::balance())->toBeInstanceOf(Balance::class)
        ->and(Sms::balance()->credits)->toBe(1498)
        ->and(Sms::senders()[0])->toBeInstanceOf(Sender::class);
});

it('offers the no-network helpers', function () {
    expect(Sms::normalizeNumber('0771234567'))->toBe('94771234567')
        ->and(Sms::normalizeNumber('12345'))->toBeNull()
        ->and(Sms::countSegments('Hello')->segments)->toBe(1)
        ->and(Sms::countSegments('Hello')->encoding)->toBe('GSM-7');
});

it('keeps each named account on its own key and sender', function () {
    Http::fake(['*' => Http::response(apiOk())]);

    Sms::account('teamcomrade')->to('0771234567')->message('Team')->send();
    Sms::to('0771234567')->message('Default')->send();
    Sms::account('teamcomrade')->send('0771234567', 'Team again');

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]);

    expect($sent[0]->header('Authorization')[0])->toBe('Bearer itssms_live_TEAMKEY')
        ->and($sent[0]['sender'])->toBe('TeamComrade')
        ->and($sent[1]->header('Authorization')[0])->toBe('Bearer itssms_live_DEFAULTKEY')
        ->and(isset($sent[1]['sender']))->toBeFalse()
        ->and($sent[2]->header('Authorization')[0])->toBe('Bearer itssms_live_TEAMKEY');
});

it('reads balance, status and senders on a named account', function () {
    Http::fake([
        '*/balance' => Http::response(['credits' => 5]),
        '*/senders' => Http::response([]),
        '*' => Http::response(['id' => 'x', 'status' => 'queued']),
    ]);

    expect(Sms::account('teamcomrade')->balance()->credits)->toBe(5)
        ->and(Sms::account('teamcomrade')->status('x')->id)->toBe('x')
        ->and(Sms::account('teamcomrade')->senders())->toBe([]);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Bearer itssms_live_TEAMKEY');
});

it('lets a named account override base_url, timeout and retries', function () {
    config(['itssms.accounts.agency' => [
        'api_key' => 'itssms_live_AGENCY', 'base_url' => 'https://other.test/api/v1', 'timeout' => 5, 'max_retries' => 0,
    ]]);
    Http::fake(['*' => Http::response(apiOk())]);

    Sms::account('agency')->send('0771234567', 'Hi');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://other.test/api/v1/'));
});

it('throws for an unknown account', function () {
    Sms::account('nope')->send('0771234567', 'Hi');
})->throws(ConfigurationException::class, 'nope');

it('throws a configuration error when no key is set', function () {
    config(['itssms.api_key' => null]);

    Sms::send('0771234567', 'Hi');
})->throws(ConfigurationException::class);

it('validates locally before any request', function () {
    Http::fake();

    try {
        Sms::to(['0771234567', '12345'])->message('Hi')->send();
    } catch (ValidationException $e) {
        expect($e->invalidNumbers)->toBe(['12345']);
    }

    Http::assertNothingSent();
});

it('exposes builder state', function () {
    $sms = Sms::to(['0771234567', '771234567', 'bad'])->message('Hi')->from('Acme')->reference('r1')->promotional();

    expect($sms)->toBeInstanceOf(PendingSms::class)
        ->and($sms->getRecipients())->toBe(['94771234567'])
        ->and($sms->getRawRecipients())->toHaveCount(3)
        ->and($sms->getMessage())->toBe('Hi')
        ->and($sms->getSender())->toBe('Acme')
        ->and($sms->getReference())->toBe('r1')
        ->and($sms->getType())->toBe('promotional')
        ->and($sms->isPromotional())->toBeTrue()
        ->and($sms->getScheduleAt())->toBeNull()
        ->and($sms->getAccount())->toBeNull()
        ->and($sms->hasRecipient('+94 77 123 4567'))->toBeTrue()
        ->and($sms->hasRecipient('0712345678'))->toBeFalse()
        ->and($sms->hasRecipient('bad'))->toBeFalse();
});

it('hides keys from manager dumps and refuses serialisation', function () {
    Http::fake(['*' => Http::response(apiOk())]);
    Sms::send('0771234567', 'Hi');

    expect(print_r(app('itssms'), true))->not->toContain('DEFAULTKEY')
        ->and(fn () => serialize(app('itssms')))->toThrow(LogicException::class);
});

it('picks up runtime config changes between sends', function () {
    Http::fake(['*' => Http::response(apiOk())]);

    Sms::send('0771234567', 'Hi');
    config(['itssms.api_key' => 'itssms_live_ROTATED']);
    Sms::send('0771234567', 'Hi');

    $recorded = Http::recorded();
    expect($recorded[0][0]->header('Authorization')[0])->toBe('Bearer itssms_live_DEFAULTKEY')
        ->and($recorded[1][0]->header('Authorization')[0])->toBe('Bearer itssms_live_ROTATED');
});
