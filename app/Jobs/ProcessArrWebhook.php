<?php

namespace App\Jobs;

use App\Enums\LibraryState;
use App\Enums\SeerrRequestStatus;
use App\Enums\TitleType;
use App\Events\TitleBecameAvailable;
use App\Events\TitleRequestDeclined;
use App\Events\TitleRequestFailed;
use App\Models\LibraryStatus;
use App\Models\Title;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessArrWebhook implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public WebhookEvent $event) {}

    public function handle(): void
    {
        try {
            match ($this->event->source) {
                'seerr' => $this->processSeerr($this->event->payload),
                'sonarr' => $this->processSonarr($this->event->payload),
                'radarr' => $this->processRadarr($this->event->payload),
                default => null,
            };

            $this->event->markProcessed();
        } catch (Throwable $exception) {
            $this->event->markFailed($exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processSeerr(array $payload): void
    {
        $mediaType = data_get($payload, 'media.media_type');
        $tmdbId = data_get($payload, 'media.tmdbId');

        if (! $tmdbId || ! in_array($mediaType, ['movie', 'tv'], true)) {
            return;
        }

        $title = Title::query()
            ->where('type', $mediaType === 'movie' ? TitleType::Movie : TitleType::Show)
            ->where('tmdb_id', $tmdbId)
            ->first();

        if (! $title) {
            return;
        }

        $notificationType = data_get($payload, 'notification_type');
        $requestId = data_get($payload, 'request.request_id') ?? data_get($payload, 'request.id');

        match ($notificationType) {
            'MEDIA_PENDING' => $this->upsertStatus($title, LibraryState::Requested, seerrRequestId: $requestId, seerrStatus: SeerrRequestStatus::PendingApproval),
            'MEDIA_AUTO_APPROVED', 'MEDIA_APPROVED' => $this->upsertStatus($title, LibraryState::Requested, seerrRequestId: $requestId, seerrStatus: SeerrRequestStatus::Approved),
            'MEDIA_AVAILABLE' => $this->markAvailable($title, seerrRequestId: $requestId),
            'MEDIA_DECLINED' => $this->markDeclined($title, seerrRequestId: $requestId),
            'MEDIA_FAILED' => $this->markRequestFailed($title, seerrRequestId: $requestId),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processSonarr(array $payload): void
    {
        $tvdbId = data_get($payload, 'series.tvdbId');

        if (! $tvdbId) {
            return;
        }

        $title = Title::query()->where('type', TitleType::Show)->where('tvdb_id', $tvdbId)->first();

        if (! $title) {
            return;
        }

        $this->applyEventType($title, data_get($payload, 'eventType'), sonarrId: data_get($payload, 'series.id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processRadarr(array $payload): void
    {
        $tmdbId = data_get($payload, 'movie.tmdbId');

        if (! $tmdbId) {
            return;
        }

        $title = Title::query()->where('type', TitleType::Movie)->where('tmdb_id', $tmdbId)->first();

        if (! $title) {
            return;
        }

        $this->applyEventType($title, data_get($payload, 'eventType'), radarrId: data_get($payload, 'movie.id'));
    }

    private function applyEventType(Title $title, ?string $eventType, ?int $sonarrId = null, ?int $radarrId = null): void
    {
        match ($eventType) {
            'Grab' => $this->upsertStatus($title, LibraryState::Downloading, sonarrId: $sonarrId, radarrId: $radarrId),
            'Download' => $this->markAvailable($title, sonarrId: $sonarrId, radarrId: $radarrId),
            'Delete' => LibraryStatus::where('title_id', $title->id)->delete(),
            default => null,
        };
    }

    private function upsertStatus(
        Title $title,
        LibraryState $state,
        ?int $seerrRequestId = null,
        ?SeerrRequestStatus $seerrStatus = null,
        ?int $sonarrId = null,
        ?int $radarrId = null,
    ): LibraryStatus {
        return LibraryStatus::updateOrCreate(
            ['title_id' => $title->id],
            array_filter([
                'state' => $state,
                'seerr_request_id' => $seerrRequestId,
                'seerr_status' => $seerrStatus,
                'sonarr_id' => $sonarrId,
                'radarr_id' => $radarrId,
            ], fn ($value) => $value !== null),
        );
    }

    private function markAvailable(Title $title, ?int $seerrRequestId = null, ?int $sonarrId = null, ?int $radarrId = null): void
    {
        $wasAvailable = $title->libraryStatus?->state === LibraryState::Available;

        $this->upsertStatus($title, LibraryState::Available, seerrRequestId: $seerrRequestId, sonarrId: $sonarrId, radarrId: $radarrId);

        if (! $wasAvailable) {
            TitleBecameAvailable::dispatch($title);
        }
    }

    private function markDeclined(Title $title, ?int $seerrRequestId = null): void
    {
        $wasDeclined = $title->libraryStatus?->state === LibraryState::Declined;

        LibraryStatus::updateOrCreate(
            ['title_id' => $title->id],
            array_filter([
                'state' => LibraryState::Declined,
                'seerr_request_id' => $seerrRequestId,
            ], fn ($value) => $value !== null) + ['seerr_status' => null],
        );

        if (! $wasDeclined) {
            TitleRequestDeclined::dispatch($title);
        }
    }

    private function markRequestFailed(Title $title, ?int $seerrRequestId = null): void
    {
        $wasFailed = $title->libraryStatus?->state === LibraryState::Failed;

        LibraryStatus::updateOrCreate(
            ['title_id' => $title->id],
            array_filter([
                'state' => LibraryState::Failed,
                'seerr_request_id' => $seerrRequestId,
            ], fn ($value) => $value !== null) + ['seerr_status' => null],
        );

        if (! $wasFailed) {
            TitleRequestFailed::dispatch($title);
        }
    }
}
