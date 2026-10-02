<?php

namespace App\Listeners;

use App\Events\TitleBecameAvailable;
use App\Models\User;
use App\Notifications\TitleAvailable;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Notification;

class SendTitleAvailableNotification
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    public function handle(TitleBecameAvailable $event): void
    {
        if (! $this->settings->configured('pushover.user_key', 'pushover.app_token')) {
            return;
        }

        $notification = new TitleAvailable($event->title);

        if ($user = User::query()->first()) {
            $user->notify($notification);
        } else {
            Notification::route('pushover', true)->notify($notification);
        }
    }
}
