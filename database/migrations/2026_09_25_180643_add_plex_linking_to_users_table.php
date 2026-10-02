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
        Schema::table('users', function (Blueprint $table) {
            // The Plex account's stable uuid (never email) — the only thing "Sign in with Plex" matches on.
            $table->string('plex_account_id')->nullable()->unique()->after('remember_token');
            $table->string('plex_username')->nullable()->after('plex_account_id');
            $table->timestamp('plex_linked_at')->nullable()->after('plex_username');

            // False for accounts created via "Sign in with Plex" setup with a random, unknown password —
            // gates the "Set a password" prompt and blocks unlinking Plex until a real password exists.
            $table->boolean('password_set')->default(true)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plex_account_id', 'plex_username', 'plex_linked_at', 'password_set']);
        });
    }
};
