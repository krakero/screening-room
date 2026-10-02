<?php

use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The setup wizard now gates the whole app until "setup.finished" is set, but installs that
     * already have a user predate that marker — mark them installed so they aren't forced back
     * through the wizard.
     */
    public function up(): void
    {
        if (User::query()->exists()) {
            IntegrationSetting::query()->updateOrCreate(['key' => 'setup.finished'], ['value' => true]);
        }
    }

    public function down(): void
    {
        //
    }
};
