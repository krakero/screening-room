<?php

namespace Tests\Feature\Updates;

use App\Enums\UpdateChannel;
use App\Services\Updates\AvailableUpdate;
use App\Services\Updates\UpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_check_stable_returns_update_when_newer_version_available(): void
    {
        Config::set('app.version', '1.1.0');
        Config::set('app.channel', 'stable');
        Config::set('app.commit', null);

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/releases/latest' => Http::response([
                'tag_name' => 'v1.2.0',
                'name' => 'Release 1.2.0',
                'html_url' => 'https://github.com/krakero/screening-room/releases/tag/v1.2.0',
                'published_at' => '2024-01-15T12:00:00Z',
            ]),
        ]);

        $checker = new UpdateChecker;
        $update = $checker->check();

        $this->assertInstanceOf(AvailableUpdate::class, $update);
        $this->assertEquals('1.2.0', $update->version);
        $this->assertEquals('Release 1.2.0', $update->summary);
        $this->assertEquals('https://github.com/krakero/screening-room/releases/tag/v1.2.0', $update->url);
        $this->assertEquals(UpdateChannel::Stable, $update->channel);
    }

    public function test_check_stable_returns_null_when_no_newer_version(): void
    {
        Config::set('app.version', '1.0.0');
        Config::set('app.channel', 'stable');
        Config::set('app.commit', null);

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/releases/latest' => Http::response([
                'tag_name' => 'v1.0.0',
                'name' => 'Release 1.0.0',
                'html_url' => 'https://github.com/krakero/screening-room/releases/tag/v1.0.0',
                'published_at' => '2024-01-15T12:00:00Z',
            ]),
        ]);

        $checker = new UpdateChecker;
        $update = $checker->check();

        $this->assertNull($update);
    }

    public function test_check_stable_returns_null_when_api_fails(): void
    {
        Config::set('app.version', '1.0.0');
        Config::set('app.channel', 'stable');
        Config::set('app.commit', null);

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/releases/latest' => Http::response([], 404),
        ]);

        $checker = new UpdateChecker;
        $update = $checker->check();

        $this->assertNull($update);
    }

    public function test_check_develop_returns_update_when_commit_differs(): void
    {
        Config::set('app.version', 'develop-xyz789');
        Config::set('app.channel', 'develop');
        Config::set('app.commit', 'xyz789abc123');

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/commits/main' => Http::response([
                'sha' => 'abc123def456',
                'commit' => [
                    'message' => "Add new feature\n\nLong description here",
                    'committer' => [
                        'date' => '2024-01-15T12:00:00Z',
                    ],
                ],
                'html_url' => 'https://github.com/krakero/screening-room/commit/abc123def456',
            ]),
        ]);

        $checker = new UpdateChecker;
        $update = $checker->check();

        $this->assertInstanceOf(AvailableUpdate::class, $update);
        $this->assertEquals('develop-abc123d', $update->version);
        $this->assertEquals('Add new feature', $update->summary);
        $this->assertEquals('https://github.com/krakero/screening-room/commit/abc123def456', $update->url);
        $this->assertEquals(UpdateChannel::Develop, $update->channel);
    }

    public function test_check_develop_returns_null_when_commit_matches(): void
    {
        Config::set('app.version', 'develop-abc123d');
        Config::set('app.channel', 'develop');
        Config::set('app.commit', 'abc123def456');

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/commits/main' => Http::response([
                'sha' => 'abc123def456',
                'commit' => [
                    'message' => 'Current commit',
                    'committer' => [
                        'date' => '2024-01-15T12:00:00Z',
                    ],
                ],
                'html_url' => 'https://github.com/krakero/screening-room/commit/abc123def456',
            ]),
        ]);

        $checker = new UpdateChecker;
        $update = $checker->check();

        $this->assertNull($update);
    }

    public function test_check_develop_returns_null_when_api_fails(): void
    {
        Config::set('app.version', 'develop-abc123d');
        Config::set('app.channel', 'develop');
        Config::set('app.commit', 'abc123def456');

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/commits/main' => Http::response([], 404),
        ]);

        $checker = new UpdateChecker;
        $update = $checker->check();

        $this->assertNull($update);
    }

    public function test_check_caches_result_for_six_hours(): void
    {
        Config::set('app.version', '1.1.0');
        Config::set('app.channel', 'stable');
        Config::set('app.commit', null);

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/releases/latest' => Http::response([
                'tag_name' => 'v1.2.0',
                'name' => 'Release 1.2.0',
                'html_url' => 'https://github.com/krakero/screening-room/releases/tag/v1.2.0',
                'published_at' => '2024-01-15T12:00:00Z',
            ]),
        ]);

        $checker = new UpdateChecker;

        // First call
        $update1 = $checker->check();
        $this->assertInstanceOf(AvailableUpdate::class, $update1);

        // Second call should use cache (no HTTP request)
        Http::assertSentCount(1);
        $update2 = $checker->check();
        $this->assertInstanceOf(AvailableUpdate::class, $update2);
        Http::assertSentCount(1);
    }

    public function test_check_force_bypasses_cache(): void
    {
        Config::set('app.version', '1.1.0');
        Config::set('app.channel', 'stable');
        Config::set('app.commit', null);

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/releases/latest' => Http::response([
                'tag_name' => 'v1.2.0',
                'name' => 'Release 1.2.0',
                'html_url' => 'https://github.com/krakero/screening-room/releases/tag/v1.2.0',
                'published_at' => '2024-01-15T12:00:00Z',
            ]),
        ]);

        $checker = new UpdateChecker;

        // First call
        $checker->check();
        Http::assertSentCount(1);

        // Forced call should bypass cache
        $checker->check(force: true);
        Http::assertSentCount(2);
    }

    /**
     * The array store used by tests skips serialization, so these use the database store,
     * which round-trips through unserialize() with `cache.serializable_classes` = false
     * like production does.
     */
    public function test_cached_update_survives_a_serializing_cache_store(): void
    {
        Config::set('cache.default', 'database');
        Config::set('app.version', 'develop-abc1234');
        Config::set('app.channel', 'develop');
        Config::set('app.commit', 'abc1234000000000000000000000000000000000');

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/commits/main' => Http::response([
                'sha' => 'def5678000000000000000000000000000000000',
                'html_url' => 'https://github.com/krakero/screening-room/commit/def5678',
                'commit' => [
                    'message' => "Fix things\n\nBody",
                    'committer' => ['date' => '2026-10-15T12:00:00Z'],
                ],
            ]),
        ]);

        $first = (new UpdateChecker)->check();
        $second = (new UpdateChecker)->check();

        $this->assertInstanceOf(AvailableUpdate::class, $second);
        $this->assertEquals($first, $second);
        $this->assertEquals('develop-def5678', $second->version);
        $this->assertEquals(UpdateChannel::Develop, $second->channel);
        Http::assertSentCount(1);
    }

    public function test_cached_no_update_is_not_refetched_on_a_serializing_cache_store(): void
    {
        Config::set('cache.default', 'database');
        Config::set('app.version', '1.0.0');
        Config::set('app.channel', 'stable');
        Config::set('app.commit', null);

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/releases/latest' => Http::response([
                'tag_name' => 'v1.0.0',
                'name' => 'Release 1.0.0',
                'html_url' => 'https://github.com/krakero/screening-room/releases/tag/v1.0.0',
                'published_at' => '2024-01-15T12:00:00Z',
            ]),
        ]);

        $this->assertNull((new UpdateChecker)->check());
        $this->assertNull((new UpdateChecker)->check());
        Http::assertSentCount(1);
    }

    public function test_unreadable_cached_value_is_refetched_instead_of_crashing(): void
    {
        Config::set('cache.default', 'database');
        Config::set('app.version', '1.1.0');
        Config::set('app.channel', 'stable');
        Config::set('app.commit', null);

        // What an older version left behind: an AvailableUpdate object, which now unserializes
        // as __PHP_Incomplete_Class.
        Cache::put('updates.available.stable', new AvailableUpdate(
            version: '1.2.0',
            summary: 'Old',
            url: 'https://example.test',
            published_at: '2024-01-01T00:00:00Z',
            channel: UpdateChannel::Stable,
        ), 3600);

        Http::fake([
            'https://api.github.com/repos/krakero/screening-room/releases/latest' => Http::response([
                'tag_name' => 'v1.2.0',
                'name' => 'Release 1.2.0',
                'html_url' => 'https://github.com/krakero/screening-room/releases/tag/v1.2.0',
                'published_at' => '2024-01-15T12:00:00Z',
            ]),
        ]);

        $update = (new UpdateChecker)->check();

        $this->assertInstanceOf(AvailableUpdate::class, $update);
        $this->assertEquals('Release 1.2.0', $update->summary);
        Http::assertSentCount(1);
    }
}
