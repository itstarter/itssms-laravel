<?php

use ItsStarter\Sms\Data\Balance;
use ItsStarter\Sms\Data\SendResult;
use ItsStarter\Sms\Exceptions\ValidationException;
use ItsStarter\Sms\Facades\Sms;
use ItsStarter\Sms\PendingSms;
use ItsStarter\Sms\SmsManager;
use ItsStarter\Sms\Testing\SmsFake;
use PHPUnit\Framework\AssertionFailedError;

it('swaps the manager and records sends', function () {
    $fake = Sms::fake();

    expect($fake)->toBeInstanceOf(SmsFake::class)
        ->and(app('itssms'))->toBe($fake)
        ->and(app(SmsManager::class))->toBe($fake);

    $result = Sms::to(['0771234567', '0712345678'])->message('Hello')->send();

    expect($result)->toBeInstanceOf(SendResult::class)
        ->and($result->recipients)->toBe(2)
        ->and($result->creditsCharged)->toBe(2)
        ->and($result->status)->toBe('queued')
        ->and($result->id)->toStartWith('cmp_FAKE');

    Sms::assertSent(fn (PendingSms $sms) => $sms->hasRecipient('94771234567') && $sms->getMessage() === 'Hello');
    Sms::assertSent();
    Sms::assertSentTo('+94 77 123 4567');
    Sms::assertSentTo('0712345678', fn (PendingSms $sms) => $sms->getMessage() === 'Hello');
    Sms::assertSentCount(1);
    Sms::assertNotSent(fn (PendingSms $sms) => $sms->getMessage() === 'Other');
});

it('asserts nothing was sent', function () {
    Sms::fake();

    Sms::assertNothingSent();
    Sms::assertSentCount(0);
    Sms::assertNotSent();
});

it('fails assertions that do not hold', function () {
    Sms::fake();
    Sms::send('0771234567', 'Hello');

    expect(fn () => Sms::assertNothingSent())->toThrow(AssertionFailedError::class)
        ->and(fn () => Sms::assertSentCount(2))->toThrow(AssertionFailedError::class)
        ->and(fn () => Sms::assertSentTo('0712345678'))->toThrow(AssertionFailedError::class)
        ->and(fn () => Sms::assertSent(fn () => false))->toThrow(AssertionFailedError::class)
        ->and(fn () => Sms::assertNotSent())->toThrow(AssertionFailedError::class);
});

it('still validates numbers and does not record rejected sends', function () {
    Sms::fake();

    expect(fn () => Sms::send('12345', 'Hello'))->toThrow(ValidationException::class)
        ->and(fn () => Sms::send('0771234567', ' '))->toThrow(ValidationException::class);

    Sms::assertNothingSent();
});

it('works without any api key configured', function () {
    config(['itssms.api_key' => null]);
    Sms::fake();

    Sms::send('0771234567', 'Hello');

    Sms::assertSentCount(1);
});

it('records named accounts, even unconfigured ones', function () {
    Sms::fake();

    Sms::account('teamcomrade')->send('0771234567', 'Hi');
    Sms::account('unlisted')->to('0771234567')->message('Hi')->send();

    Sms::assertSent(fn (PendingSms $sms) => $sms->getAccount() === 'teamcomrade');
    Sms::assertSent(fn (PendingSms $sms) => $sms->getAccount() === 'unlisted');
    Sms::assertSentCount(2);
});

it('prices promotional and scheduled sends plausibly', function () {
    Sms::fake();

    $result = Sms::to('0771234567')->message('Offer')->promotional()->at('2026-12-01T10:00:00+05:30')->send();

    expect($result->creditsCharged)->toBe(2)->and($result->status)->toBe('scheduled');
});

it('answers status, balance and senders', function () {
    Sms::fake();

    expect(Sms::balance())->toBeInstanceOf(Balance::class)
        ->and(Sms::status('cmp_X')->id)->toBe('cmp_X')
        ->and(Sms::senders())->toHaveCount(1);
});
