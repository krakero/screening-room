<?php

namespace App\NativeComponents;

use App\Exceptions\ApiUnavailable;
use App\Exceptions\Unauthenticated;
use App\Services\Api\ScreeningRoomApi;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Search extends NativeComponent
{
    public string $query = '';

    public bool $loading = false;

    public string $error = '';

    public array $results = [];

    public bool $hasSearched = false;

    public array $importingIds = [];

    public array $requestingIds = [];

    public array $requestStatuses = [];

    public bool $hideWatched = false;

    public bool $hideReReleases = false;

    public array $trending = [];

    public array $newMovies = [];

    public array $newEpisodes = [];

    public array $recommendations = [];

    public string $trendingError = '';

    public string $newMoviesError = '';

    public string $newEpisodesError = '';

    public string $recommendationsError = '';

    public bool $shelvesLoaded = false;

    public function navTitle(): string
    {
        return 'Discover';
    }

    public function mount(): void
    {
        $this->loadDiscover();
    }

    public function loadDiscover(): void
    {
        $api = app(ScreeningRoomApi::class);

        // Load trending
        try {
            $response = $api->get('discover/trending');
            if ($response->successful()) {
                $this->trending = $response->json('data', []);
                $this->trendingError = '';
            } else {
                $this->trendingError = 'Could not load trending titles';
            }
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');

            return;
        } catch (ApiUnavailable $e) {
            $this->trendingError = 'Could not load trending titles';
        }

        // Load new movies
        try {
            $response = $api->get('discover/new');
            if ($response->successful()) {
                $this->newMovies = $response->json('data', []);
                $this->newMoviesError = '';
            } else {
                $this->newMoviesError = 'Could not load new movies';
            }
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');

            return;
        } catch (ApiUnavailable $e) {
            $this->newMoviesError = 'Could not load new movies';
        }

        // Load new episodes
        try {
            $response = $api->get('discover/new-episodes');
            if ($response->successful()) {
                $this->newEpisodes = $response->json('data', []);
                $this->newEpisodesError = '';
            } else {
                $this->newEpisodesError = 'Could not load new episodes';
            }
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');

            return;
        } catch (ApiUnavailable $e) {
            $this->newEpisodesError = 'Could not load new episodes';
        }

        // Load recommendations
        try {
            $response = $api->get('discover/recommendations');
            if ($response->successful()) {
                $this->recommendations = $response->json('data', []);
                $this->recommendationsError = '';
            } else {
                $this->recommendationsError = 'Could not load recommendations';
            }
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');

            return;
        } catch (ApiUnavailable $e) {
            $this->recommendationsError = 'Could not load recommendations';
        }

        $this->shelvesLoaded = true;
    }

    public function updatedQuery(): void
    {
        $this->search();
    }

    public function search(): void
    {
        $query = trim($this->query);

        if (empty($query)) {
            $this->results = [];
            $this->hasSearched = false;
            $this->error = '';

            return;
        }

        $this->loading = true;
        $this->error = '';
        $this->hasSearched = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->get('search', ['q' => $query]);

            if (! $response->successful()) {
                $this->error = $response->json('message', 'Search failed. Please try again.');
                $this->loading = false;

                return;
            }

            $this->results = $response->json('data', []);
        } catch (Unauthenticated $e) {
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function retry(): void
    {
        if (! empty(trim($this->query))) {
            $this->search();
        } else {
            $this->loadDiscover();
        }
    }

    public function refresh(): void
    {
        $this->loadDiscover();
    }

    public function openItem(string $shelf, int $index): void
    {
        $items = match ($shelf) {
            'trending' => $this->trending,
            'new' => $this->newMovies,
            'episodes' => $this->newEpisodes,
            default => [],
        };

        $item = $items[$index] ?? null;

        if ($item === null) {
            return;
        }

        if ($item['title_id'] !== null) {
            $this->navigate("/titles/{$item['title_id']}");

            return;
        }

        // Need to import first
        $this->importAndOpenItem($shelf, $index);
    }

    public function openRecommendation(int $shelfIndex, int $itemIndex): void
    {
        $shelf = $this->recommendations[$shelfIndex] ?? null;

        if ($shelf === null) {
            return;
        }

        $item = $shelf['items'][$itemIndex] ?? null;

        if ($item === null) {
            return;
        }

        if ($item['title_id'] !== null) {
            $this->navigate("/titles/{$item['title_id']}");

            return;
        }

        // Need to import first
        $this->importAndOpenRecommendation($shelfIndex, $itemIndex);
    }

    public function importAndOpenItem(string $shelf, int $index): void
    {
        $items = match ($shelf) {
            'trending' => $this->trending,
            'new' => $this->newMovies,
            'episodes' => $this->newEpisodes,
            default => null,
        };

        if ($items === null) {
            return;
        }

        $item = $items[$index] ?? null;

        if ($item === null) {
            return;
        }

        $importKey = "{$item['type']}:{$item['tmdb_id']}";
        $this->importingIds[$importKey] = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->post('titles/import', [
                'type' => $item['type'],
                'tmdb_id' => $item['tmdb_id'],
            ]);

            $imported = $response->json();
            $titleId = $imported['id'];

            // Update the item with the new title_id
            match ($shelf) {
                'trending' => $this->trending[$index]['title_id'] = $titleId,
                'new' => $this->newMovies[$index]['title_id'] = $titleId,
                'episodes' => $this->newEpisodes[$index]['title_id'] = $titleId,
                default => null,
            };

            unset($this->importingIds[$importKey]);

            $this->navigate("/titles/{$titleId}");
        } catch (Unauthenticated $e) {
            unset($this->importingIds[$importKey]);
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            unset($this->importingIds[$importKey]);
            $this->error = $e->getMessage();
        }
    }

    public function importAndOpenRecommendation(int $shelfIndex, int $itemIndex): void
    {
        $shelf = $this->recommendations[$shelfIndex] ?? null;

        if ($shelf === null) {
            return;
        }

        $item = $shelf['items'][$itemIndex] ?? null;

        if ($item === null) {
            return;
        }

        $importKey = "{$item['type']}:{$item['tmdb_id']}";
        $this->importingIds[$importKey] = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->post('titles/import', [
                'type' => $item['type'],
                'tmdb_id' => $item['tmdb_id'],
            ]);

            $imported = $response->json();
            $titleId = $imported['id'];

            // Update the item with the new title_id
            $this->recommendations[$shelfIndex]['items'][$itemIndex]['title_id'] = $titleId;

            unset($this->importingIds[$importKey]);

            $this->navigate("/titles/{$titleId}");
        } catch (Unauthenticated $e) {
            unset($this->importingIds[$importKey]);
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            unset($this->importingIds[$importKey]);
            $this->error = $e->getMessage();
        }
    }

    public function filterItems(array $items): array
    {
        return collect($items)
            ->filter(function (array $item): bool {
                if ($this->hideWatched && $item['watched']) {
                    return false;
                }

                if ($this->hideReReleases && $item['is_re_release']) {
                    return false;
                }

                return true;
            })
            ->values()
            ->all();
    }

    public function shouldShowItem(array $item): bool
    {
        if ($this->hideWatched && $item['watched']) {
            return false;
        }

        if ($this->hideReReleases && $item['is_re_release']) {
            return false;
        }

        return true;
    }

    public function openResult(int $index): void
    {
        $result = $this->results[$index] ?? null;

        if ($result === null) {
            return;
        }

        if ($result['title_id'] !== null) {
            $this->navigate("/titles/{$result['title_id']}");

            return;
        }

        // Need to import first
        $this->importAndOpen($index);
    }

    public function importAndOpen(int $index): void
    {
        $result = $this->results[$index] ?? null;

        if ($result === null) {
            return;
        }

        $importKey = "{$result['type']}:{$result['tmdb_id']}";
        $this->importingIds[$importKey] = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->post('titles/import', [
                'type' => $result['type'],
                'tmdb_id' => $result['tmdb_id'],
            ]);

            $imported = $response->json();
            $titleId = $imported['id'];

            // Update the result with the new title_id
            $this->results[$index]['title_id'] = $titleId;
            $this->results[$index]['href_action'] = 'show';

            unset($this->importingIds[$importKey]);

            $this->navigate("/titles/{$titleId}");
        } catch (Unauthenticated $e) {
            unset($this->importingIds[$importKey]);
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            unset($this->importingIds[$importKey]);
            $this->error = $e->getMessage();
        }
    }

    public function requestTitle(int $index): void
    {
        $result = $this->results[$index] ?? null;

        if ($result === null) {
            return;
        }

        // Import first if needed
        if ($result['title_id'] === null) {
            $this->importAndRequest($index);

            return;
        }

        // Send request
        $this->sendRequest($index, $result['title_id']);
    }

    public function importAndRequest(int $index): void
    {
        $result = $this->results[$index] ?? null;

        if ($result === null) {
            return;
        }

        $importKey = "{$result['type']}:{$result['tmdb_id']}";
        $this->importingIds[$importKey] = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->post('titles/import', [
                'type' => $result['type'],
                'tmdb_id' => $result['tmdb_id'],
            ]);

            $imported = $response->json();
            $titleId = $imported['id'];

            // Update the result with the new title_id
            $this->results[$index]['title_id'] = $titleId;
            $this->results[$index]['href_action'] = 'show';

            unset($this->importingIds[$importKey]);

            $this->sendRequest($index, $titleId);
        } catch (Unauthenticated $e) {
            unset($this->importingIds[$importKey]);
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            unset($this->importingIds[$importKey]);
            $this->error = $e->getMessage();
        }
    }

    public function sendRequest(int $index, int $titleId): void
    {
        $this->requestingIds[$index] = true;

        try {
            $api = app(ScreeningRoomApi::class);
            $response = $api->post("titles/{$titleId}/request", []);

            if ($response->status() === 422) {
                // Validation error - likely Seerr not configured
                $errors = $response->json('errors', []);
                if (isset($errors['title'])) {
                    $this->error = is_array($errors['title']) ? $errors['title'][0] : $errors['title'];
                } else {
                    $this->error = $response->json('message', 'Could not send request');
                }
                unset($this->requestingIds[$index]);

                return;
            }

            if (! $response->successful()) {
                $this->error = $response->json('message', 'Request failed. Please try again.');
                unset($this->requestingIds[$index]);

                return;
            }

            $status = $response->json('status.state');
            $this->results[$index]['status'] = $status;
            $this->requestStatuses[$index] = $status;

            unset($this->requestingIds[$index]);
        } catch (Unauthenticated $e) {
            unset($this->requestingIds[$index]);
            $this->replace('/sign-in');
        } catch (ApiUnavailable $e) {
            unset($this->requestingIds[$index]);
            $this->error = $e->getMessage();
        }
    }

    public function isImporting(array $result): bool
    {
        $importKey = "{$result['type']}:{$result['tmdb_id']}";

        return isset($this->importingIds[$importKey]);
    }

    public function isRequesting(int $index): bool
    {
        return isset($this->requestingIds[$index]);
    }

    public function getRequestStatus(int $index, array $result): ?string
    {
        // Use locally updated status first
        if (isset($this->requestStatuses[$index])) {
            return $this->requestStatuses[$index];
        }

        // Fall back to API status
        return $result['status'] ?? null;
    }

    public function canRequest(array $result): bool
    {
        $status = $result['status'] ?? null;

        // Can request if: not watched, not owned, not already pending/processing/available
        if ($result['watched']) {
            return false;
        }

        if (in_array($status, ['owned', 'pending', 'processing', 'available', 'partially_available'])) {
            return false;
        }

        return true;
    }

    public function render(): Element|View
    {
        return $this->view('search');
    }
}
