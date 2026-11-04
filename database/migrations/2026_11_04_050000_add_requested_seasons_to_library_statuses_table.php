<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_statuses', function (Blueprint $table) {
            $table->json('requested_seasons')->nullable()->after('radarr_id');
        });
    }

    public function down(): void
    {
        Schema::table('library_statuses', function (Blueprint $table) {
            $table->dropColumn('requested_seasons');
        });
    }
};
