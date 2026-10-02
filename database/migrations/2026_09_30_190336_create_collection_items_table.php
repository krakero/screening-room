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
        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('format', 20);
            $table->string('edition')->nullable();
            $table->string('retailer')->nullable();
            $table->string('barcode', 32)->nullable()->index();
            $table->date('acquired_at')->nullable();
            $table->decimal('price', 8, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('location')->nullable();
            $table->string('loaned_to')->nullable();
            $table->date('loaned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['title_id', 'season_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_items');
    }
};
