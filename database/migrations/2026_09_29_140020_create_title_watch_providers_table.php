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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('title_watch_providers');
    }
};
