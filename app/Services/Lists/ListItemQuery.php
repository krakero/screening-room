<?php

namespace App\Services\Lists;

use App\Enums\ListReleaseFilter;
use App\Enums\ListSort;
use App\Enums\ShowStatus;
use App\Enums\TitleType;
use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\Title;
use App\Services\ShowProgress;
use App\Services\Tmdb\TmdbClient;
use App\Support\DisplayTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Sorts and filters a list's items. Shared by the list page and the API.
 *
 * Release date is `titles.release_date` for movies and the first air date for shows (TMDB's
 * `first_air_date` is stored in the same column). Titles without one always sort last.
 */
class ListItemQuery
{
    public function __construct(private readonly ShowProgress $showProgress) {}

    /**
     * Resolves raw (e.g. query-string) values, falling back to the defaults for anything invalid.
     *
     * @return Collection<int, MediaListItem>
     */
    public function forInput(
        MediaList $mediaList,
        ?string $sort = null,
        ?string $type = null,
        ?string $status = null,
        bool $unwatched = false,
        ?string $release = null,
        ?int $provider = null,
        ?int $network = null,
    ): Collection {
        return $this->get(
            $mediaList,
            ListSort::tryFrom((string) $sort) ?? ListSort::Manual,
            TitleType::tryFrom((string) $type),
            ShowStatus::tryFrom((string) $status),
            $unwatched,
            ListReleaseFilter::tryFrom((string) $release),
            $provider,
            $network,
        );
    }

    /**
     * @return Collection<int, MediaListItem> items with `title` (and its `libraryStatus`) loaded
     */
    public function get(
        MediaList $mediaList,
        ListSort $sort = ListSort::Manual,
        ?TitleType $type = null,
        ?ShowStatus $status = null,
        bool $unwatched = false,
        ?ListReleaseFilter $release = null,
        ?int $provider = null,
        ?int $network = null,
    ): Collection {
        if (! app(TmdbClient::class)->showWatchProviders()) {
            $provider = null;
        }

        $query = MediaListItem::query()
            ->select('media_list_items.*')
            ->join('titles', 'titles.id', '=', 'media_list_items.title_id')
            ->where('media_list_items.media_list_id', $mediaList->id)
            ->with(['title.libraryStatus'])
            ->when($type, fn (Builder $query, TitleType $type) => $query->where('titles.type', $type))
            ->when($status, fn (Builder $query, ShowStatus $status) => $query->whereHas('title', fn ($titleQuery) => $titleQuery->withShowStatus($status)))
            ->when($provider, fn (Builder $query, int $provider) => $query->whereHas('title', fn ($titleQuery) => $titleQuery->availableOn($provider)))
            ->when($network, fn (Builder $query, int $network) => $query->whereHas('title', fn ($titleQuery) => $titleQuery->onNetwork($network)))
            ->when($release, fn (Builder $query, ListReleaseFilter $release) => match ($release) {
                ListReleaseFilter::Released => $query->where('titles.release_date', '<=', DisplayTimezone::today()),
                ListReleaseFilter::Upcoming => $query->where('titles.release_date', '>', DisplayTimezone::today()),
            });

        $this->applySort($query, $sort);

        $items = $query->get();

        return $unwatched ? $this->onlyUnwatched($items) : $items;
    }

    /**
     * @param  Builder<MediaListItem>  $query
     */
    private function applySort(Builder $query, ListSort $sort): void
    {
        match ($sort) {
            ListSort::Manual => null,
            ListSort::RecentlyReleased => $query->orderByRaw('titles.release_date IS NULL')->orderByDesc('titles.release_date'),
            ListSort::OldestRelease => $query->orderByRaw('titles.release_date IS NULL')->orderBy('titles.release_date'),
            ListSort::UpcomingFirst => $query
                ->orderByRaw('CASE WHEN titles.release_date IS NULL THEN 2 WHEN titles.release_date > ? THEN 0 ELSE 1 END', [DisplayTimezone::today()->toDateString()])
                ->orderByRaw('CASE WHEN titles.release_date > ? THEN titles.release_date END ASC', [DisplayTimezone::today()->toDateString()])
                ->orderByDesc('titles.release_date'),
            ListSort::RecentlyAdded => $query->orderByDesc('media_list_items.created_at')->orderByDesc('media_list_items.id'),
            ListSort::Title => $query->orderByRaw('LOWER(titles.name)'),
            ListSort::MyRating => $query
                ->leftJoin('ratings', fn ($join) => $join
                    ->on('ratings.rateable_id', '=', 'titles.id')
                    ->where('ratings.rateable_type', (new Title)->getMorphClass()))
                ->orderByRaw('ratings.score IS NULL')
                ->orderByDesc('ratings.score'),
        };

        $query->orderBy('media_list_items.position')->orderBy('media_list_items.id');
    }

    /**
     * Movies with no plays, and shows that aren't fully watched (see ShowProgress::isComplete).
     *
     * @param  Collection<int, MediaListItem>  $items
     * @return Collection<int, MediaListItem>
     */
    private function onlyUnwatched(Collection $items): Collection
    {
        $items->pluck('title')->filter->isMovie()->pipe(
            fn (Collection $movies) => (new EloquentCollection($movies->values()->all()))->loadMissing('plays:id,playable_type,playable_id')
        );

        $progress = $this->showProgress->forMany($items->pluck('title')->filter->isShow());

        return $items
            ->filter(fn (MediaListItem $item): bool => $item->title->isMovie()
                ? $item->title->plays->isEmpty()
                : ! $progress->get($item->title_id)->isComplete)
            ->values();
    }
}
