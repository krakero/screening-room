<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\EpisodesAiringToday;
use App\Services\CalendarQuery;
use App\Support\IntegrationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendEpisodesAiringTodayDigest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:episodes-airing-today';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a Pushover digest of episodes airing today for followed shows';

    public function handle(IntegrationSettings $settings, CalendarQuery $query): int
    {
        if (! $settings->configured('pushover.user_key', 'pushover.app_token')) {
            return self::SUCCESS;
        }

        $entries = $query->airingToday();

        if ($entries->isEmpty()) {
            return self::SUCCESS;
        }

        $notification = new EpisodesAiringToday($entries);

        if ($user = User::query()->first()) {
            $user->notify($notification);
        } else {
            Notification::route('pushover', true)->notify($notification);
        }

        return self::SUCCESS;
    }
}
