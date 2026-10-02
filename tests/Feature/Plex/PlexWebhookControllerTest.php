<?php

use App\Enums\TitleType;
use App\Jobs\ProcessPlexWebhook;
use App\Models\Play;
use App\Models\Title;
use App\Models\WebhookEvent;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    app(IntegrationSettings::class)->setMany([
        'plex.webhook_secret' => 'super-secret',
        'plex.account_id' => '1',
    ]);
});

function plexWebhookPayload(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/plex/{$name}.json"));
}

test('it returns 404 when the secret does not match', function () {
    $response = $this->post('/webhooks/plex/wrong-secret', [
        'payload' => plexWebhookPayload('webhook_movie_scrobble'),
    ]);

    $response->assertNotFound();
});

test('it returns 404 when no secret is configured', function () {
    app(IntegrationSettings::class)->forget('plex.webhook_secret');

    $response = $this->post('/webhooks/plex/anything', [
        'payload' => plexWebhookPayload('webhook_movie_scrobble'),
    ]);

    $response->assertNotFound();
});

test('it logs the webhook event and dispatches processing', function () {
    Queue::fake();

    $response = $this->post('/webhooks/plex/super-secret', [
        'payload' => plexWebhookPayload('webhook_movie_scrobble'),
    ]);

    $response->assertNoContent();

    expect(WebhookEvent::where('source', 'plex')->where('event', 'media.scrobble')->exists())->toBeTrue();

    Queue::assertPushed(ProcessPlexWebhook::class);
});

test('a full request processes the scrobble end to end', function () {
    $title = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $this->post('/webhooks/plex/super-secret', [
        'payload' => plexWebhookPayload('webhook_movie_scrobble'),
    ])->assertNoContent();

    expect(Play::where('playable_type', 'title')->where('playable_id', $title->id)->exists())->toBeTrue();
});
