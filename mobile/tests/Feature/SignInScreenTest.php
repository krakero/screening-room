<?php

namespace Tests\Feature;

use App\NativeComponents\SignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class SignInScreenTest extends TestCase
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
    }

    public function test_successful_sign_in_navigates_to_up_next(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/token' => Http::response(['token' => 'test-token', 'user' => []]),
        ]);

        $testable = Native::test(SignIn::class)
            ->set('serverUrl', 'https://example.com')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->call('signIn');

        $testable->assertSet('loading', false);
        $testable->assertReplacedWith('/up-next');
        $this->assertEquals('test-token', SecureStorage::get('api_token'));
    }

    public function test_invalid_credentials_shows_error(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/token' => Http::response([
                'errors' => ['email' => ['Invalid credentials']],
            ], 422),
        ]);

        $testable = Native::test(SignIn::class)
            ->set('serverUrl', 'https://example.com')
            ->set('email', 'test@example.com')
            ->set('password', 'wrong-password')
            ->call('signIn');

        $testable->assertSet('loading', false);
        $testable->assertSet('error', 'Invalid credentials');
    }

    public function test_unreachable_server_shows_friendly_error(): void
    {
        Http::fake(fn () => throw new ConnectionException);

        $testable = Native::test(SignIn::class)
            ->set('serverUrl', 'https://example.com')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->call('signIn');

        $testable->assertSet('loading', false);
        $testable->assertSet('error', 'Unable to connect to server. Please check the URL and your connection.');
    }

    public function test_sign_in_with_plex_requires_server_url(): void
    {
        $testable = Native::test(SignIn::class)
            ->set('serverUrl', '')
            ->call('signInWithPlex');

        $testable->assertSet('error', 'Please enter a server URL');
        $testable->assertSet('waitingForPlex', false);
    }

    public function test_sign_in_with_plex_checks_the_server_before_opening_plex(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/api/v1' => Http::response('Not Found', 404),
        ]);

        $testable = Native::test(SignIn::class)
            ->set('serverUrl', 'https://example.com')
            ->call('signInWithPlex');

        $testable->assertSet('waitingForPlex', false);
        $testable->assertSet('error', 'Invalid server URL or server not configured');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'plex.tv'));
    }

    public function test_sign_in_with_plex_handles_unreachable_server(): void
    {
        Http::fake(fn () => throw new ConnectionException);

        $testable = Native::test(SignIn::class)
            ->set('serverUrl', 'https://example.com')
            ->call('signInWithPlex');

        $testable->assertSet('waitingForPlex', false);
        $testable->assertSet('error', 'Unable to connect to server. Please check the URL and your connection.');
    }

    public function test_sign_in_with_plex_handles_plex_connection_error(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'plex.tv')) {
                throw new ConnectionException;
            }

            return Http::response(['status' => 'ok', 'version' => 'v1']);
        });

        $bridge = Native::fakeBridge();
        $bridge->respondTo('Browser.OpenInApp', ['success' => true]);

        $testable = Native::test(SignIn::class)
            ->set('serverUrl', 'https://example.com')
            ->call('signInWithPlex');

        $testable->assertSet('waitingForPlex', false);
        $testable->assertSet('error', 'Couldn\'t reach Plex. Please try again.');
    }

    public function test_sign_in_with_plex_starts_waiting_state(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://plex.tv/api/v2/pins' => Http::response(['id' => 12345, 'code' => 'ABCD']),
        ]);

        $bridge = Native::fakeBridge();
        $bridge->respondTo('Browser.OpenInApp', ['success' => true]);

        $testable = Native::test(SignIn::class)
            ->set('serverUrl', 'https://example.com')
            ->call('signInWithPlex');

        $testable->assertSet('waitingForPlex', true);
        $testable->assertSet('error', '');
        $bridge->assertCalled('Browser.OpenInApp');
    }

    public function test_poll_with_null_token_keeps_waiting(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins/12345' => Http::response(['id' => 12345, 'authToken' => null]),
        ]);

        $component = Native::test(SignIn::class)
            ->set('waitingForPlex', true);
        $component->set('plexPinId', 12345)->set('plexStartedAt', time());

        $component->call('pollPlexAuth');

        $component->assertSet('waitingForPlex', true);
        $component->assertSet('error', '');
    }

    public function test_poll_with_token_signs_in_and_navigates(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins/12345' => Http::response(['id' => 12345, 'authToken' => 'plex-token-123']),
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/plex' => Http::response(['token' => 'test-token', 'user' => []]),
        ]);

        $component = Native::test(SignIn::class)
            ->set('serverUrl', 'https://example.com')
            ->set('waitingForPlex', true);
        $component->set('plexPinId', 12345)->set('plexStartedAt', time());

        $component->call('pollPlexAuth');

        $component->assertSet('waitingForPlex', false);
        $component->assertReplacedWith('/up-next');
        $this->assertEquals('test-token', SecureStorage::get('api_token'));
    }

    public function test_cancel_stops_waiting(): void
    {
        $testable = Native::test(SignIn::class)
            ->set('waitingForPlex', true)
            ->call('cancelPlexSignIn');

        $testable->assertSet('waitingForPlex', false);
        $testable->assertSet('error', '');
    }

    public function test_poll_times_out_after_5_minutes(): void
    {
        $component = Native::test(SignIn::class)
            ->set('waitingForPlex', true);
        $component->set('plexPinId', 12345)->set('plexStartedAt', time() - 301);

        $component->call('pollPlexAuth');

        $component->assertSet('waitingForPlex', false);
        $component->assertSet('error', 'Plex sign-in timed out. Please try again.');
    }

    public function test_toggle_email_fallback(): void
    {
        $testable = Native::test(SignIn::class)
            ->set('showEmailFallback', false)
            ->call('toggleEmailFallback');

        $testable->assertSet('showEmailFallback', true);

        $testable->call('toggleEmailFallback');
        $testable->assertSet('showEmailFallback', false);
    }
}
