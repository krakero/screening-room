<?php

namespace App\NativeComponents;

use App\Exceptions\ApiUnavailable;
use App\Exceptions\Unauthenticated;
use App\Services\Api\ScreeningRoomApi;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class TitleDetail extends NativeComponent
{
    public bool $loading = true;

    public string $error = '';

    public ?array $title = null;

    public ?array $selectedSeason = null;

    public int $selectedSeasonNumber = 0;

    public bool $loadingSeason = false;

    public string $seasonError = '';

    // Optimistic UI tracking
    public array $optimisticWatched = [];

    public ?bool $optimisticMovieWatched = null;

    public ?bool $optimisticFollowing = null;

    // Mark season watched confirmation
    public bool $showSeasonConfirm = false;

    public int $seasonConfirmCount = 0;

    public function mount(): void
    {
        $this->fetchTitle();
    }

    public function navTitle(): string
    {
        return $this->title['name'] ?? 'Title';
    }

    public function fetchTitle(): void
    {
        $this->loading = true;
        $this->error = '';

        try {
            $api = app(ScreeningRoomApi::class);
            $titleId = $this->param('id');
            $response = $api->get("titles/{$titleId}");

            if (! $response->successful()) {
                $this->error = "Server error (HTTP {$response->status()})";

                return;
            }

            $this->title = $response->json();

            // Load first season for shows
            if ($this->isShow() && ! empty($this->title['seasons'])) {
                $this->selectedSeasonNumber = $this->title['seasons'][0]['season_number'];
                $this->fetchSeason($this->selectedSeasonNumber);
            }
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function fetchSeason(int $seasonNumber): void
    {
        if (! $this->isShow()) {
            return;
        }

        $this->loadingSeason = true;
        $this->seasonError = '';
        $this->selectedSeasonNumber = $seasonNumber;

        try {
            $api = app(ScreeningRoomApi::class);
            $titleId = $this->param('id');
            $response = $api->get("titles/{$titleId}/seasons/{$seasonNumber}");

            $this->selectedSeason = $response->json();
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->seasonError = $e->getMessage();
        } finally {
            $this->loadingSeason = false;
        }
    }

    public function toggleEpisodeWatched(int $episodeId): void
    {
        if (! $this->selectedSeason) {
            return;
        }

        $episodeIndex = collect($this->selectedSeason['episodes'])
            ->search(fn ($ep) => $ep['id'] === $episodeId);

        if ($episodeIndex === false) {
            return;
        }

        $episode = $this->selectedSeason['episodes'][$episodeIndex];
        $currentlyWatched = $this->optimisticWatched[$episodeId] ?? $episode['watched'] ?? false;
        $newWatched = ! $currentlyWatched;

        // Optimistic update
        $this->optimisticWatched[$episodeId] = $newWatched;

        try {
            $api = app(ScreeningRoomApi::class);

            if ($newWatched) {
                $response = $api->post("episodes/{$episodeId}/watch");
            } else {
                $response = $api->delete("episodes/{$episodeId}/watch");
            }

            if (! $response->successful()) {
                unset($this->optimisticWatched[$episodeId]);
                $this->seasonError = "Failed to update: Server error (HTTP {$response->status()})";

                return;
            }

            // Success - update actual episode data
            $this->selectedSeason['episodes'][$episodeIndex]['watched'] = $newWatched;
            unset($this->optimisticWatched[$episodeId]);
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            // Rollback optimistic update
            unset($this->optimisticWatched[$episodeId]);
            $this->seasonError = "Failed to update: {$e->getMessage()}";
        }
    }

    public function showMarkSeasonWatchedConfirm(): void
    {
        if (! $this->selectedSeason) {
            return;
        }

        $this->seasonError = '';

        try {
            $api = app(ScreeningRoomApi::class);
            $seasonId = $this->selectedSeason['id'];
            $response = $api->get("seasons/{$seasonId}/watch/preview");

            $this->seasonConfirmCount = $response->json('aired_unwatched_count');
            $this->showSeasonConfirm = true;
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->seasonError = $e->getMessage();
        }
    }

    public function cancelMarkSeasonWatched(): void
    {
        $this->showSeasonConfirm = false;
        $this->seasonConfirmCount = 0;
    }

    public function confirmMarkSeasonWatched(): void
    {
        if (! $this->selectedSeason) {
            return;
        }

        $this->showSeasonConfirm = false;
        $this->loadingSeason = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $seasonId = $this->selectedSeason['id'];
            $api->post("seasons/{$seasonId}/watch");

            // Refresh season data
            $this->fetchSeason($this->selectedSeasonNumber);
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->seasonError = $e->getMessage();
            $this->loadingSeason = false;
        }
    }

    public function toggleMovieWatched(): void
    {
        if ($this->isShow() || ! $this->title) {
            return;
        }

        $currentlyWatched = $this->optimisticMovieWatched ?? ! empty($this->title['movie_plays']);
        $newWatched = ! $currentlyWatched;

        // Optimistic update
        $this->optimisticMovieWatched = $newWatched;

        try {
            $api = app(ScreeningRoomApi::class);
            $titleId = $this->param('id');

            if ($newWatched) {
                $response = $api->post("titles/{$titleId}/watch");
            } else {
                // Unwatch: delete the most recent play
                if (! empty($this->title['movie_plays'])) {
                    $playId = $this->title['movie_plays'][0]['id'];
                    $response = $api->delete("plays/{$playId}");
                }
            }

            if (! $response->successful()) {
                $this->optimisticMovieWatched = null;
                $this->error = "Failed to update: Server error (HTTP {$response->status()})";

                return;
            }

            // Success - refresh to get actual state
            $this->fetchTitle();
            $this->optimisticMovieWatched = null;
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            // Rollback
            $this->optimisticMovieWatched = null;
            $this->error = "Failed to update: {$e->getMessage()}";
        }
    }

    public function followAction(): void
    {
        if (! $this->isShow() || ! $this->title) {
            return;
        }

        $follow = $this->title['follow'] ?? null;

        try {
            $api = app(ScreeningRoomApi::class);
            $titleId = $this->param('id');

            if ($follow === null) {
                // Not following - create new follow
                $api->post("titles/{$titleId}/follow");
            } elseif ($follow['state'] === 'watching') {
                // Currently watching - pause it
                $api->post("follows/{$follow['id']}/pause");
            } elseif ($follow['state'] === 'paused') {
                // Currently paused - resume it
                $api->post("follows/{$follow['id']}/resume");
            }

            // Refresh to get actual state
            $this->fetchTitle();
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->error = "Failed to update: {$e->getMessage()}";
        }
    }

    public function requestTitle(): void
    {
        if (! $this->title) {
            return;
        }

        $this->error = '';

        try {
            $api = app(ScreeningRoomApi::class);
            $titleId = $this->param('id');
            $api->post("titles/{$titleId}/request");

            // Refresh to show updated request status
            $this->fetchTitle();
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function retry(): void
    {
        $this->fetchTitle();
    }

    public function retrySeason(): void
    {
        $this->fetchSeason($this->selectedSeasonNumber);
    }

    private function isShow(): bool
    {
        return $this->title && $this->title['type'] === 'show';
    }

    public function movieWatched(): bool
    {
        if ($this->optimisticMovieWatched !== null) {
            return $this->optimisticMovieWatched;
        }

        return ! empty($this->title['movie_plays']);
    }

    public function followButtonLabel(): string
    {
        if (! $this->isShow() || ! $this->title) {
            return 'Follow';
        }

        $follow = $this->title['follow'] ?? null;

        if ($follow === null) {
            return 'Follow';
        }

        return match ($follow['state']) {
            'watching' => 'Pause',
            'paused' => 'Resume',
            default => 'Follow',
        };
    }

    public function episodeWatched(int $episodeId, bool $defaultWatched): bool
    {
        return $this->optimisticWatched[$episodeId] ?? $defaultWatched;
    }

    public function canRequest(): bool
    {
        if (! $this->title) {
            return false;
        }

        $status = $this->title['request_status'] ?? null;

        return $status === null || ($status['state'] ?? null) === 'unavailable';
    }

    public function requestButtonLabel(): string
    {
        if (! $this->title || ! isset($this->title['request_status'])) {
            return 'Request';
        }

        return match ($this->title['request_status']['state'] ?? null) {
            'pending' => 'Requested',
            'processing' => 'Processing',
            'available' => 'Available',
            default => 'Request',
        };
    }

    public function render(): Element|View
    {
        return $this->view('title-detail');
    }
}
