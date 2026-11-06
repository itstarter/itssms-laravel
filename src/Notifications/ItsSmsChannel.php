<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use ItsStarter\Sms\Data\SendResult;
use ItsStarter\Sms\Exceptions\ConfigurationException;
use ItsStarter\Sms\SmsManager;

final class ItsSmsChannel
{
    public function __construct(private readonly Container $container) {}

    /**
     * Sends the notification's toItsSms() message to routeNotificationForItsSms().
     * Does nothing, silently, when the notifiable has no phone number.
     *
     * @return SendResult|list<SendResult>|null
     */
    public function send(mixed $notifiable, Notification $notification): SendResult|array|null
    {
        $route = is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('itssms', $notification)
            : null;

        if (is_string($route)) {
            $route = trim($route);
        }

        if (! is_string($route) && ! is_array($route) || $route === '' || $route === []) {
            return null;
        }

        if (! method_exists($notification, 'toItsSms')) {
            throw new ConfigurationException(
                $notification::class.' must define a toItsSms() method to use the ITS SMS channel.',
                0,
                'missing_notification_method',
            );
        }

        $message = $notification->toItsSms($notifiable);

        if (! $message instanceof ItsSmsMessage) {
            $message = ItsSmsMessage::create(is_string($message) ? $message : '');
        }

        /** @var SmsManager $manager Resolved now, not at construction, so Sms::fake() is honoured. */
        $manager = $this->container->make('itssms');

        $numbers = array_values(array_map('strval', (array) $route));
        $idempotencyKey = $message->getIdempotencyKey();

        // Queued retries re-run this method, so derive a stable key from the notification.
        if ($idempotencyKey === null && $notification->id !== null) {
            $who = $notifiable instanceof Model ? (string) $notifiable->getKey() : sha1(implode(',', $numbers));
            $idempotencyKey = "notif:{$notification->id}:{$who}";
        }

        $pending = $manager->account($message->getAccount())
            ->to($numbers)
            ->message($message->getContent())
            ->from($message->getSender())
            ->reference($message->getReference())
            ->at($message->getScheduleAt());

        if ($idempotencyKey !== null) {
            $pending->idempotencyKey($idempotencyKey);
        }

        if ($message->getType() === 'promotional') {
            $pending->promotional();
        }

        return $pending->send();
    }
}
