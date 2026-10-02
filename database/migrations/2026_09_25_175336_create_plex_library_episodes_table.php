<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plex_library_episodes', function (Blueprint $table) {
            $table->id();
            $table->string('show_rating_key');
            $table->string('machine_identifier')->nullable();
            $table->unsignedInteger('season_number');
            $table->unsignedInteger('episode_number');
            $table->string('plex_rating_key');
            $table->timestamp('indexed_at');
            $table->timestamps();

            $table->unique(['machine_identifier', 'show_rating_key', 'season_number', 'episode_number'], 'plex_library_episodes_show_season_episode_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plex_library_episodes');
    }
};
