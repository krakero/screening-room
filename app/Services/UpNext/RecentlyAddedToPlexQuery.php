<?php

namespace App\Services\UpNext;

use App\Support\IntegrationSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecentlyAddedToPlexQuery
{
    private const LIMIT = 10;

    private const WINDOW_DAYS = 14;

    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * Up to 10 recently-added items, newest first. Plain arrays only — no hydrated models.
     *
     * @return Collection<int, array{title_id: int, added_at: string, episode_count: int, season_number: ?int, episode_number: ?int}>
     */
    public function get(): Collection
    {
        if (! $this->plexConfigured()) {
            return collect();
        }

        $window = now()->subDays(self::WINDOW_DAYS);

        $movies = $this->movieEntries($window);
        $shows = $this->showEntries($window);

        return $movies->concat($shows)
            ->sortByDesc('added_at')
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Display label for a cached entry. Null for movies; "S2 E5" for a single new episode;
     * "3 new episodes" for multiples. Single source of truth — also used by the web and mobile.
     *
     * @param  array{episode_count: int, season_number: ?int, episode_number: ?int}  $entry
     */
    public static function label(array $entry): ?string
    {
        if ($entry['episode_count'] === 0) {
            return null;
        }

        if ($entry['episode_count'] === 1) {
            return sprintf('S%d E%d', $entry['season_number'], $entry['episode_number']);
        }

        return trans_choice(':count new episodes', $entry['episode_count']);
    }

    public function plexConfigured(): bool
    {
        return $this->settings->configured('plex.url', 'plex.token');
    }

    /**
     * @return Collection<int, array{title_id: int, added_at: string, episode_count: int, season_number: null, episode_number: null}>
     */
    private function movieEntries(CarbonInterface $window): Collection
    {
        return DB::table('plex_library_items as pli')
            ->join('titles', function (JoinClause $join): void {
                $join->on('titles.type', '=', 'pli.type')
                    ->where(function (JoinClause $sub): void {
                        $sub->whereColumn('titles.tmdb_id', 'pli.tmdb_id')
                            ->orWhereRaw('(pli.tvdb_id IS NOT NULL AND titles.tvdb_id = pli.tvdb_id)');
                    });
            })
            ->where('pli.type', 'movie')
            ->whereNotNull('pli.added_at')
            ->where('pli.added_at', '>=', $window)
            ->groupBy('titles.id')
            ->select([
                'titles.id as title_id',
                DB::raw('MAX(pli.added_at) as added_at'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'title_id' => (int) $row->title_id,
                'added_at' => Carbon::parse($row->added_at)->toIso8601String(),
                'episode_count' => 0,
                'season_number' => null,
                'episode_number' => null,
            ]);
    }

    /**
     * @return Collection<int, array{title_id: int, added_at: string, episode_count: int, season_number: ?int, episode_number: ?int}>
     */
    private function showEntries(CarbonInterface $window): Collection
    {
        return DB::table('plex_library_episodes as ple')
            ->join('plex_library_items as pli', function (JoinClause $join): void {
                $join->on('pli.plex_rating_key', '=', 'ple.show_rating_key')
                    ->whereRaw('(pli.machine_identifier = ple.machine_identifier OR (pli.machine_identifier IS NULL AND ple.machine_identifier IS NULL))');
            })
            ->join('titles', function (JoinClause $join): void {
                $join->on('titles.type', '=', 'pli.type')
                    ->where(function (JoinClause $sub): void {
                        $sub->whereColumn('titles.tmdb_id', 'pli.tmdb_id')
                            ->orWhereRaw('(pli.tvdb_id IS NOT NULL AND titles.tvdb_id = pli.tvdb_id)');
                    });
            })
            ->where('pli.type', 'show')
            ->whereNotNull('ple.added_at')
            ->where('ple.added_at', '>=', $window)
            ->groupBy('titles.id')
            ->select([
                'titles.id as title_id',
                DB::raw('MAX(ple.added_at) as added_at'),
                DB::raw('COUNT(*) as episode_count'),
                DB::raw('CASE WHEN COUNT(*) = 1 THEN MIN(ple.season_number) ELSE NULL END as season_number'),
                DB::raw('CASE WHEN COUNT(*) = 1 THEN MIN(ple.episode_number) ELSE NULL END as episode_number'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'title_id' => (int) $row->title_id,
                'added_at' => Carbon::parse($row->added_at)->toIso8601String(),
                'episode_count' => (int) $row->episode_count,
                'season_number' => $row->season_number !== null ? (int) $row->season_number : null,
                'episode_number' => $row->episode_number !== null ? (int) $row->episode_number : null,
            ]);
    }
}
