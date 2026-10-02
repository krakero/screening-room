<?php

namespace App\Notifications;

use App\Models\Title;
use App\Notifications\Messages\PushoverMessage;
use Illuminate\Notifications\Notification;

class TitleRequestDeclined extends Notification
{
    public function __construct(private readonly Title $title) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['pushover'];
    }

    public function toPushover(mixed $notifiable): PushoverMessage
    {
        return new PushoverMessage(
            title: __('Request declined'),
            message: __('Your request for :name was declined.', ['name' => $this->title->name]),
            url: route('titles.show', $this->title),
        );
    }
}
