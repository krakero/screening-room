<?php

namespace App\Actions\Ratings;

use App\Enums\RatingSource;
use App\Models\ExternalRating;
use App\Models\Title;
use App\Services\MdbList\MdbListClient;
use App\Services\MdbList\MdbListException;
use Illuminate\Support\Carbon;

class RefreshExternalRatings
{
    public function __construct(private readonly MdbListClient $client) {}

    /**
     * Fetch MDBList's ratings for a title and upsert the sources we display.
     * Swallows MDBList failures (not configured, connection, rate limited, non-2xx) so a
     * stale or unreachable provider never blocks the caller — on failure the cached
     * ratings (if any) are left untouched and `ratings_checked_at` is not advanced, so
     * the title is retried on its next stale check instead of waiting a full week.
     * Queued callers pass `$throwOnRateLimit` so a 429 reaches the job, which backs off.
     *
     * @throws MdbListException When rate limited and `$throwOnRateLimit` is true.
     */
    public function handle(Title $title, bool $throwOnRateLimit = false): void
    {
        [$provider, $mediaId] = $this->providerAndId($title);

        if ($provider === null || $mediaId === null) {
            return;
        }

        try {
            $response = $this->client->lookup($provider, $title->type->value, $mediaId);
        } catch (MdbListException $exception) {
            if ($throwOnRateLimit && $exception->isRateLimited()) {
                throw $exception;
            }

            return;
        }

        $fetchedAt = Carbon::now();

        foreach ($response['ratings'] ?? [] as $rating) {
            $source = RatingSource::tryFrom($rating['source'] ?? '');

            if ($source === null || ! is_numeric($rating['value'] ?? null)) {
                continue;
            }

            ExternalRating::updateOrCreate(
                ['title_id' => $title->id, 'source' => $source],
                [
                    'value' => (float) $rating['value'],
                    'max' => $source->maxValue(),
                    'votes' => $rating['votes'] ?? null,
                    'url' => $rating['url'] ?? null,
                    'fetched_at' => $fetchedAt,
                ],
            );
        }

        // Record the attempt even when MDBList had no ratings for this title, so a
        // not-found title isn't re-requested on every page view — it's rechecked
        // after 7 days like everything else.
        $title->forceFill(['ratings_checked_at' => $fetchedAt])->save();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function providerAndId(Title $title): array
    {
        if (filled($title->imdb_id)) {
            return ['imdb', $title->imdb_id];
        }

        if ($title->tmdb_id) {
            return ['tmdb', (string) $title->tmdb_id];
        }

        return [null, null];
    }
}
