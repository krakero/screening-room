<?php

namespace App\NativeComponents;

use App\Exceptions\ApiUnavailable;
use App\Exceptions\Unauthenticated;
use App\Services\Api\ScreeningRoomApi;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Calendar extends NativeComponent
{
    public bool $loading = true;

    public bool $loadingMore = false;

    public bool $loadingCatchUp = true;

    public string $error = '';

    public array $catchUpEntries = [];

    public array $entries = [];

    public array $groupedEntries = [];

    public Carbon $startDate;

    public Carbon $endDate;

    private const DAYS_PER_PAGE = 30;

    public function mount(): void
    {
        $this->startDate = now()->startOfDay();
        $this->endDate = now()->startOfDay()->addDays(self::DAYS_PER_PAGE - 1);

        $this->loadCatchUp();
        $this->loadCalendar();
    }

    public function navTitle(): string
    {
        return 'Calendar';
    }

    public function loadCatchUp(): void
    {
        $this->loadingCatchUp = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->get('calendar/catch-up');

            if ($response->successful()) {
                $this->catchUpEntries = $response->json('data', []);
            }
            // Silently skip catch-up if failed - it's optional
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            // Silently skip catch-up if unavailable - it's optional
        } finally {
            $this->loadingCatchUp = false;
        }
    }

    public function loadCalendar(): void
    {
        $this->loading = true;
        $this->error = '';

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->get('calendar', [
                'start' => $this->startDate->toDateString(),
                'end' => $this->endDate->toDateString(),
            ]);

            if (! $response->successful()) {
                $this->error = 'Unable to load calendar. Please try again.';
                $this->loading = false;

                return;
            }

            $this->entries = $response->json('data', []);
            $this->groupEntries();
            $this->loading = false;
        } catch (Unauthenticated $e) {
            $this->loading = false;
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
            $this->loading = false;
        }
    }

    public function loadMore(): void
    {
        if ($this->loadingMore || $this->loading) {
            return;
        }

        $this->loadingMore = true;

        $newStart = $this->endDate->clone()->addDay();
        $newEnd = $newStart->clone()->addDays(self::DAYS_PER_PAGE - 1);

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->get('calendar', [
                'start' => $newStart->toDateString(),
                'end' => $newEnd->toDateString(),
            ]);

            $newEntries = $response->json('data', []);
            $this->entries = array_merge($this->entries, $newEntries);
            $this->endDate = $newEnd;
            $this->groupEntries();
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            // Silently ignore pagination errors
        } finally {
            $this->loadingMore = false;
        }
    }

    public function refresh(): void
    {
        $this->startDate = now()->startOfDay();
        $this->endDate = now()->startOfDay()->addDays(self::DAYS_PER_PAGE - 1);
        $this->entries = [];
        $this->catchUpEntries = [];

        $this->loadCatchUp();
        $this->loadCalendar();
    }

    public function retry(): void
    {
        $this->loadCalendar();
    }

    public function openTitle(int $titleId): void
    {
        $this->navigate("/titles/{$titleId}");
    }

    private function groupEntries(): void
    {
        $grouped = [];
        $today = now()->startOfDay();

        foreach ($this->entries as $entry) {
            $date = Carbon::parse($entry['date'])->startOfDay();
            $key = $this->formatDateHeader($date, $today);

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'date' => $date,
                    'entries' => [],
                ];
            }

            $grouped[$key]['entries'][] = $entry;
        }

        // Collapse consecutive episodes of the same show on the same day
        foreach ($grouped as $key => $dayData) {
            $grouped[$key]['entries'] = $this->collapseConsecutiveEpisodes($dayData['entries']);
        }

        $this->groupedEntries = $grouped;
    }

    /**
     * Group consecutive episodes of the same show on the same day into one row
     */
    private function collapseConsecutiveEpisodes(array $entries): array
    {
        $collapsed = [];
        $currentGroup = null;

        foreach ($entries as $entry) {
            // Only group episodes (not movies or other types)
            if ($entry['type'] !== 'episode' || ! isset($entry['episode'])) {
                if ($currentGroup) {
                    $collapsed[] = $this->finalizeGroup($currentGroup);
                    $currentGroup = null;
                }
                $collapsed[] = $entry;

                continue;
            }

            $titleId = $entry['title']['id'];
            $seasonNumber = $entry['episode']['season_number'];
            $episodeNumber = $entry['episode']['episode_number'];

            if ($currentGroup === null) {
                $currentGroup = [
                    'entry' => $entry,
                    'titleId' => $titleId,
                    'seasonNumber' => $seasonNumber,
                    'episodes' => [$entry['episode']],
                ];

                continue;
            }

            // Check if this episode continues the current group (same show, same season, consecutive episode)
            $expectedEpisodeNumber = $currentGroup['episodes'][count($currentGroup['episodes']) - 1]['episode_number'] + 1;

            if ($titleId === $currentGroup['titleId']
                && $seasonNumber === $currentGroup['seasonNumber']
                && $episodeNumber === $expectedEpisodeNumber) {
                $currentGroup['episodes'][] = $entry['episode'];
            } else {
                // Different show or non-consecutive, finalize current group
                $collapsed[] = $this->finalizeGroup($currentGroup);
                $currentGroup = [
                    'entry' => $entry,
                    'titleId' => $titleId,
                    'seasonNumber' => $seasonNumber,
                    'episodes' => [$entry['episode']],
                ];
            }
        }

        if ($currentGroup) {
            $collapsed[] = $this->finalizeGroup($currentGroup);
        }

        return $collapsed;
    }

    private function finalizeGroup(array $group): array
    {
        $entry = $group['entry'];

        if (count($group['episodes']) === 1) {
            return $entry;
        }

        // Multiple consecutive episodes - create a grouped entry
        $firstEp = $group['episodes'][0];
        $lastEp = $group['episodes'][count($group['episodes']) - 1];
        $count = count($group['episodes']);

        return array_merge($entry, [
            '_grouped' => true,
            '_episodeCount' => $count,
            '_firstEpisode' => $firstEp,
            '_lastEpisode' => $lastEp,
            '_allWatched' => collect($group['episodes'])->every(fn ($ep) => $entry['watched'] ?? false),
        ]);
    }

    public function formatDateHeaderWithDate(Carbon $date): string
    {
        $today = now()->startOfDay();
        $dateDay = $date->copy()->startOfDay();
        $diffInDays = (int) $today->diffInDays($dateDay, false);

        if ($diffInDays === 0) {
            return 'Today · '.$date->format('D, M j');
        } elseif ($diffInDays === 1) {
            return 'Tomorrow · '.$date->format('D, M j');
        } elseif ($diffInDays === -1) {
            return 'Yesterday · '.$date->format('D, M j');
        } elseif ($diffInDays > 0 && $diffInDays < 7) {
            return $date->format('l · M j'); // "Monday · Oct 21"
        } else {
            return $date->format('D, M j'); // "Thu, Oct 22"
        }
    }

    private function formatDateHeader(Carbon $date, Carbon $today): string
    {
        // Use day-level comparison to avoid timezone/time-of-day issues
        $dateDay = $date->copy()->startOfDay();
        $todayDay = $today->copy()->startOfDay();

        $diffInDays = (int) $todayDay->diffInDays($dateDay, false);

        if ($diffInDays === 0) {
            return 'Today';
        } elseif ($diffInDays === 1) {
            return 'Tomorrow';
        } elseif ($diffInDays === -1) {
            return 'Yesterday';
        } elseif ($diffInDays > 0 && $diffInDays < 7) {
            return $date->format('l'); // Day name
        } else {
            return $date->format('D, M j'); // "Thu, Oct 22"
        }
    }

    public function render(): Element|View
    {
        return $this->view('calendar');
    }
}
