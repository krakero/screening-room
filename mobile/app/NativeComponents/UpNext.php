<?php

namespace App\NativeComponents;

use App\Exceptions\ApiUnavailable;
use App\Exceptions\Unauthenticated;
use App\Services\Api\ScreeningRoomApi;
use Carbon\Carbon;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class UpNext extends NativeComponent
{
    public bool $loading = true;

    public string $error = '';

    public string $greeting = '';

    public array $continueWatching = [];

    public array $airingThisWeek = [];

    public array $recentlyAdded = [];

    public array $markingWatched = [];

    public function mount(): void
    {
        $this->fetchData();
    }

    public function navTitle(): string
    {
        return 'Up Next';
    }

    public function fetchData(): void
    {
        $this->loading = true;
        $this->error = '';

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->get('up-next');

            if ($response->successful()) {
                $data = $response->json();
                $this->greeting = $data['greeting'] ?? '';
                $this->continueWatching = $this->formatContinueWatchingData($data['continue_watching'] ?? []);
                $this->airingThisWeek = $this->formatAiringThisWeekData(is_array($data['airing_this_week']) ? $data['airing_this_week'] : []);
                $this->recentlyAdded = $data['recently_added'] ?? [];
            } else {
                $this->error = 'Failed to load Up Next';
            }
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    private function formatContinueWatchingData(array $data): array
    {
        return array_map(function ($entry) {
            if (isset($entry['next_episode']['air_date'])) {
                $entry['next_episode']['formatted_air_date'] = $this->formatAirDate(
                    $entry['next_episode']['air_date'],
                    $entry['next_episode']['has_aired'] ?? false
                );
            }

            return $entry;
        }, $data);
    }

    private function formatAiringThisWeekData(array $data): array
    {
        $formatted = [];

        foreach ($data as $day => $episodes) {
            $formattedDay = $this->formatDayLabel($day);
            $formatted[$formattedDay] = array_map(function ($episode) {
                if (isset($episode['air_date'])) {
                    $episode['formatted_air_date'] = $this->formatAirDate(
                        $episode['air_date'],
                        $episode['has_aired'] ?? false
                    );
                }

                return $episode;
            }, $episodes);
        }

        return $formatted;
    }

    public function markWatched(int $episodeId): void
    {
        $this->markingWatched[$episodeId] = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->post("episodes/{$episodeId}/watch");

            if ($response->successful()) {
                // Refresh the data to show the next episode
                $this->fetchData();
            } else {
                $this->error = 'Failed to mark episode as watched';
            }
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
        } finally {
            unset($this->markingWatched[$episodeId]);
        }
    }

    public function openTitle(int $titleId): void
    {
        $this->navigate("/titles/{$titleId}");
    }

    public function retry(): void
    {
        $this->fetchData();
    }

    public function formatAirDate(?string $airDate, bool $hasAired): string
    {
        if (! $airDate) {
            return '';
        }

        try {
            $date = Carbon::parse($airDate);
            $today = Carbon::today();

            if ($hasAired) {
                // Already aired: "Aired Jul 11, 2022"
                return 'Aired '.$date->format('M j, Y');
            } else {
                // Not yet aired: "Airs Fri" or "Airs Today" or "Airs Tomorrow"
                if ($date->isSameDay($today)) {
                    return 'Airs Today';
                } elseif ($date->isSameDay($today->copy()->addDay())) {
                    return 'Airs Tomorrow';
                } else {
                    return 'Airs '.$date->format('D');
                }
            }
        } catch (\Exception $e) {
            return $airDate;
        }
    }

    public function formatDayLabel(string $dateKey): string
    {
        try {
            $date = Carbon::parse($dateKey);
            $today = Carbon::today();

            if ($date->isSameDay($today)) {
                return 'Today';
            } elseif ($date->isSameDay($today->copy()->addDay())) {
                return 'Tomorrow';
            } else {
                return $date->format('l');
            }
        } catch (\Exception $e) {
            return $dateKey;
        }
    }

    public function render(): Element|View
    {
        return $this->view('up-next');
    }
}
