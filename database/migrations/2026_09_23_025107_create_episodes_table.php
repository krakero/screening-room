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
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tmdb_id')->unique();
            $table->unsignedInteger('tvdb_id')->nullable()->index();
            $table->unsignedSmallInteger('season_number');
            $table->unsignedSmallInteger('episode_number');
            $table->string('name')->nullable();
            $table->text('overview')->nullable();
            $table->date('air_date')->nullable()->index();
            $table->unsignedSmallInteger('runtime')->nullable();
            $table->string('still_path')->nullable();
            $table->timestamps();

            $table->unique(['title_id', 'season_number', 'episode_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
