<?php

namespace Tests\Feature\Updates;

use App\Enums\UpdateChannel;
use App\Services\Updates\AvailableUpdate;
use App\Services\Updates\UpdateChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateCheckerTest extends TestCase
{
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
}
