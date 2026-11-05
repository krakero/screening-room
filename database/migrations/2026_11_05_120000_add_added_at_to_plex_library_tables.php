<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plex_library_items', function (Blueprint $table) {
            $table->timestamp('added_at')->nullable()->after('indexed_at');
            $table->index('added_at');
        });

        Schema::table('plex_library_episodes', function (Blueprint $table) {
            $table->timestamp('added_at')->nullable()->after('indexed_at');
            $table->index('added_at');
        });
    }

    public function down(): void
    {
        Schema::table('plex_library_items', function (Blueprint $table) {
            $table->dropIndex(['added_at']);
            $table->dropColumn('added_at');
        });

        Schema::table('plex_library_episodes', function (Blueprint $table) {
            $table->dropIndex(['added_at']);
            $table->dropColumn('added_at');
        });
    }
};
