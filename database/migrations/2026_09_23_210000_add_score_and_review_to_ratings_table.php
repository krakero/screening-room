<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Reuses the existing (previously unused) `note` column as `review` instead of adding a
     * duplicate text column. `thumb` is kept (not dropped) until the user re-runs the Trakt
     * ratings import to restore precise 1-10 scores; app code stops reading/writing it.
     */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->after('thumb');
            $table->renameColumn('note', 'review');
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->boolean('review_spoilers')->default(false)->after('review');
            $table->timestamp('reviewed_at')->nullable()->after('review_spoilers');
        });

        // score is in half-star units (1 = ½★ … 10 = ★★★★★); thumb up/down had no finer
        // precision, so this maps to 4 and 2 stars respectively.
        DB::table('ratings')->where('thumb', 'up')->update(['score' => 8]);
        DB::table('ratings')->where('thumb', 'down')->update(['score' => 4]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropColumn(['score', 'review_spoilers', 'reviewed_at']);
            $table->renameColumn('review', 'note');
        });
    }
};
