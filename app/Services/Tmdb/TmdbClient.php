<?php

namespace App\Services\Tmdb;

use App\Concerns\NormalizesTmdbArrays;
use App\Enums\TitleType;
use App\Support\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class TmdbClient
{
    use NormalizesTmdbArrays;

    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * The token in use, resolved from the database first, then the environment. Null when neither is set.
     */
    public function token(): ?string
    {
        return $this->settings->get('tmdb.token') ?: (config('services.tmdb.token') ?: null);
    }

    /**
     * The region in use, resolved from the database first, then the environment.
     */
    public function region(): string
    {
        return $this->settings->get('tmdb.region') ?: config('services.tmdb.region', 'US');
    }

    /**
     * Whether streaming availability ("where to watch" and the Service filter) is shown. On unless turned off.
     */
    public function showWatchProviders(): bool
    {
        return filter_var($this->settings->get('tmdb.show_watch_providers', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->client()->get('/authentication');
        } catch (Throwable) {
            return false;
        }

        return $response->successful();
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $query, int $page = 1): array
    {
        $data = $this->get('/search/multi', [
            'query' => $query,
            'page' => $page,
        ]);

        $data['results'] = collect($this->arrayOfArrays($data['results'] ?? []))
            ->filter(fn (array $result): bool => in_array($result['media_type'] ?? null, ['movie', 'tv'], true))
            ->values()
            ->all();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function movie(int $tmdbId): array
    {
        return $this->get("/movie/{$tmdbId}", [
            'append_to_response' => 'credits,external_ids,videos,watch/providers',
            'include_video_language' => $this->includeVideoLanguage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $tmdbId): array
    {
        return $this->get("/tv/{$tmdbId}", [
            'append_to_response' => 'aggregate_credits,external_ids,videos,watch/providers',
            'include_video_language' => $this->includeVideoLanguage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function movieVideos(int $tmdbId): array
    {
        return $this->get("/movie/{$tmdbId}/videos", [
            'include_video_language' => $this->includeVideoLanguage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function showVideos(int $tmdbId): array
    {
        return $this->get("/tv/{$tmdbId}/videos", [
            'include_video_language' => $this->includeVideoLanguage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function seasonVideos(int $showTmdbId, int $seasonNumber): array
    {
        return $this->get("/tv/{$showTmdbId}/season/{$seasonNumber}/videos", [
            'include_video_language' => $this->includeVideoLanguage(),
        ]);
    }

    /**
     * Resolve a movie/show/episode by an external id (imdb_id, tvdb_id, ...).
     *
     * @return array<string, mixed>
     */
    public function findByExternalId(string $externalId, string $externalSource): array
    {
        return $this->get("/find/{$externalId}", [
            'external_source' => $externalSource,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function trending(string $type = 'all', string $window = 'week'): array
    {
        return $this->get("/trending/{$type}/{$window}");
    }

    /**
     * @return array<string, mixed>
     */
    public function nowPlayingMovies(?string $region = null): array
    {
        return $this->get('/movie/now_playing', array_filter(['region' => $region]));
    }

    /**
     * @return array<string, mixed>
     */
    public function upcomingMovies(?string $region = null): array
    {
        return $this->get('/movie/upcoming', array_filter(['region' => $region]));
    }

    /**
     * @return array<string, mixed>
     */
    public function onTheAirShows(): array
    {
        return $this->get('/tv/on_the_air');
    }

    /**
     * @return array<string, mixed>
     */
    public function recommendations(TitleType $type, int $tmdbId): array
    {
        $endpoint = $type === TitleType::Movie ? "/movie/{$tmdbId}/recommendations" : "/tv/{$tmdbId}/recommendations";

        return $this->get($endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    public function episode(int $showTmdbId, int $seasonNumber, int $episodeNumber): array
    {
        return $this->get("/tv/{$showTmdbId}/season/{$seasonNumber}/episode/{$episodeNumber}", [
            'append_to_response' => 'credits',
        ]);
    }

    /**
     * @param  array<int, int>  $seasonNumbers
     * @return array<int, array<string, mixed>>
     */
    public function seasons(int $showTmdbId, array $seasonNumbers): array
    {
        $seasons = [];

        foreach (array_chunk($seasonNumbers, 20) as $batch) {
            $appendToResponse = collect($batch)
                ->map(fn (int $seasonNumber): string => "season/{$seasonNumber}")
                ->implode(',');

            $data = $this->get("/tv/{$showTmdbId}", [
                'append_to_response' => $appendToResponse,
            ]);

            foreach ($batch as $seasonNumber) {
                if (isset($data["season/{$seasonNumber}"])) {
                    $seasons[$seasonNumber] = $data["season/{$seasonNumber}"];
                }
            }
        }

        return $seasons;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $endpoint, array $query = []): array
    {
        try {
            $response = $this->client()->get($endpoint, $query);
        } catch (ConnectionException $exception) {
            throw TmdbException::connectionFailed($endpoint, $exception->getMessage());
        } catch (RequestException $exception) {
            throw TmdbException::requestFailed($endpoint, $exception->response->status(), $exception->response->body());
        }

        return $response->json();
    }

    /**
     * Languages to include when TMDB returns `videos`: the app locale, then English, then untagged
     * ("null") videos — so a trailer is still found when nothing matches the app's own locale.
     */
    private function includeVideoLanguage(): string
    {
        $languages = collect([config('app.locale', 'en'), 'en'])->unique()->implode(',');

        return "{$languages},null";
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(config('services.tmdb.base_url'))
            ->withToken($this->token())
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(3, 100, function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException) {
                    $status = $exception->response->status();

                    return $status === 429 || $status >= 500;
                }

                return false;
            });
    }
}
