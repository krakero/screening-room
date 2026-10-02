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
        Schema::table('library_statuses', function (Blueprint $table) {
            $table->string('seerr_status', 20)->nullable()->after('seerr_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('library_statuses', function (Blueprint $table) {
            $table->dropColumn('seerr_status');
        });
    }
};
