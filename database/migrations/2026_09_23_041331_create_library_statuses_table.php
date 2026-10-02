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
        Schema::create('library_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('state', 20);
            $table->unsignedInteger('seerr_request_id')->nullable();
            $table->unsignedInteger('sonarr_id')->nullable();
            $table->unsignedInteger('radarr_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_statuses');
    }
};
