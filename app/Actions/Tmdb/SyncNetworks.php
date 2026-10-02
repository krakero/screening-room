<?php

namespace App\Actions\Tmdb;

use App\Concerns\NormalizesTmdbArrays;
use App\Models\Network;
use App\Models\Title;

class SyncNetworks
{
    use NormalizesTmdbArrays;

    /**
     * Upserts the show's networks by TMDB id and replaces its pivot rows, keeping TMDB's order as
     * a zero-based `position`. Runs inside the caller's import transaction.
     *
     * @param  array<int, mixed>  $networks
     */
    public function handle(Title $title, array $networks): void
    {
        $now = now();

        $rows = collect($this->arrayOfArrays($networks))
            ->unique(fn (array $network): int => (int) $network['id'])
            ->values();

        if ($rows->isNotEmpty()) {
            Network::upsert(
                $rows->map(fn (array $network): array => [
                    'tmdb_id' => (int) $network['id'],
                    'name' => (string) $network['name'],
                    'logo_path' => $network['logo_path'] ?? null,
                    'origin_country' => isset($network['origin_country']) && $network['origin_country'] !== '' ? (string) $network['origin_country'] : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['tmdb_id'],
                ['name', 'logo_path', 'origin_country', 'updated_at'],
            );
        }

        $ids = Network::whereIn('tmdb_id', $rows->map(fn (array $network): int => (int) $network['id'])->all())->pluck('id', 'tmdb_id');

        $title->networks()->sync(
            $rows->mapWithKeys(fn (array $network, int $position): array => [
                $ids[(int) $network['id']] => ['position' => $position],
            ])->all(),
        );

        $title->unsetRelation('networks');
    }
}
