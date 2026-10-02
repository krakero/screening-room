<?php

namespace App\Actions\Tmdb;

use App\Concerns\NormalizesTmdbArrays;
use App\Enums\WatchProviderType;
use App\Models\Title;
use App\Models\WatchProvider;
use App\Services\Tmdb\TmdbClient;

class SyncWatchProviders
{
    use NormalizesTmdbArrays;

    public function __construct(private readonly TmdbClient $tmdb) {}

    /**
     * Replaces the title's providers for the configured region with the streaming (flatrate) and
     * free/ad-supported entries from the TMDB `watch/providers` payload; rent and buy are ignored,
     * and so are add-on channel variants. Providers are upserted by TMDB id. Other regions are left
     * alone, and the title is marked as checked even when nothing is offered.
     *
     * @param  array<string, mixed>  $watchProviders
     */
    public function handle(Title $title, array $watchProviders): void
    {
        $region = $this->tmdb->region();
        $regionData = $watchProviders['results'][$region] ?? [];

        $entries = collect(WatchProviderType::cases())
            ->flatMap(fn (WatchProviderType $type) => collect($this->arrayOfArrays($regionData[$type->value] ?? []))
                ->reject(fn (array $provider): bool => WatchProvider::isChannelVariant((string) $provider['provider_name']))
                ->map(fn (array $provider): array => ['type' => $type->value, 'provider' => $provider]))
            ->unique(fn (array $entry): string => "{$entry['provider']['provider_id']}:{$entry['type']}")
            ->values();

        $now = now();

        // Callers (ImportMovie, ImportShow) run this inside their own import transaction, which
        // also retries on deadlock, so the delete + insert here is already atomic. Pivot writes go
        // through the relation's connection rather than the DB facade.
        $title->watchProviders()->newPivotStatement()->where('title_id', $title->id)->where('region', $region)->delete();

        if ($entries->isNotEmpty()) {
            WatchProvider::upsert(
                $entries->unique(fn (array $entry): int => (int) $entry['provider']['provider_id'])
                    ->map(fn (array $entry): array => [
                        'tmdb_id' => (int) $entry['provider']['provider_id'],
                        'name' => (string) $entry['provider']['provider_name'],
                        'logo_path' => $entry['provider']['logo_path'] ?? null,
                        'display_priority' => $entry['provider']['display_priority'] ?? 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all(),
                ['tmdb_id'],
                ['name', 'logo_path', 'display_priority', 'updated_at'],
            );

            $ids = WatchProvider::whereIn('tmdb_id', $entries->pluck('provider.provider_id')->unique()->all())->pluck('id', 'tmdb_id');

            $title->watchProviders()->newPivotStatement()->insert(
                $entries->map(fn (array $entry): array => [
                    'title_id' => $title->id,
                    'watch_provider_id' => $ids[(int) $entry['provider']['provider_id']],
                    'type' => $entry['type'],
                    'region' => $region,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }

        $title->forceFill(['watch_providers_checked_at' => $now])->save();

        $title->unsetRelation('watchProviders');
    }
}
