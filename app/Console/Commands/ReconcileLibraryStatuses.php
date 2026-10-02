<?php

namespace App\Console\Commands;

use App\Enums\LibraryState;
use App\Enums\SeerrRequestStatus;
use App\Events\TitleBecameAvailable;
use App\Events\TitleRequestDeclined;
use App\Models\LibraryStatus;
use App\Services\MediaRequests\MediaRequestService;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use App\Support\IntegrationSettings;
use Illuminate\Console\Command;
use Throwable;

class ReconcileLibraryStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'library:reconcile';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconciles library statuses with Seerr/Sonarr/Radarr, catching any events missed by webhooks.';

    public function handle(SonarrClient $sonarr, RadarrClient $radarr, MediaRequestService $seerr, IntegrationSettings $settings): int
    {
        $seerrConfigured = $settings->configured('seerr.url', 'seerr.api_key');

        LibraryStatus::with('title')
            ->where('state', '!=', LibraryState::Available)
            ->get()
            ->each(function (LibraryStatus $status) use ($sonarr, $radarr, $seerr, $seerrConfigured): void {
                $title = $status->title;

                if (! $title) {
                    return;
                }

                try {
                    if ($seerrConfigured && $status->seerr_request_id && in_array($status->state, [LibraryState::Requested, LibraryState::Pending], true)) {
                        $this->reconcileSeerr($status, $seerr->mediaStatus($title));
                    }

                    if (in_array($status->fresh()->state, [LibraryState::Declined, LibraryState::Failed, LibraryState::Available], true)) {
                        return;
                    }

                    if ($title->isShow() && $title->tvdb_id) {
                        $this->reconcileSonarr($status->fresh(['title']), $sonarr->seriesByTvdbId($title->tvdb_id));
                    } elseif ($title->isMovie() && $title->tmdb_id) {
                        $this->reconcileRadarr($status->fresh(['title']), $radarr->movieByTmdbId($title->tmdb_id));
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return self::SUCCESS;
    }

    /**
     * @param  array{available: bool, request_id: int|null, seerr_status: SeerrRequestStatus|null, declined: bool}|null  $mediaStatus
     */
    private function reconcileSeerr(LibraryStatus $status, ?array $mediaStatus): void
    {
        if ($mediaStatus === null) {
            return;
        }

        if ($mediaStatus['available']) {
            $this->applyState($status, LibraryState::Available);

            return;
        }

        if ($mediaStatus['declined']) {
            $status->update(['state' => LibraryState::Declined, 'seerr_status' => null]);

            TitleRequestDeclined::dispatch($status->title);

            return;
        }

        if ($mediaStatus['seerr_status'] !== $status->seerr_status) {
            $status->update(['seerr_status' => $mediaStatus['seerr_status']]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $series
     */
    private function reconcileSonarr(LibraryStatus $status, ?array $series): void
    {
        if ($series === null) {
            return;
        }

        $percentComplete = data_get($series, 'statistics.percentOfEpisodes', 0);

        $this->applyState($status, $percentComplete >= 100 ? LibraryState::Available : LibraryState::Downloading, sonarrId: data_get($series, 'id'));
    }

    /**
     * @param  array<string, mixed>|null  $movie
     */
    private function reconcileRadarr(LibraryStatus $status, ?array $movie): void
    {
        if ($movie === null) {
            return;
        }

        $hasFile = (bool) data_get($movie, 'hasFile', false);

        $this->applyState($status, $hasFile ? LibraryState::Available : LibraryState::Downloading, radarrId: data_get($movie, 'id'));
    }

    private function applyState(LibraryStatus $status, LibraryState $state, ?int $sonarrId = null, ?int $radarrId = null): void
    {
        if ($status->state === $state && $sonarrId === $status->sonarr_id && $radarrId === $status->radarr_id) {
            return;
        }

        $becameAvailable = $state === LibraryState::Available && $status->state !== LibraryState::Available;

        $status->update(array_filter([
            'state' => $state,
            'sonarr_id' => $sonarrId,
            'radarr_id' => $radarrId,
        ], fn ($value) => $value !== null));

        if ($becameAvailable) {
            TitleBecameAvailable::dispatch($status->title);
        }
    }
}
