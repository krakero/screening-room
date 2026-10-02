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
        Schema::create('external_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->decimal('value', 6, 2);
            $table->decimal('max', 6, 2);
            $table->unsignedInteger('votes')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['title_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_ratings');
    }
};
