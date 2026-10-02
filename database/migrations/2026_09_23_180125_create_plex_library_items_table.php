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
        Schema::create('plex_library_items', function (Blueprint $table) {
            $table->id();
            $table->string('plex_rating_key');
            $table->string('machine_identifier')->nullable();
            $table->string('type');
            $table->unsignedInteger('tmdb_id')->nullable();
            $table->string('imdb_id')->nullable();
            $table->unsignedInteger('tvdb_id')->nullable();
            $table->string('section_key')->nullable();
            $table->timestamp('indexed_at');
            $table->timestamps();

            $table->unique(['machine_identifier', 'plex_rating_key']);
            $table->index(['type', 'tmdb_id']);
            $table->index(['type', 'imdb_id']);
            $table->index(['type', 'tvdb_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plex_library_items');
    }
};
