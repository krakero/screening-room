<?php

namespace App\Listeners;

use App\Events\TitleRequestFailed;
use App\Models\User;
use App\Notifications\TitleRequestFailed as TitleRequestFailedNotification;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Notification;

class SendTitleRequestFailedNotification
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    public function handle(TitleRequestFailed $event): void
    {
        if (! $this->settings->configured('pushover.user_key', 'pushover.app_token')) {
            return;
        }

        $notification = new TitleRequestFailedNotification($event->title);

        if ($user = User::query()->first()) {
            $user->notify($notification);
        } else {
            Notification::route('pushover', true)->notify($notification);
        }
    }
}
