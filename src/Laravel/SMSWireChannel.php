<?php

declare(strict_types=1);

namespace SMSWire\Laravel;

use Illuminate\Notifications\Notification;
use SMSWire\Client;

/**
 * The 'smswire' notification channel. A notification lists it in via() and returns an
 * SMSWireMessage (or a string) from toSmswire($notifiable); the number comes from the message's
 * to(), else the notifiable's routeNotificationForSmswire(), or Notification::route('smswire', …).
 */
final class SMSWireChannel
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return array<string, mixed>|null the queued message, or null when there's no number */
    public function send(object $notifiable, Notification $notification): ?array
    {
        /** @var SMSWireMessage|string $message */
        $message = $notification->toSmswire($notifiable); // @phpstan-ignore method.notFound
        if (is_string($message)) {
            $message = new SMSWireMessage($message);
        }
        $to = $message->to;
        if ($to === null && method_exists($notifiable, 'routeNotificationFor')) {
            $to = $notifiable->routeNotificationFor('smswire', $notification);
        }
        if (!$to) {
            return null;
        }
        return $this->client->messages->send(['to' => $to] + $message->toArray(), $message->idempotencyKey);
    }
}
