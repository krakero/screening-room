<?php

namespace App\Services\Search;

use App\Enums\TitleType;
use App\Models\Title;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * TMDB multi-search, resolved against the local library so callers know which results are
 * already imported. Shared by the web search page and the API's search endpoint.
 */
class SearchTitles
{
    private const CACHE_TTL_MINUTES = 5;

    public function __construct(private readonly TmdbClient $tmdb) {}

    /**
     * @return Collection<int, array{tmdb_id: int, type: TitleType, name: string, year: ?string, poster: ?string, title: ?Title, watched: bool}>
     */
    public function handle(string $query): Collection
    {
        $data = $this->cachedSearch($query);

        $results = collect($data['results'] ?? []);
        $existing = $this->existingTitles($results);

        return $results->map(function (array $result) use ($existing): array {
            $type = $result['media_type'] === 'movie' ? TitleType::Movie : TitleType::Show;
            $title = $existing->get("{$type->value}:{$result['id']}");
            $name = $result['title'] ?? $result['name'] ?? '';
            $year = substr($result['release_date'] ?? $result['first_air_date'] ?? '', 0, 4) ?: null;

            return [
                'tmdb_id' => $result['id'],
                'type' => $type,
                'name' => $name,
                'year' => $year,
                'poster' => $this->posterUrl($result['poster_path'] ?? null),
                'backdrop' => $this->backdropUrl($result['backdrop_path'] ?? null),
                'title' => $title,
                'watched' => $title ? $this->isWatched($title) : false,
            ];
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedSearch(string $query): array
    {
        $normalized = md5(Str::lower(trim($query)));

        return Cache::remember(
            "search:tmdb:{$normalized}",
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->tmdb->search($query),
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     * @return Collection<string, Title>
     */
    private function existingTitles(Collection $results): Collection
    {
        $tmdbIds = $results->pluck('id')->all();

        return Title::whereIn('tmdb_id', $tmdbIds)
            ->with('libraryStatus')
            ->withExists([
                'mediaLists as on_list',
                'follow as is_followed',
                'plays as has_plays',
                'episodes as has_episode_plays' => fn ($query) => $query->whereHas('plays'),
            ])
            ->get()
            ->keyBy(fn (Title $title): string => "{$title->type->value}:{$title->tmdb_id}");
    }

    private function isWatched(Title $title): bool
    {
        return (bool) ($title->isMovie() ? $title->has_plays : $title->has_episode_plays);
    }

    private function posterUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return config('services.tmdb.image_base_url')."/w342{$path}";
    }

    private function backdropUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return config('services.tmdb.image_base_url')."/w1280{$path}";
    }
}
