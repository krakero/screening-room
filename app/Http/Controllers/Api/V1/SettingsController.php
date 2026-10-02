<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\IntegrationSettings;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    /**
     * Read-only configured/not-configured status per integration. Never returns secrets:
     * only booleans, mirroring the status dots on the web settings nav.
     */
    public function integrations(IntegrationSettings $settings): JsonResponse
    {
        return response()->json([
            'plex' => ['configured' => $settings->configured('plex.token')],
            'seerr' => ['configured' => $settings->configured('seerr.api_key')],
            'sonarr' => ['configured' => $settings->configured('sonarr.api_key')],
            'radarr' => ['configured' => $settings->configured('radarr.api_key')],
            'qbittorrent' => ['configured' => $settings->configured('qbittorrent.url')],
            'mdblist' => ['configured' => $settings->configured('mdblist.api_key')],
            'pushover' => ['configured' => $settings->configured('pushover.user_key', 'pushover.app_token')],
            // Trakt has no stored credential (imports are file-based); "configured" reports
            // whether an import has ever been run, mirroring the only state the web page tracks.
            'trakt' => ['configured' => filled($settings->get('trakt.last_import'))],
            'tmdb' => ['configured' => $settings->configured('tmdb.token') || filled(config('services.tmdb.token'))],
        ]);
    }
}
