<?php

namespace App\Services\Plex;

use App\Support\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class PlexClient
{
    private const HISTORY_PAGE_SIZE = 100;

    /**
     * Availability lookups (findByGuid, episodeRatingKey, machineIdentifier) run inline while a
     * page renders, so they use a short budget instead of the default 10s/3-retry client — a slow
     * or unreachable server must not hang the title/season page.
     */
    private const AVAILABILITY_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly IntegrationSettings $settings,
    ) {}

    public function testConnection(): bool
    {
        try {
            return $this->client()->get('/identity')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The server's unique id, used to build `app.plex.tv` deep links.
     */
    public function machineIdentifier(bool $shortTimeout = true): ?string
    {
        $data = $this->get('/identity', shortTimeout: $shortTimeout);

        $identifier = $data['MediaContainer']['machineIdentifier'] ?? null;

        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }

    /**
     * Movie and show library sections (other types, e.g. music, are skipped — only these can
     * match a local Title). Used to build the local library index (`plex:index`), never during a
     * page render.
     *
     * @return array<int, array{key: string, type: string, title: string}>
     */
    public function librarySections(): array
    {
        $data = $this->get('/library/sections');

        $sections = [];

        foreach ($data['MediaContainer']['Directory'] ?? [] as $section) {
            $type = $section['type'] ?? null;

            if (! in_array($type, ['movie', 'show'], true)) {
                continue;
            }

            $sections[] = [
                'key' => (string) $section['key'],
                'type' => $type,
                'title' => (string) ($section['title'] ?? ''),
            ];
        }

        return $sections;
    }

    /**
     * One page of a section's items with external ids resolved (`includeGuids=1`), for building
     * the local library index. Used to build the local library index (`plex:index`), never during
     * a page render.
     *
     * @return array{items: array<int, array<string, mixed>>, total_size: int}
     */
    public function sectionItems(string $sectionKey, int $start, int $size): array
    {
        $data = $this->get("/library/sections/{$sectionKey}/all", [
            'includeGuids' => 1,
        ], [
            'X-Plex-Container-Start' => (string) $start,
            'X-Plex-Container-Size' => (string) $size,
        ]);

        return [
            'items' => $data['MediaContainer']['Metadata'] ?? [],
            'total_size' => (int) ($data['MediaContainer']['totalSize'] ?? count($data['MediaContainer']['Metadata'] ?? [])),
        ];
    }

    /**
     * The rating key of a specific episode under a show, matched by season/episode number.
     */
    public function episodeRatingKey(string $showRatingKey, int $seasonNumber, int $episodeNumber): ?string
    {
        foreach ($this->showEpisodes($showRatingKey) as $episode) {
            if ($episode['season_number'] === $seasonNumber && $episode['episode_number'] === $episodeNumber) {
                return $episode['rating_key'];
            }
        }

        return null;
    }

    /**
     * Every episode under a show (one `allLeaves` call), used to build the local
     * `plex_library_episodes` index (`plex:index`, `$shortTimeout: false`) and as the batched
     * live fallback when that index is missing an episode (`plex:refresh-availability`,
     * `$shortTimeout: true`) — either way, a show's episode list is fetched at most once per
     * call, never once per episode.
     *
     * @return array<int, array{season_number: int, episode_number: int, rating_key: string}>
     */
    public function showEpisodes(string $showRatingKey, bool $shortTimeout = true): array
    {
        $data = $this->get("/library/metadata/{$showRatingKey}/allLeaves", shortTimeout: $shortTimeout);

        $episodes = [];

        foreach ($data['MediaContainer']['Metadata'] ?? [] as $item) {
            if (! isset($item['parentIndex'], $item['index'], $item['ratingKey'])) {
                continue;
            }

            $episodes[] = [
                'season_number' => (int) $item['parentIndex'],
                'episode_number' => (int) $item['index'],
                'rating_key' => (string) $item['ratingKey'],
            ];
        }

        return $episodes;
    }

    /**
     * Watch history entries newer than $since, parsed from `/status/sessions/history/all`.
     * Paged via X-Plex-Container-Start/-Size (the server may hold many thousands of entries),
     * stopping once a page's oldest entry (the server sorts viewedAt:desc) predates $since.
     *
     * @return array<int, array{rating_key: string, grandparent_rating_key: ?string, viewed_at: Carbon, type: ?string, title: string, grandparent_title: ?string, season_number: ?int, episode_number: ?int, account_id: ?string}>
     */
    public function recentHistory(Carbon $since): array
    {
        $entries = [];
        $start = 0;
        $accountId = $this->resolveAccountId();

        while (true) {
            $data = $this->get('/status/sessions/history/all', array_filter([
                'sort' => 'viewedAt:desc',
                'accountID' => $accountId,
            ], fn (mixed $value): bool => filled($value)), [
                'X-Plex-Container-Start' => (string) $start,
                'X-Plex-Container-Size' => (string) self::HISTORY_PAGE_SIZE,
            ]);

            $items = $data['MediaContainer']['Metadata'] ?? [];

            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! isset($item['viewedAt'])) {
                    continue;
                }

                $viewedAt = Carbon::createFromTimestamp((int) $item['viewedAt']);

                if ($viewedAt->lessThan($since)) {
                    continue;
                }

                $entries[] = [
                    'rating_key' => (string) $item['ratingKey'],
                    'grandparent_rating_key' => isset($item['grandparentRatingKey']) ? (string) $item['grandparentRatingKey'] : null,
                    'viewed_at' => $viewedAt,
                    'type' => $item['type'] ?? null,
                    'title' => $item['title'] ?? '',
                    'grandparent_title' => $item['grandparentTitle'] ?? null,
                    'season_number' => $item['parentIndex'] ?? null,
                    'episode_number' => $item['index'] ?? null,
                    'account_id' => isset($item['accountID']) ? (string) $item['accountID'] : null,
                ];
            }

            $oldest = end($items);
            $oldestViewedAt = isset($oldest['viewedAt']) ? Carbon::createFromTimestamp((int) $oldest['viewedAt']) : null;

            if (count($items) < self::HISTORY_PAGE_SIZE || $oldestViewedAt?->lessThan($since)) {
                break;
            }

            $start += self::HISTORY_PAGE_SIZE;
        }

        return $entries;
    }

    /**
     * External ids (tmdb, tvdb, imdb) for a library item, parsed from its `Guid` array.
     *
     * @return array<string, string>
     */
    public function metadataGuids(string $ratingKey): array
    {
        $data = $this->get("/library/metadata/{$ratingKey}");

        $metadata = $data['MediaContainer']['Metadata'][0] ?? [];

        return self::parseGuids($metadata['Guid'] ?? []);
    }

    /**
     * The server's accounts (owner is always id 1), skipping the blank-named system account.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function accounts(): array
    {
        $data = $this->get('/accounts');

        $accounts = [];

        foreach ($data['MediaContainer']['Account'] ?? [] as $account) {
            $name = (string) ($account['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $accounts[] = ['id' => (int) $account['id'], 'name' => $name];
        }

        return $accounts;
    }

    /**
     * The configured `plex.account_id` setting may be a numeric Plex account id or a username.
     * Plex's API only accepts the numeric id, so a username is resolved via `/accounts` and the
     * result cached against the setting's current value (invalidated automatically if it changes).
     */
    public function resolveAccountId(): ?string
    {
        $configured = trim((string) $this->settings->get('plex.account_id', ''));

        if ($configured === '') {
            return null;
        }

        if (ctype_digit($configured)) {
            return $configured;
        }

        if ((string) $this->settings->get('plex.account_id_resolved_for', '') === $configured) {
            $cached = $this->settings->get('plex.account_id_resolved_id');

            if (filled($cached)) {
                return (string) $cached;
            }
        }

        try {
            $accounts = $this->accounts();
        } catch (Throwable) {
            // Best-effort: never let a failed /accounts lookup break polling; omit the filter instead.
            return null;
        }

        foreach ($accounts as $account) {
            if (strcasecmp($account['name'], $configured) === 0) {
                $this->settings->setMany([
                    'plex.account_id_resolved_for' => $configured,
                    'plex.account_id_resolved_id' => (string) $account['id'],
                ]);

                return (string) $account['id'];
            }
        }

        return null;
    }

    /**
     * Parse a Plex `Guid` array (`[{"id": "tmdb://603"}, ...]`) into ['tmdb' => '603', ...].
     *
     * @param  array<int, array<string, mixed>|string>  $guids
     * @return array<string, string>
     */
    public static function parseGuids(array $guids): array
    {
        $parsed = [];

        foreach ($guids as $guid) {
            $id = is_array($guid) ? ($guid['id'] ?? null) : $guid;

            if (! is_string($id) || ! str_contains($id, '://')) {
                continue;
            }

            [$scheme, $value] = explode('://', $id, 2);

            if (in_array($scheme, ['tmdb', 'tvdb', 'imdb'], true) && $value !== '') {
                $parsed[$scheme] = $value;
            }
        }

        return $parsed;
    }

    /**
     * Parse a Metadata item's guid(s) into normalized external ids, from either agent format:
     * the modern `plex://` agent's `Guid` array (the item's OWN ids), or the legacy
     * `com.plexapp.agents.*` agent's single `guid` string. The legacy tvdb agent on an EPISODE
     * encodes the SHOW's external id plus season/episode (not the episode's own id), so that
     * case is returned separately as `show_guids` with the season/episode it carries.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{guids: array<string, string>, show_guids: array<string, string>, season_number: ?int, episode_number: ?int}
     */
    public static function guidsFromMetadata(array $metadata): array
    {
        $guids = self::parseGuids($metadata['Guid'] ?? []);
        $showGuids = [];
        $seasonNumber = null;
        $episodeNumber = null;

        if ($guids === [] && is_string($metadata['guid'] ?? null)) {
            $legacy = self::parseLegacyGuid($metadata['guid']);

            if ($legacy !== null && $legacy['season_number'] !== null && $legacy['episode_number'] !== null) {
                $showGuids = [$legacy['scheme'] => $legacy['value']];
                $seasonNumber = $legacy['season_number'];
                $episodeNumber = $legacy['episode_number'];
            } elseif ($legacy !== null) {
                $guids = [$legacy['scheme'] => $legacy['value']];
            }
        }

        return [
            'guids' => $guids,
            'show_guids' => $showGuids,
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
        ];
    }

    /**
     * Parse a legacy single `guid` string, e.g. `com.plexapp.agents.thetvdb://121361/6/1?lang=en`
     * (show tvdb id 121361, season 6, episode 1) or `com.plexapp.agents.themoviedb://603?lang=en`.
     *
     * @return array{scheme: string, value: string, season_number: ?int, episode_number: ?int}|null
     */
    public static function parseLegacyGuid(string $guid): ?array
    {
        if (! preg_match('#^com\.plexapp\.agents\.([a-z]+)://([^?]+)#', $guid, $matches)) {
            return null;
        }

        $scheme = match ($matches[1]) {
            'thetvdb' => 'tvdb',
            'themoviedb' => 'tmdb',
            'imdb' => 'imdb',
            default => null,
        };

        if ($scheme === null) {
            return null;
        }

        $segments = explode('/', $matches[2]);

        return [
            'scheme' => $scheme,
            'value' => $segments[0],
            'season_number' => isset($segments[1]) ? (int) $segments[1] : null,
            'episode_number' => isset($segments[2]) ? (int) $segments[2] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function get(string $endpoint, array $query = [], array $headers = [], bool $shortTimeout = false): array
    {
        try {
            $response = $this->client($shortTimeout)->withHeaders($headers)->get($endpoint, $query);
        } catch (ConnectionException $exception) {
            throw PlexException::connectionFailed($endpoint, $exception->getMessage());
        } catch (RequestException $exception) {
            throw PlexException::requestFailed($endpoint, $exception->response->status(), $exception->response->body());
        }

        return $response->json() ?? [];
    }

    protected function client(bool $shortTimeout = false): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->settings->get('plex.url'), '/'))
            ->withHeaders([
                'X-Plex-Token' => $this->settings->get('plex.token'),
                'Accept' => 'application/json',
            ])
            ->timeout($shortTimeout ? self::AVAILABILITY_TIMEOUT_SECONDS : 10)
            ->connectTimeout($shortTimeout ? self::AVAILABILITY_TIMEOUT_SECONDS : 5)
            ->retry($shortTimeout ? 1 : 3, 100, function (Throwable $exception): bool {
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
