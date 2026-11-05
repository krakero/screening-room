<?php

namespace Tests\Feature;

use App\NativeComponents\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class SettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private array $storedData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storedData = [];
        $bridge = Native::fakeBridge();

        $bridge->respondTo('SecureStorage.Get', function ($params) {
            $key = $params['key'];

            if (isset($this->storedData[$key])) {
                return ['status' => 'Found', 'value' => $this->storedData[$key]];
            }

            return ['status' => 'NotFound', 'value' => null];
        });

        $bridge->respondTo('SecureStorage.Set', function ($params) {
            $this->storedData[$params['key']] = $params['value'];

            return ['success' => true];
        });

        $bridge->respondTo('SecureStorage.Delete', function ($params) {
            unset($this->storedData[$params['key']]);

            return ['success' => true];
        });
    }

    public function test_displays_server_url(): void
    {
        Http::fake([
            'https://example.com/api/v1/me' => Http::response(['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'timezone' => 'UTC']),
            'https://example.com/api/v1/stats' => Http::response(['headline' => ['episodesWatched' => 100, 'moviesWatched' => 50, 'showsFollowed' => 25, 'totalWatchMinutes' => 6000]]),
            'https://example.com/api/v1/settings/integrations' => Http::response(['plex' => ['configured' => true]]),
        ]);

        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Settings::class);

        $testable->assertSet('serverUrl', 'https://example.com');
        $testable->assertSet('loading', false);
    }

    public function test_fetches_user_data_on_mount(): void
    {
        Http::fake([
            'https://example.com/api/v1/me' => Http::response(['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'timezone' => 'America/New_York']),
            'https://example.com/api/v1/stats' => Http::response(['headline' => ['episodesWatched' => 200, 'moviesWatched' => 75, 'showsFollowed' => 30, 'totalWatchMinutes' => 12000]]),
            'https://example.com/api/v1/settings/integrations' => Http::response(['plex' => ['configured' => true], 'seerr' => ['configured' => false]]),
        ]);

        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Settings::class);

        $testable->assertSet('user', ['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'timezone' => 'America/New_York']);
        $testable->assertSet('stats', ['episodesWatched' => 200, 'moviesWatched' => 75, 'showsFollowed' => 30, 'totalWatchMinutes' => 12000]);
        $testable->assertSet('integrations', ['plex' => ['configured' => true], 'seerr' => ['configured' => false]]);
        $testable->assertSet('loading', false);
        $testable->assertSet('error', '');
    }

    public function test_handles_api_error(): void
    {
        Http::fake([
            'https://example.com/api/v1/me' => Http::response([], 500),
            'https://example.com/api/v1/stats' => Http::response([], 500),
            'https://example.com/api/v1/settings/integrations' => Http::response([], 500),
        ]);

        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Settings::class);

        $testable->assertSet('loading', false);
        $testable->assertSet('error', 'The server had a problem. Please try again.');
    }

    public function test_handles_unauthenticated_error(): void
    {
        Http::fake([
            'https://example.com/api/v1/me' => Http::response([], 401),
        ]);

        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Settings::class);

        $testable->assertReplacedWith('/sign-in');
    }

    public function test_retry_fetches_data_again(): void
    {
        Http::fake([
            'https://example.com/api/v1/me' => Http::sequence()
                ->push([], 500)
                ->push(['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'timezone' => 'UTC']),
            'https://example.com/api/v1/stats' => Http::response(['headline' => ['episodesWatched' => 100, 'moviesWatched' => 50, 'showsFollowed' => 25, 'totalWatchMinutes' => 6000]]),
            'https://example.com/api/v1/settings/integrations' => Http::response(['plex' => ['configured' => true]]),
        ]);

        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Settings::class);
        $testable->assertSet('error', 'The server had a problem. Please try again.');

        $testable->call('fetchData');

        $testable->assertSet('user', ['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'timezone' => 'UTC']);
        $testable->assertSet('error', '');
    }

    public function test_confirm_sign_out_shows_confirmation(): void
    {
        Http::fake([
            'https://example.com/api/v1/me' => Http::response(['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'timezone' => 'UTC']),
            'https://example.com/api/v1/stats' => Http::response(['headline' => ['episodesWatched' => 100, 'moviesWatched' => 50, 'showsFollowed' => 25, 'totalWatchMinutes' => 6000]]),
            'https://example.com/api/v1/settings/integrations' => Http::response(['plex' => ['configured' => true]]),
        ]);

        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Settings::class);
        $testable->assertSet('showSignOutConfirm', false);

        $testable->call('confirmSignOut');

        $testable->assertSet('showSignOutConfirm', true);
    }

    public function test_cancel_sign_out_hides_confirmation(): void
    {
        Http::fake([
            'https://example.com/api/v1/me' => Http::response(['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'timezone' => 'UTC']),
            'https://example.com/api/v1/stats' => Http::response(['headline' => ['episodesWatched' => 100, 'moviesWatched' => 50, 'showsFollowed' => 25, 'totalWatchMinutes' => 6000]]),
            'https://example.com/api/v1/settings/integrations' => Http::response(['plex' => ['configured' => true]]),
        ]);

        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Settings::class)
            ->call('confirmSignOut');
        $testable->assertSet('showSignOutConfirm', true);

        $testable->call('cancelSignOut');

        $testable->assertSet('showSignOutConfirm', false);
    }

    public function test_sign_out_clears_token_and_navigates(): void
    {
        Http::fake([
            'https://example.com/api/v1/me' => Http::response(['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'timezone' => 'UTC']),
            'https://example.com/api/v1/stats' => Http::response(['headline' => ['episodesWatched' => 100, 'moviesWatched' => 50, 'showsFollowed' => 25, 'totalWatchMinutes' => 6000]]),
            'https://example.com/api/v1/settings/integrations' => Http::response(['plex' => ['configured' => true]]),
            'https://example.com/api/v1/auth/token' => Http::response([], 204),
        ]);

        $this->storedData['server_url'] = 'https://example.com';
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Settings::class)
            ->call('signOut');

        $testable->assertReplacedWith('/sign-in');
        $this->assertNull(SecureStorage::get('api_token'));
        $this->assertNull(SecureStorage::get('server_url'));
    }
}
