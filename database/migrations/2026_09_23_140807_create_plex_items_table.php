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
        Schema::create('plex_items', function (Blueprint $table) {
            $table->id();
            $table->string('plexable_type');
            $table->unsignedBigInteger('plexable_id');
            $table->string('machine_identifier')->nullable();
            $table->string('rating_key')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->unique(['plexable_type', 'plexable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plex_items');
    }
};
