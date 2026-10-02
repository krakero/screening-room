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
        Schema::table('seasons', function (Blueprint $table) {
            $table->string('trailer_site')->nullable()->after('episodes_synced_at');
            $table->string('trailer_key')->nullable()->after('trailer_site');
            $table->timestamp('trailer_checked_at')->nullable()->after('trailer_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn(['trailer_site', 'trailer_key', 'trailer_checked_at']);
        });
    }
};
