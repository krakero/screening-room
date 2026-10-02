<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mark seasons imported before seasons-first importing as loaded, with their episode counts.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE seasons
            SET episode_count = COALESCE(episode_count, (
                    SELECT COUNT(*) FROM episodes WHERE episodes.season_id = seasons.id
                )),
                episodes_synced_at = COALESCE(episodes_synced_at, updated_at)
            WHERE EXISTS (SELECT 1 FROM episodes WHERE episodes.season_id = seasons.id)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
