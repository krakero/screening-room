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
        Schema::table('follows', function (Blueprint $table) {
            $table->timestamp('rewatch_started_at')->nullable()->after('last_played_at');
            $table->unsignedInteger('rewatch_count')->default(0)->after('rewatch_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->dropColumn(['rewatch_started_at', 'rewatch_count']);
        });
    }
};
