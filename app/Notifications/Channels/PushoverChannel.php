<?php

namespace App\Notifications\Channels;

use App\Services\Pushover\PushoverClient;
use Illuminate\Notifications\Notification;

class PushoverChannel
{
    public function __construct(private readonly PushoverClient $client) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPushover')) {
            return;
        }

        $message = $notification->toPushover($notifiable);

        $this->client->send($message->title, $message->message, $message->url);
    }
}
