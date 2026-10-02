<?php

use App\Models\WatchProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL DDL isn't transactional: a failed earlier run can leave these new tables
        // behind (empty, since conversion happens after they're all created). Start clean.
        Schema::dropIfExists('network_title');
        Schema::dropIfExists('networks');
        Schema::dropIfExists('title_watch_provider');
        Schema::dropIfExists('watch_providers');

        Schema::create('watch_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_id')->unique();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->unsignedInteger('display_priority')->default(0);
            $table->timestamps();
        });

        Schema::create('title_watch_provider', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('watch_provider_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('region', 2);
            $table->timestamps();

            // Named explicitly: the generated name exceeds MySQL's 64-character identifier limit.
            $table->unique(['title_id', 'watch_provider_id', 'type', 'region'], 'title_watch_provider_unique');
            $table->index(['watch_provider_id', 'region']);
        });

        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_id')->unique();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->string('origin_country', 2)->nullable();
            $table->timestamps();
        });

        Schema::create('network_title', function (Blueprint $table) {
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->primary(['network_id', 'title_id']);
        });

        $this->convertProviders();
        Schema::drop('title_watch_providers');

        $this->convertNetworks();
        Schema::table('titles', function (Blueprint $table) {
            $table->dropColumn('networks');
        });
    }

    /**
     * Reverse the migrations. Recreates the old shapes and copies the data back on a best-effort basis.
     */
    public function down(): void
    {
        Schema::create('title_watch_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('provider_id');
            $table->string('provider_name');
            $table->string('logo_path')->nullable();
            $table->string('type');
            $table->string('region', 2);
            $table->unsignedInteger('display_priority')->default(0);
            $table->timestamps();

            $table->unique(['title_id', 'provider_id', 'type', 'region']);
            $table->index(['provider_id', 'region']);
        });

        Schema::table('titles', function (Blueprint $table) {
            $table->json('networks')->nullable()->after('genres');
        });

        DB::table('title_watch_provider')->orderBy('id')->chunkById(500, function ($rows): void {
            $providers = DB::table('watch_providers')->whereIn('id', $rows->pluck('watch_provider_id')->unique())->get()->keyBy('id');

            DB::table('title_watch_providers')->insert($rows->map(fn (object $row): array => [
                'title_id' => $row->title_id,
                'provider_id' => $providers[$row->watch_provider_id]->tmdb_id,
                'provider_name' => $providers[$row->watch_provider_id]->name,
                'logo_path' => $providers[$row->watch_provider_id]->logo_path,
                'type' => $row->type,
                'region' => $row->region,
                'display_priority' => $providers[$row->watch_provider_id]->display_priority,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all());
        });

        DB::table('titles')->orderBy('id')->chunkById(200, function ($titles): void {
            $pivots = DB::table('network_title')
                ->join('networks', 'networks.id', '=', 'network_title.network_id')
                ->whereIn('network_title.title_id', $titles->pluck('id'))
                ->orderBy('network_title.position')
                ->get(['network_title.title_id', 'networks.tmdb_id', 'networks.name', 'networks.logo_path'])
                ->groupBy('title_id');

            foreach ($pivots as $titleId => $networks) {
                DB::table('titles')->where('id', $titleId)->update([
                    'networks' => json_encode($networks->map(fn (object $network): array => [
                        'id' => $network->tmdb_id,
                        'name' => $network->name,
                        'logo_path' => $network->logo_path,
                    ])->all()),
                ]);
            }
        });

        Schema::drop('network_title');
        Schema::drop('networks');
        Schema::drop('title_watch_provider');
        Schema::drop('watch_providers');
    }

    /**
     * One provider per TMDB id, taking name, logo and priority from its most recently updated row.
     * Add-on channel variants are dropped.
     */
    private function convertProviders(): void
    {
        $latest = [];

        DB::table('title_watch_providers')->orderBy('updated_at')->orderBy('id')->each(function (object $row) use (&$latest): void {
            if (! WatchProvider::isChannelVariant($row->provider_name)) {
                $latest[$row->provider_id] = $row;
            }
        }, 500);

        $now = now();

        foreach (array_chunk($latest, 200) as $chunk) {
            DB::table('watch_providers')->insert(array_map(fn (object $row): array => [
                'tmdb_id' => $row->provider_id,
                'name' => $row->provider_name,
                'logo_path' => $row->logo_path,
                'display_priority' => $row->display_priority,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $ids = DB::table('watch_providers')->pluck('id', 'tmdb_id');

        DB::table('title_watch_providers')->orderBy('id')->chunkById(500, function ($rows) use ($ids): void {
            $insert = $rows
                ->filter(fn (object $row): bool => isset($ids[$row->provider_id]))
                ->map(fn (object $row): array => [
                    'title_id' => $row->title_id,
                    'watch_provider_id' => $ids[$row->provider_id],
                    'type' => $row->type,
                    'region' => $row->region,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])
                ->all();

            DB::table('title_watch_provider')->insertOrIgnore($insert);
        });
    }

    /**
     * Expands each title's `networks` JSON into `networks` rows plus ordered pivot rows.
     */
    private function convertNetworks(): void
    {
        DB::table('titles')->whereNotNull('networks')->orderBy('id')->chunkById(200, function ($titles): void {
            $now = now();
            $networks = [];
            $positions = [];

            foreach ($titles as $title) {
                foreach ((array) json_decode($title->networks, true) as $position => $network) {
                    if (! is_array($network) || ! isset($network['id'], $network['name'])) {
                        continue;
                    }

                    $networks[(int) $network['id']] = [
                        'tmdb_id' => (int) $network['id'],
                        'name' => (string) $network['name'],
                        'logo_path' => $network['logo_path'] ?? null,
                        'origin_country' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $positions[] = [$title->id, (int) $network['id'], $position];
                }
            }

            if ($networks === []) {
                return;
            }

            DB::table('networks')->upsert(array_values($networks), ['tmdb_id'], ['name', 'logo_path', 'updated_at']);

            $ids = DB::table('networks')->whereIn('tmdb_id', array_keys($networks))->pluck('id', 'tmdb_id');

            DB::table('network_title')->insertOrIgnore(array_map(fn (array $row): array => [
                'network_id' => $ids[$row[1]],
                'title_id' => $row[0],
                'position' => $row[2],
            ], $positions));
        });
    }
};
