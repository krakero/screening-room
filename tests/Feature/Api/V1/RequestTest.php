<?php

use App\Actions\Requests\RequestTitle;
use App\Enums\LibraryState;
use App\Enums\SeerrRequestStatus;
use App\Jobs\SubmitTitleRequest;
use App\Models\LibraryStatus;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function configureSeerrApi(): void
{
    app(IntegrationSettings::class)->setMany([
        'seerr.url' => 'https://seerr.test',
        'seerr.api_key' => 'secret-key',
    ]);
}

test('requesting a title requires authentication', function () {
    $title = Title::factory()->movie()->create();

    $response = $this->postJson("/api/v1/titles/{$title->id}/request");

    $response->assertUnauthorized();
});

test('requesting a title returns immediately with a pending status and queues the seerr call', function () {
    Queue::fake();
    configureSeerrApi();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/request");

    $response->assertAccepted()->assertJson(['status' => ['state' => 'pending']]);

    expect($title->fresh()->libraryStatus->state)->toBe(LibraryState::Pending);

    Queue::assertPushed(SubmitTitleRequest::class, function ($job) use ($title) {
        return $job->title->is($title) && $job->seasonNumbers === [];
    });
});

test('requesting a show queues the chosen seasons', function () {
    Queue::fake();
    configureSeerrApi();

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/request", [
        'seasons' => [1, 2],
    ])->assertAccepted();

    Queue::assertPushed(SubmitTitleRequest::class, fn ($job) => $job->seasonNumbers === [1, 2]);
});

test('the queued job sends the seerr request and marks the title requested', function () {
    configureSeerrApi();
    Http::fake([
        'seerr.test/api/v1/movie/603' => Http::response(['id' => 603, 'mediaInfo' => null]),
        'seerr.test/api/v1/request' => Http::response(['id' => 42, 'status' => 1]),
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    (new SubmitTitleRequest($title))->handle(app(RequestTitle::class));

    expect($title->fresh()->libraryStatus->state)->toBe(LibraryState::Requested);
});

test('the queued job marks the title failed when seerr is unreachable', function () {
    configureSeerrApi();
    Http::fake([
        'seerr.test/api/v1/movie/603' => Http::response(['id' => 603, 'mediaInfo' => null]),
        'seerr.test/api/v1/request' => Http::response(['message' => 'boom'], 500),
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Pending]);

    expect(fn () => (new SubmitTitleRequest($title))->handle(app(RequestTitle::class)))
        ->toThrow(RuntimeException::class);

    expect($title->fresh()->libraryStatus->state)->toBe(LibraryState::Failed);
});

test('the queued job reflects an already-requested seerr status instead of duplicating the request', function () {
    configureSeerrApi();
    Http::fake([
        'seerr.test/api/v1/movie/603' => Http::response([
            'id' => 603,
            'mediaInfo' => ['status' => 2, 'requests' => [['id' => 77, 'status' => 2]]],
        ]),
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    (new SubmitTitleRequest($title))->handle(app(RequestTitle::class));

    $status = $title->fresh()->libraryStatus;

    expect($status->state)->toBe(LibraryState::Requested)
        ->and($status->seerr_request_id)->toBe(77)
        ->and($status->seerr_status)->toBe(SeerrRequestStatus::Approved);

    Http::assertNotSent(fn ($request) => $request->url() === 'https://seerr.test/api/v1/request');
});

test('requesting a title returns a validation error immediately when seerr is not configured', function () {
    Queue::fake();

    $title = Title::factory()->movie()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/request");

    $response->assertUnprocessable()->assertJson([
        'message' => 'Could not send the request. Check the Seerr connection in Settings.',
    ]);

    Queue::assertNotPushed(SubmitTitleRequest::class);
});

test('request status requires authentication', function () {
    $title = Title::factory()->movie()->create();

    $response = $this->getJson("/api/v1/titles/{$title->id}/request-status");

    $response->assertUnauthorized();
});

test('request status returns null state when never requested', function () {
    $title = Title::factory()->movie()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/request-status");

    $response->assertOk()->assertJson(['state' => null]);
});

test('request status reflects the library status state', function () {
    configureSeerrApi();
    Http::fake([
        'seerr.test/api/v1/movie/603' => Http::response(['id' => 603, 'mediaInfo' => null]),
        'seerr.test/api/v1/request' => Http::response(['id' => 42, 'status' => 1]),
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/request")->assertAccepted();

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/request-status");

    $response->assertOk()->assertJson(['state' => 'requested']);
    expect($response->json())->toHaveKeys(['state', 'seerr_status']);
});
