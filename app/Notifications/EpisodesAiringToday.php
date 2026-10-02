<?php

namespace App\Notifications;

use App\Notifications\Messages\PushoverMessage;
use App\Services\CalendarEntry;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class EpisodesAiringToday extends Notification
{
    /**
     * @param  Collection<int, CalendarEntry>  $entries
     */
    public function __construct(private readonly Collection $entries) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['pushover'];
    }

    public function toPushover(mixed $notifiable): PushoverMessage
    {
        $count = $this->entries->count();

        $title = $count === 1
            ? __('1 episode airing today')
            : __(':count episodes airing today', ['count' => $count]);

        $message = $this->entries
            ->map(fn (CalendarEntry $entry): string => sprintf(
                '%s — S%02dE%02d %s',
                $entry->title->name,
                $entry->episode?->season_number ?? 0,
                $entry->episode?->episode_number ?? 0,
                $entry->episode?->name ?? '',
            ))
            ->implode("\n");

        return new PushoverMessage(
            title: $title,
            message: $message,
            url: route('calendar'),
        );
    }
}
