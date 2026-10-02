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
        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->unsignedInteger('tmdb_id');
            $table->string('imdb_id', 20)->nullable()->index();
            $table->unsignedInteger('tvdb_id')->nullable()->index();
            $table->string('name');
            $table->string('original_name')->nullable();
            $table->string('original_language', 10)->nullable();
            $table->string('tagline')->nullable();
            $table->text('overview')->nullable();
            $table->string('status', 40)->nullable();
            $table->boolean('in_production')->default(false);
            $table->date('release_date')->nullable();
            $table->date('last_air_date')->nullable();
            $table->unsignedSmallInteger('runtime')->nullable();
            $table->json('genres')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('backdrop_path')->nullable();
            $table->timestamp('tmdb_synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['type', 'tmdb_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
