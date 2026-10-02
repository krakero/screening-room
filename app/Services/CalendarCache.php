<?php

namespace App\Services;

use App\Enums\CalendarEntryType;
use App\Models\Episode;
use App\Models\Title;
use App\Models\User;
use App\Services\Watch\WatchCacheVersion;
use App\Support\DisplayTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Caches CalendarQuery results the same way App\Services\UpNext\UpNextCache does: entries are
 * serialized to plain ids (never hydrated models), keyed by a version shared with Up Next
 * (App\Services\Watch\WatchCacheVersion — one "watch data changed" bust, not two systems) plus
 * the viewer's timezone, local date, and the visible range (agenda vs. month vs. catch-up), so
 * each view rolls over at local midnight independently. Hydration re-queries titles/episodes
 * (with `plexItem`) fresh on every read, so Plex availability never needs its own bust trigger.
 */
class CalendarCache
{
    /**
     * Comfortably covers a full local day plus slack around the nightly warm; the version +
     * date key make this mostly a safety net rather than the thing driving invalidation.
     */
    private const TTL_HOURS = 26;

    public function __construct(
        private readonly CalendarQuery $calendarQuery,
        private readonly WatchCacheVersion $version,
    ) {}

    /**
     * @return Collection<int, CalendarEntry>
     */
    public function forRange(Carbon $start, Carbon $end): Collection
    {
        $rows = Cache::remember(
            $this->rangeKey($start, $end),
            now()->addHours(self::TTL_HOURS),
            fn (): array => $this->serialize($this->calendarQuery->forRange($start, $end)),
        );

        return $this->hydrate($rows);
    }

    /**
     * @return Collection<int, CalendarEntry>
     */
    public function catchUp(): Collection
    {
        $rows = Cache::remember(
            $this->catchUpKey(),
            now()->addHours(self::TTL_HOURS),
            fn (): array => $this->serialize($this->calendarQuery->catchUp()),
        );

        return $this->hydrate($rows);
    }

    /**
     * Warms the default views the calendar page shows on first load: the 60-day agenda window
     * and the current month grid, plus catch-up. Other months cache on first view (via
     * forRange()'s Cache::remember) with the same TTL rather than being warmed proactively.
     */
    public function warmDefaults(): void
    {
        $today = DisplayTimezone::today();

        $this->warmRange($today, $today->clone()->addDays(60));

        $monthStart = $today->clone()->startOfMonth();
        $gridStart = $monthStart->clone()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $monthStart->clone()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $this->warmRange($gridStart, $gridEnd);

        Cache::put(
            $this->catchUpKey(),
            $this->serialize($this->calendarQuery->catchUp()),
            now()->addHours(self::TTL_HOURS),
        );
    }

    private function warmRange(Carbon $start, Carbon $end): void
    {
        Cache::put(
            $this->rangeKey($start, $end),
            $this->serialize($this->calendarQuery->forRange($start, $end)),
            now()->addHours(self::TTL_HOURS),
        );
    }

    /**
     * @param  Collection<int, CalendarEntry>  $entries
     * @return array<int, array{type: string, date: string, title_id: int, episode_id: int|null, watched: bool}>
     */
    private function serialize(Collection $entries): array
    {
        return $entries
            ->map(fn (CalendarEntry $entry): array => [
                'type' => $entry->type->value,
                'date' => $entry->date->toIso8601String(),
                'title_id' => $entry->title->id,
                'episode_id' => $entry->episode?->id,
                'watched' => $entry->watched,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{type: string, date: string, title_id: int, episode_id: int|null, watched: bool}>  $rows
     * @return Collection<int, CalendarEntry>
     */
    private function hydrate(array $rows): Collection
    {
        if ($rows === []) {
            return collect();
        }

        $rowCollection = collect($rows);
        $titleIds = $rowCollection->pluck('title_id')->unique();
        $episodeIds = $rowCollection->pluck('episode_id')->filter()->unique();

        $titles = Title::query()->whereIn('id', $titleIds)->with('plexItem')->get()->keyBy('id');
        $episodes = $episodeIds->isEmpty()
            ? collect()
            : Episode::query()->whereIn('id', $episodeIds)->with('plexItem')->get()->keyBy('id');

        return $rowCollection
            ->map(function (array $row) use ($titles, $episodes): ?CalendarEntry {
                $title = $titles->get($row['title_id']);

                if ($title === null) {
                    return null;
                }

                return new CalendarEntry(
                    type: CalendarEntryType::from($row['type']),
                    date: Carbon::parse($row['date']),
                    title: $title,
                    episode: $row['episode_id'] !== null ? $episodes->get($row['episode_id']) : null,
                    watched: $row['watched'],
                );
            })
            ->filter()
            ->values();
    }

    private function rangeKey(Carbon $start, Carbon $end): string
    {
        return sprintf(
            'calendar:v%d:user:%s:tz:%s:date:%s:range:%s:%s',
            $this->version->current(),
            $this->userId(),
            DisplayTimezone::current(),
            DisplayTimezone::today()->toDateString(),
            $start->toDateString(),
            $end->toDateString(),
        );
    }

    private function catchUpKey(): string
    {
        return sprintf(
            'calendar:v%d:user:%s:tz:%s:date:%s:catchup',
            $this->version->current(),
            $this->userId(),
            DisplayTimezone::current(),
            DisplayTimezone::today()->toDateString(),
        );
    }

    /**
     * Single-user app, but the key is still scoped by user id per the caching contract, so a
     * future multi-user mode doesn't silently share one account's calendar with another's.
     */
    private function userId(): int|string
    {
        return Auth::id() ?? User::query()->value('id') ?? 'system';
    }
}
