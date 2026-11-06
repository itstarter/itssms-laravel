<?php

use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Sleep;
use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\Facades\Sms;
use ItsStarter\Sms\Notifications\ItsSmsChannel;
use ItsStarter\Sms\Notifications\ItsSmsMessage;
use ItsStarter\Sms\PendingSms;

class SmsUser
{
    use Notifiable;

    public function __construct(public ?string $phone) {}

    public function routeNotificationForItsSms($notification): ?string
    {
        return $this->phone;
    }
}

class OtpNotification extends Notification
{
    public function __construct(private mixed $message = 'Your OTP is 482913') {}

    public function via($notifiable): array
    {
        return [ItsSmsChannel::class];
    }

    public function toItsSms($notifiable): string|ItsSmsMessage
    {
        return $this->message;
    }
}

class AliasNotification extends OtpNotification
{
    public function via($notifiable): array
    {
        return ['itssms'];
    }
}

class NoMethodNotification extends Notification
{
    public function via($notifiable): array
    {
        return [ItsSmsChannel::class];
    }
}

beforeEach(function () {
    Sleep::fake();
});

it('sends a string message through the client', function () {
    Http::fake(['*' => Http::response(['id' => 'cmp_1', 'status' => 'queued', 'recipients' => 1])]);

    (new SmsUser('0771234567'))->notify(new OtpNotification);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sms/send')
        && $request['to'] === ['94771234567']
        && $request['message'] === 'Your OTP is 482913'
        && $request->header('Authorization')[0] === 'Bearer itssms_live_DEFAULTKEY');
});

it('can be used by its short alias in via()', function () {
    Http::fake(['*' => Http::response(['id' => 'cmp_1', 'status' => 'queued'])]);

    (new SmsUser('0771234567'))->notify(new AliasNotification);

    Http::assertSentCount(1);
});

it('honours account, sender, reference, type and schedule on ItsSmsMessage', function () {
    Http::fake(['*' => Http::response(['id' => 'cmp_1', 'status' => 'scheduled'])]);

    $message = ItsSmsMessage::create('Offer')->content('Big offer')->from('Shop')->reference('promo-1')
        ->promotional()->at('2026-12-01T10:00:00+05:30')->account('teamcomrade');

    (new SmsUser('0771234567'))->notify(new OtpNotification($message));

    Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Bearer itssms_live_TEAMKEY'
        && $request['message'] === 'Big offer'
        && $request['sender'] === 'Shop'
        && $request['reference'] === 'promo-1'
        && $request['type'] === 'promotional'
        && $request['schedule_at'] === '2026-12-01T10:00:00+05:30');
});

it('exposes ItsSmsMessage state', function () {
    $message = ItsSmsMessage::create('Hi')->promotional()->transactional();

    expect($message->getContent())->toBe('Hi')
        ->and($message->getType())->toBe('transactional')
        ->and($message->getSender())->toBeNull()
        ->and($message->getReference())->toBeNull()
        ->and($message->getScheduleAt())->toBeNull()
        ->and($message->getAccount())->toBeNull();
});

it('skips silently when the notifiable has no phone number', function () {
    Http::fake();

    (new SmsUser(null))->notify(new OtpNotification);
    (new SmsUser('  '))->notify(new OtpNotification);

    Http::assertNothingSent();
});

it('skips when the notifiable has no routing method at all', function () {
    Http::fake();

    expect(app(ItsSmsChannel::class)->send(new stdClass, new OtpNotification))->toBeNull();
    Http::assertNothingSent();
});

it('throws when the notification has no toItsSms method', function () {
    (new SmsUser('0771234567'))->notify(new NoMethodNotification);
})->throws(ConfigurationException::class, 'toItsSms');

it('works with on-demand notifications', function () {
    Http::fake(['*' => Http::response(['id' => 'cmp_1', 'status' => 'queued'])]);

    NotificationFacade::route('itssms', '0771234567')->notify(new OtpNotification);

    Http::assertSent(fn (Request $request) => $request['to'] === ['94771234567']);
});

it('is recorded by Sms::fake() even when the channel was resolved earlier', function () {
    app(ItsSmsChannel::class);
    Sms::fake();

    (new SmsUser('0771234567'))->notify(new OtpNotification);
    (new SmsUser('0712345678'))->notify(new OtpNotification(ItsSmsMessage::create('Hi')->account('teamcomrade')));

    Sms::assertSentCount(2);
    Sms::assertSentTo('0771234567', fn (PendingSms $sms) => $sms->getMessage() === 'Your OTP is 482913');
    Sms::assertSent(fn (PendingSms $sms) => $sms->getAccount() === 'teamcomrade');
});

it('derives the idempotency key from the notification so a retry reuses it', function () {
    Http::fake(['*' => Http::response(['id' => 'cmp_1', 'status' => 'queued', 'recipients' => 1])]);

    $notification = new OtpNotification;
    $notification->id = 'abc-123';
    $user = new SmsUser('0771234567');

    app(ItsSmsChannel::class)->send($user, $notification);
    app(ItsSmsChannel::class)->send($user, $notification);

    $recorded = Http::recorded();
    $expected = 'notif:abc-123:'.sha1('0771234567');
    expect($recorded[0][0]->header('Idempotency-Key')[0])->toBe($expected)
        ->and($recorded[1][0]->header('Idempotency-Key')[0])->toBe($expected);
});

it('prefers an explicit message idempotency key', function () {
    Http::fake(['*' => Http::response(['id' => 'cmp_1', 'status' => 'queued', 'recipients' => 1])]);

    $notification = new OtpNotification(ItsSmsMessage::create('Hi')->idempotencyKey('mine'));
    $notification->id = 'abc-123';

    app(ItsSmsChannel::class)->send(new SmsUser('0771234567'), $notification);

    Http::assertSent(fn (Request $r) => $r->header('Idempotency-Key')[0] === 'mine');
});
