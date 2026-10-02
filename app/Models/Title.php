<?php

namespace App\Models;

use App\Enums\ShowStatus;
use App\Enums\TitleType;
use App\Enums\WatchProviderType;
use App\Observers\TitleObserver;
use App\Services\Tmdb\TmdbClient;
use Database\Factories\TitleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property TitleType $type
 * @property int $tmdb_id
 * @property string|null $imdb_id
 * @property int|null $tvdb_id
 * @property string $name
 * @property string|null $original_name
 * @property string|null $original_language
 * @property string|null $tagline
 * @property string|null $overview
 * @property string|null $status
 * @property bool $in_production
 * @property Carbon|null $release_date
 * @property Carbon|null $last_air_date
 * @property int|null $runtime
 * @property array<int, string>|null $genres
 * @property string|null $poster_path
 * @property string|null $backdrop_path
 * @property Carbon|null $tmdb_synced_at
 * @property Carbon|null $ratings_checked_at
 * @property string|null $trailer_site
 * @property string|null $trailer_key
 * @property Carbon|null $trailer_checked_at
 * @property Carbon|null $watch_providers_checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'type', 'tmdb_id', 'imdb_id', 'tvdb_id', 'name', 'original_name', 'original_language',
    'tagline', 'overview', 'status', 'in_production', 'release_date', 'last_air_date',
    'runtime', 'genres', 'poster_path', 'backdrop_path', 'tmdb_synced_at', 'ratings_checked_at',
    'trailer_site', 'trailer_key', 'trailer_checked_at', 'watch_providers_checked_at',
])]
#[ObservedBy(TitleObserver::class)]
class Title extends Model
{
    /** @use HasFactory<TitleFactory> */
    use HasFactory;

    /**
     * @return HasMany<Season, $this>
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class)->orderBy('season_number');
    }

    /**
     * @return HasMany<Episode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    /**
     * @return MorphMany<Credit, $this>
     */
    public function credits(): MorphMany
    {
        return $this->morphMany(Credit::class, 'creditable');
    }

    /**
     * @return MorphMany<Play, $this>
     */
    public function plays(): MorphMany
    {
        return $this->morphMany(Play::class, 'playable');
    }

    /**
     * @return MorphOne<Rating, $this>
     */
    public function rating(): MorphOne
    {
        return $this->morphOne(Rating::class, 'rateable');
    }

