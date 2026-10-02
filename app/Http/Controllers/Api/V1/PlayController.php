<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Plays\LogPlay;
use App\Actions\Plays\MarkSeasonWatched;
use App\Actions\Plays\MarkShowWatched;
use App\Actions\Plays\RemovePlay;
use App\Actions\Tmdb\EnsureSeasonEpisodes;
use App\Enums\PlaySource;
use App\Enums\WatchedAt;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PlayHistoryResource;
use App\Http\Resources\V1\PlayResource;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Services\ShowProgress;
use App\Support\DisplayTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PlayController extends Controller
{
    public function watchEpisode(Request $request, Episode $episode): JsonResponse
    {
        [$when, $customDatetime] = $this->resolveWhen($request);

        $play = app(LogPlay::class)->handle($episode, $when, $customDatetime);

        return response()->json(['play' => new PlayResource($play)], 201);
    }

    public function unwatchEpisode(Episode $episode): Response
    {
        $play = $episode->plays()->where('source', PlaySource::Manual)->latest('watched_at')->first()
            ?? $episode->plays()->latest('watched_at')->first();

        if (! $play) {
            return response()->json(['message' => __('No play to remove.')], 404);
        }

        app(RemovePlay::class)->handle($play);

        return response()->noContent();
    }

    public function destroy(Play $play): Response
    {
        app(RemovePlay::class)->handle($play);

        return response()->noContent();
    }

    public function watchMovie(Request $request, Title $title): JsonResponse
    {
        if ($title->isShow()) {
            throw ValidationException::withMessages([
                'title' => __('Use the show or season watch endpoints for a show.'),
            ]);
        }

        [$when, $customDatetime] = $this->resolveWhen($request);

        $play = app(LogPlay::class)->handle($title, $when, $customDatetime);

        return response()->json(['play' => new PlayResource($play)], 201);
    }

    public function watchShow(Request $request, Title $title): JsonResponse
    {
        $this->ensureIsShow($title);

        [$when, $customDatetime] = $this->resolveWhen($request);

        $markedCount = app(MarkShowWatched::class)->handle($title, $when, $customDatetime);

        return response()->json(['marked_count' => $markedCount]);
    }

    public function previewWatchShow(Title $title, ShowProgress $showProgress): JsonResponse
    {
        $this->ensureIsShow($title);

        $progress = $showProgress->for($title);

        return response()->json(['aired_unwatched_count' => $progress->airedCount - $progress->watchedCount]);
    }

    public function watchSeason(Request $request, Season $season): JsonResponse
    {
        [$when, $customDatetime] = $this->resolveWhen($request);

        $markedCount = app(MarkSeasonWatched::class)->handle($season, $when, $customDatetime);

        return response()->json(['marked_count' => $markedCount]);
    }

    public function previewWatchSeason(Season $season): JsonResponse
    {
        return response()->json(['aired_unwatched_count' => $this->airedUnwatchedCount($season)]);
    }

    public function history(): AnonymousResourceCollection
    {
        $plays = Play::query()
            ->with(['playable' => fn ($morphTo) => $morphTo->morphWith([
                Episode::class => ['title'],
            ])])
            ->orderByDesc('watched_at')
            ->paginate(20);

        return PlayHistoryResource::collection($plays);
    }

    /**
     * @return array{0: WatchedAt, 1: ?CarbonImmutable}
     */
    private function resolveWhen(Request $request): array
    {
        $validated = $request->validate([
            'when' => ['sometimes', Rule::enum(WatchedAt::class)],
            'datetime' => ['required_if:when,custom', 'nullable', 'date'],
        ]);

        $when = WatchedAt::from($validated['when'] ?? 'now');

        $customDatetime = $when === WatchedAt::Custom
            ? CarbonImmutable::parse($validated['datetime'])
            : null;

        return [$when, $customDatetime];
    }

    private function ensureIsShow(Title $title): void
    {
        if (! $title->isShow()) {
            throw ValidationException::withMessages([
                'title' => __('This title is a movie; use the movie watch endpoint instead.'),
            ]);
        }
    }

    /**
     * Aired episodes in the season that haven't been marked watched yet, ensuring the season's
     * episodes are imported from TMDB first (same as the season page's `loadEpisodes()`).
     */
    private function airedUnwatchedCount(Season $season): int
    {
        app(EnsureSeasonEpisodes::class)->handle($season);

        return $season->episodes()
            ->whereNotNull('air_date')
            ->where('air_date', '<=', DisplayTimezone::today())
            ->whereDoesntHave('plays')
            ->count();
    }
}
