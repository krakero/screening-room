<?php

namespace App\Listeners;

use App\Events\TitleRequestDeclined;
use App\Models\User;
use App\Notifications\TitleRequestDeclined as TitleRequestDeclinedNotification;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Notification;

class SendTitleRequestDeclinedNotification
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    public function handle(TitleRequestDeclined $event): void
    {
        if (! $this->settings->configured('pushover.user_key', 'pushover.app_token')) {
            return;
        }

        $notification = new TitleRequestDeclinedNotification($event->title);

        if ($user = User::query()->first()) {
            $user->notify($notification);
        } else {
            Notification::route('pushover', true)->notify($notification);
        }
    }
}
