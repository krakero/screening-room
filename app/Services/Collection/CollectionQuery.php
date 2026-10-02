<?php

namespace App\Services\Collection;

use App\Enums\CollectionFormat;
use App\Enums\TitleType;
use App\Models\CollectionItem;
use App\Models\PlexLibraryItem;
use App\Models\Title;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CollectionQuery
{
    private Builder $query;

    private ?CollectionFormat $formatFilter = null;

    private ?TitleType $typeFilter = null;

    private ?bool $loanedFilter = null;

    private bool $inPlexOnly = false;

    private string $search = '';

    private string $sortBy = 'title';

    private string $sortDirection = 'asc';

    public function __construct()
    {
        $this->query = Title::query();
    }

    public function format(?CollectionFormat $format): self
    {
        $this->formatFilter = $format;

        return $this;
    }

    public function type(?TitleType $type): self
    {
        $this->typeFilter = $type;

        return $this;
    }

    public function loaned(?bool $loaned): self
    {
        $this->loanedFilter = $loaned;

        return $this;
    }

    public function inPlexOnly(bool $inPlexOnly = true): self
    {
        $this->inPlexOnly = $inPlexOnly;

        return $this;
    }

    public function search(string $search): self
    {
        $this->search = trim($search);

        return $this;
    }

    public function sort(string $sortBy, string $direction = 'asc'): self
    {
        $this->sortBy = $sortBy;
        $this->sortDirection = $direction;

        return $this;
    }

    /**
     * @return LengthAwarePaginator<Title>
     */
    public function paginate(int $perPage = 24): LengthAwarePaginator
    {
        $this->applyFilters();
        $this->applySort();

        return $this->query
            ->with(['collectionItems', 'plexItem', 'libraryStatus'])
            ->paginate($perPage);
    }

    /**
     * @return array{copies: int, titles: int, movies: int, shows: int, loaned: int}
     */
    public function totals(): array
    {
        $titleIds = $this->getTitleIds();

        $copiesCount = CollectionItem::query()
            ->whereIn('title_id', $titleIds)
            ->whereNull('season_id')
            ->count();

        $loanedCount = CollectionItem::query()
            ->whereIn('title_id', $titleIds)
            ->whereNull('season_id')
            ->whereNotNull('loaned_to')
            ->count();

        $titles = Title::query()
            ->whereIn('id', $titleIds)
            ->select('id', 'type')
            ->get();

        return [
            'copies' => $copiesCount,
            'titles' => $titles->count(),
            'movies' => $titles->where('type', TitleType::Movie)->count(),
            'shows' => $titles->where('type', TitleType::Show)->count(),
            'loaned' => $loanedCount,
        ];
    }

    private function applyFilters(): void
    {
        $this->query->where(function (Builder $query): void {
            $query->whereHas('collectionItems', function (Builder $q): void {
                $q->whereNull('season_id');

                if ($this->formatFilter) {
                    $q->where('format', $this->formatFilter->value);
                }

                if ($this->loanedFilter !== null) {
                    if ($this->loanedFilter) {
                        $q->whereNotNull('loaned_to');
                    } else {
                        $q->whereNull('loaned_to');
                    }
                }
            });

            if ($this->inPlexOnly) {
                $query->orWhereHas('plexItem', function (Builder $q): void {
                    $q->whereNotNull('plex_rating_key');
                });
            }
        });

        if ($this->typeFilter) {
            $this->query->where('type', $this->typeFilter);
        }

        if ($this->search !== '') {
            $this->query->where('name', 'like', '%'.$this->search.'%');
        }
    }

    private function applySort(): void
    {
        switch ($this->sortBy) {
            case 'acquired_at':
                $this->query
                    ->leftJoin('collection_items', function ($join): void {
                        $join->on('titles.id', '=', 'collection_items.title_id')
                            ->whereNull('collection_items.season_id');
                    })
                    ->select('titles.*', DB::raw('MAX(collection_items.acquired_at) as latest_acquired_at'))
                    ->groupBy('titles.id')
                    ->orderBy('latest_acquired_at', $this->sortDirection)
                    ->orderBy('titles.name', 'asc');
                break;

            case 'created_at':
                $this->query
                    ->leftJoin('collection_items', function ($join): void {
                        $join->on('titles.id', '=', 'collection_items.title_id')
                            ->whereNull('collection_items.season_id');
                    })
                    ->select('titles.*', DB::raw('MIN(collection_items.created_at) as earliest_created_at'))
                    ->groupBy('titles.id')
                    ->orderBy('earliest_created_at', $this->sortDirection)
                    ->orderBy('titles.name', 'asc');
                break;

            case 'title':
            default:
                $this->query->orderBy('name', $this->sortDirection);
                break;
        }
    }

    private function getTitleIds(): array
    {
        $collectionTitleIds = CollectionItem::query()
            ->whereNull('season_id')
            ->distinct()
            ->pluck('title_id')
            ->all();

        if ($this->inPlexOnly) {
            $plexTitleIds = PlexLibraryItem::query()
                ->whereNotNull('plex_rating_key')
                ->distinct()
                ->pluck('title_id')
                ->all();

            $collectionTitleIds = array_unique(array_merge($collectionTitleIds, $plexTitleIds));
        }

        return $collectionTitleIds;
    }
}
