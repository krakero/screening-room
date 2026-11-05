<?php

namespace Tests\Feature;

use App\Exceptions\ApiUnavailable;
use App\Services\Api\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class AuthTest extends TestCase
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

    public function test_normalizes_url_by_adding_https_and_removing_trailing_slash(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/token' => Http::response(['token' => 'test-token', 'user' => []]),
        ]);

        $auth = app(Auth::class);
        $result = $auth->signIn('example.com/', 'test@example.com', 'password');

        $this->assertTrue($result['success']);
        $this->assertEquals('https://example.com', SecureStorage::get('server_url'));
    }

    public function test_sign_in_validates_server_url(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'not_configured'], 200),
        ]);

        $auth = app(Auth::class);
        $result = $auth->signIn('https://example.com', 'test@example.com', 'password');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid server URL', $result['error']);
    }

    public function test_sign_in_throws_on_unreachable_server(): void
    {
        Http::fake(fn () => throw new ConnectionException);

        $auth = app(Auth::class);

        $this->expectException(ApiUnavailable::class);
        $this->expectExceptionMessage('Unable to connect to server');

        $auth->signIn('https://example.com', 'test@example.com', 'password');
    }

    public function test_sign_in_stores_token_on_success(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/token' => Http::response(['token' => 'test-token', 'user' => []]),
        ]);

        $auth = app(Auth::class);
        $result = $auth->signIn('https://example.com', 'test@example.com', 'password');

        $this->assertTrue($result['success']);
        $this->assertEquals('https://example.com', SecureStorage::get('server_url'));
        $this->assertEquals('test-token', SecureStorage::get('api_token'));
    }

    public function test_sign_in_returns_422_error_message(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/token' => Http::response([
                'errors' => ['email' => ['Invalid credentials']],
            ], 422),
        ]);

        $auth = app(Auth::class);
        $result = $auth->signIn('https://example.com', 'test@example.com', 'wrong-password');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['error']);
    }

    public function test_sign_out_clears_token(): void
    {
        Http::fake([
            'https://example.com/api/v1/auth/token' => Http::response([], 204),
        ]);

        SecureStorage::set('server_url', 'https://example.com');
        SecureStorage::set('api_token', 'test-token');

        $auth = app(Auth::class);
        $auth->signOut();

        $this->assertNull(SecureStorage::get('server_url'));
        $this->assertNull(SecureStorage::get('api_token'));
    }

    public function test_is_signed_in_returns_true_when_token_exists(): void
    {
        SecureStorage::set('api_token', 'test-token');

        $auth = app(Auth::class);
        $this->assertTrue($auth->isSignedIn());
    }

    public function test_is_signed_in_returns_false_when_no_token(): void
    {
        $auth = app(Auth::class);
        $this->assertFalse($auth->isSignedIn());
    }

    public function test_sign_in_with_plex_validates_server_url(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'not_configured'], 200),
        ]);

        $auth = app(Auth::class);
        $result = $auth->signInWithPlex('https://example.com', 'plex-token-123');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid server URL', $result['error']);
    }

    public function test_sign_in_with_plex_throws_on_unreachable_server(): void
    {
        Http::fake(fn () => throw new ConnectionException);

        $auth = app(Auth::class);

        $this->expectException(ApiUnavailable::class);
        $this->expectExceptionMessage('Unable to connect to server');

        $auth->signInWithPlex('https://example.com', 'plex-token-123');
    }

    public function test_sign_in_with_plex_stores_token_on_success(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/plex' => Http::response(['token' => 'test-token', 'user' => []]),
        ]);

        $auth = app(Auth::class);
        $result = $auth->signInWithPlex('https://example.com', 'plex-token-123');

        $this->assertTrue($result['success']);
        $this->assertEquals('https://example.com', SecureStorage::get('server_url'));
        $this->assertEquals('test-token', SecureStorage::get('api_token'));
    }

    public function test_sign_in_with_plex_returns_422_error_message(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/plex' => Http::response([
                'errors' => ['plex_token' => ['This Plex account isn\'t linked to Screening Room.']],
            ], 422),
        ]);

        $auth = app(Auth::class);
        $result = $auth->signInWithPlex('https://example.com', 'unlinked-plex-token');

        $this->assertFalse($result['success']);
        $this->assertEquals('This Plex account isn\'t linked to Screening Room.', $result['error']);
    }

    public function test_sign_in_with_plex_normalizes_url(): void
    {
        Http::fake([
            'https://example.com/api/v1' => Http::response(['status' => 'ok', 'version' => 'v1']),
            'https://example.com/api/v1/auth/plex' => Http::response(['token' => 'test-token', 'user' => []]),
        ]);

        $auth = app(Auth::class);
        $result = $auth->signInWithPlex('example.com/', 'plex-token-123');

        $this->assertTrue($result['success']);
        $this->assertEquals('https://example.com', SecureStorage::get('server_url'));
    }
}