    /**
     * @return BelongsToMany<MediaList, $this>
     */
    public function mediaLists(): BelongsToMany
    {
        return $this->belongsToMany(MediaList::class, 'media_list_items')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * @return HasOne<Follow, $this>
     */
    public function follow(): HasOne
    {
        return $this->hasOne(Follow::class);
    }

    /**
     * @return HasOne<LibraryStatus, $this>
     */
    public function libraryStatus(): HasOne
    {
        return $this->hasOne(LibraryStatus::class);
    }

    /**
     * @return HasMany<ExternalRating, $this>
     */
    public function externalRatings(): HasMany
    {
        return $this->hasMany(ExternalRating::class);
    }

    /**
     * @return HasMany<CollectionItem, $this>
     */
    public function collectionItems(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }

    /**
     * @return BelongsToMany<WatchProvider, $this>
     */
    public function watchProviders(): BelongsToMany
    {
        return $this->belongsToMany(WatchProvider::class, 'title_watch_provider')->withPivot('type', 'region')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Network, $this>
     */
    public function networks(): BelongsToMany
    {
        return $this->belongsToMany(Network::class)->withPivot('position')->orderByPivot('position');
    }

    /**
     * @return MorphOne<PlexItem, $this>
     */
    public function plexItem(): MorphOne
    {
        return $this->morphOne(PlexItem::class, 'plexable');
    }

    public function posterUrl(string $size = 'w342'): ?string
    {
        return $this->imageUrl($this->poster_path, $size);
    }

    public function backdropUrl(string $size = 'w1280'): ?string
    {
        return $this->imageUrl($this->backdrop_path, $size);
    }

    /**
     * The current region's providers, one per provider (flatrate wins over free/ads), by display priority.
     *
     * The winning availability type is exposed as `$provider->pivot->type` (a WatchProviderType).
     *
     * @return Collection<int, WatchProvider>
     */
    public function streamingProviders(): Collection
    {
        $region = app(TmdbClient::class)->region();
        $flatrate = WatchProviderType::Flatrate->value;

        return $this->watchProviders
            ->filter(fn (WatchProvider $provider): bool => $provider->pivot->region === $region)
            ->sortBy([
                fn (WatchProvider $a, WatchProvider $b): int => ($b->pivot->type === $flatrate) <=> ($a->pivot->type === $flatrate),
                ['display_priority', 'asc'],
                ['name', 'asc'],
            ])
            ->unique('id')
            ->each(function (WatchProvider $provider): void {
                $provider->pivot->type = WatchProviderType::from($provider->pivot->type);
            })
            ->sortBy([['display_priority', 'asc'], ['name', 'asc']])
            ->values();
    }

    public static function networkLogoUrl(?string $logoPath): ?string
    {
        if ($logoPath === null) {
            return null;
        }

        return config('services.tmdb.image_base_url').'/w92'.$logoPath;
    }

    public function hasTrailer(): bool
    {
        return filled($this->trailer_site) && filled($this->trailer_key);
    }

    public function trailerEmbedUrl(): ?string
    {
        if (! $this->hasTrailer()) {
            return null;
        }

        return match ($this->trailer_site) {
            'YouTube' => "https://www.youtube-nocookie.com/embed/{$this->trailer_key}?autoplay=1",
            'Vimeo' => "https://player.vimeo.com/video/{$this->trailer_key}?autoplay=1",
            default => null,
        };
    }

    public function trailerWatchUrl(): ?string
    {
        if (! $this->hasTrailer()) {
            return null;
        }

        return match ($this->trailer_site) {
            'YouTube' => "https://www.youtube.com/watch?v={$this->trailer_key}",
            'Vimeo' => "https://vimeo.com/{$this->trailer_key}",
            default => null,
        };
    }

    public function isMovie(): bool
    {
        return $this->type === TitleType::Movie;
    }

    public function isShow(): bool
    {
        return $this->type === TitleType::Show;
    }

    public function showStatus(): ?ShowStatus
    {
        if (! $this->isShow()) {
            return null;
        }

        return ShowStatus::fromTmdbStatus($this->status);
    }

    /**
     * The year (or year range) to show for this title: a single year when there's only one
     * (a movie, or a show whose start and end years match), a closed range for an ended/canceled
     * show ("2019–2023"), or an open range for an ongoing/returning show ("2019–").
     */
    public function yearRange(): ?string
    {
        if ($this->isMovie()) {
            return $this->release_date?->format('Y');
        }

        if (! $this->release_date) {
            return null;
        }

        $startYear = $this->release_date->format('Y');

        if ($this->in_production || $this->showStatus() === ShowStatus::Ongoing) {
            return "{$startYear}+";
        }

        $endYear = $this->last_air_date?->format('Y');

        if ($endYear === null || $endYear === $startYear) {
            return $startYear;
        }

        return "{$startYear}–{$endYear}";
    }

    /**
     * @param  Builder<Title>  $query
     * @return Builder<Title>
     */
    public function scopeMovies(Builder $query): Builder
    {
        return $query->where('type', TitleType::Movie);
    }

    /**
     * @param  Builder<Title>  $query
     * @return Builder<Title>
     */
    public function scopeShows(Builder $query): Builder
    {
        return $query->where('type', TitleType::Show);
    }

    /**
     * @param  Builder<Title>  $query
     * @return Builder<Title>
     */
    public function scopeWithShowStatus(Builder $query, ShowStatus $status): Builder
    {
        return $query->where('type', TitleType::Show)->whereIn('status', $status->tmdbStatuses());
    }

    /**
     * Titles streaming or free on the given provider in the region (defaults to the configured TMDB region).
     *
     * @param  Builder<Title>  $query
     * @return Builder<Title>
     */
    public function scopeAvailableOn(Builder $query, int $watchProviderId, ?string $region = null): Builder
    {
        $region ??= app(TmdbClient::class)->region();

        return $query->whereHas('watchProviders', function (Builder $providers) use ($watchProviderId, $region): void {
            $providers->where('watch_providers.id', $watchProviderId)->where('title_watch_provider.region', $region);
        });
    }

    /**
     * Titles broadcast on the given network (`networks.id`, not the TMDB id).
     *
     * @param  Builder<Title>  $query
     * @return Builder<Title>
     */
    public function scopeOnNetwork(Builder $query, int $networkId): Builder
    {
        return $query->whereHas('networks', fn (Builder $networks) => $networks->where('networks.id', $networkId));
    }

    private function imageUrl(?string $path, string $size): ?string
    {
        if ($path === null) {
            return null;
        }

        return config('services.tmdb.image_base_url')."/{$size}{$path}";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TitleType::class,
            'genres' => 'array',
            'release_date' => 'date',
            'last_air_date' => 'date',
            'in_production' => 'boolean',
            'tmdb_synced_at' => 'datetime',
            'ratings_checked_at' => 'datetime',
            'trailer_checked_at' => 'datetime',
            'watch_providers_checked_at' => 'datetime',
        ];
    }
}
