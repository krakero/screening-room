<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Updates\UpdaterClient;
use App\Support\AppVersion;
use Illuminate\Support\Facades\Storage;

class UpdateStatusController extends Controller
{
    public function __invoke(UpdaterClient $updater): array
    {
        $boot = null;
        $disk = Storage::disk('updater');

        if ($disk->exists('boot.json')) {
            $boot = json_decode($disk->get('boot.json'), associative: true);
        }

        return [
            'app_up' => true,
            'version' => AppVersion::current(),
            'boot' => $boot,
            'status' => $updater->status(),
            'heartbeat_ok' => $updater->isAvailable(),
        ];
    }
}
