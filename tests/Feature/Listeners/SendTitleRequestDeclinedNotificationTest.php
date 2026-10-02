<?php

use App\Events\TitleRequestDeclined;
use App\Models\Title;
use App\Notifications\TitleRequestDeclined as TitleRequestDeclinedNotification;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Notification;

test('a declined request notifies via pushover when configured', function () {
    Notification::fake();

    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'user-key',
        'pushover.app_token' => 'app-token',
    ]);

    $title = Title::factory()->movie()->create();

    event(new TitleRequestDeclined($title));

    Notification::assertSentOnDemand(TitleRequestDeclinedNotification::class);
});

test('a declined request sends no notification when pushover is not configured', function () {
    Notification::fake();

    $title = Title::factory()->movie()->create();

    event(new TitleRequestDeclined($title));

    Notification::assertNothingSent();
});
