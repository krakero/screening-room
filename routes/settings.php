<?php

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/devices', 'pages::settings.devices')->name('devices.edit');

    Route::livewire('settings/backups', 'pages::settings.backups')->name('settings.backups');

    Route::get('settings/backups/{filename}/download', function (string $filename, BackupManager $backups) {
        try {
            return response()->download($backups->path($filename));
        } catch (BackupException) {
            abort(404);
        }
    })->name('settings.backups.download');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');

    Route::get('settings/integrations', function (IntegrationSettings $settings) {
        $firstUnconfigured = collect([
            'plex' => $settings->configured('plex.token'),
            'seerr' => $settings->configured('seerr.api_key'),
            'sonarr' => $settings->configured('sonarr.api_key'),
            'radarr' => $settings->configured('radarr.api_key'),
            'qbittorrent' => $settings->configured('qbittorrent.url'),
            'pushover' => $settings->configured('pushover.user_key', 'pushover.app_token'),
            'mdblist' => $settings->configured('mdblist.api_key'),
        ])->search(false, strict: true);

        return redirect()->route('settings.integrations.'.($firstUnconfigured ?: 'plex'));
    })->name('settings.integrations');

    Route::livewire('settings/features/trakt', 'pages::settings.features.trakt')->name('settings.features.trakt');
    Route::redirect('settings/integrations/trakt', 'settings/features/trakt');

    Route::livewire('settings/features/collection', 'pages::settings.features.collection')->name('settings.features.collection');

    Route::livewire('settings/integrations/plex', 'pages::settings.integrations.plex')->name('settings.integrations.plex');
    Route::livewire('settings/integrations/seerr', 'pages::settings.integrations.seerr')->name('settings.integrations.seerr');
    Route::livewire('settings/integrations/sonarr', 'pages::settings.integrations.sonarr')->name('settings.integrations.sonarr');
    Route::livewire('settings/integrations/radarr', 'pages::settings.integrations.radarr')->name('settings.integrations.radarr');
    Route::livewire('settings/integrations/qbittorrent', 'pages::settings.integrations.qbittorrent')->name('settings.integrations.qbittorrent');
    Route::livewire('settings/integrations/pushover', 'pages::settings.integrations.pushover')->name('settings.integrations.pushover');
    Route::livewire('settings/integrations/tmdb', 'pages::settings.integrations.tmdb')->name('settings.integrations.tmdb');
    Route::livewire('settings/integrations/mdblist', 'pages::settings.integrations.mdblist')->name('settings.integrations.mdblist');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
