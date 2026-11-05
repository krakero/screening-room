<?php

namespace Tests\Feature;

use App\Exceptions\ApiUnavailable;
use App\Services\Plex\PlexPinAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class PlexPinAuthTest extends TestCase
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

    public function test_start_creates_pin_and_returns_id_and_url(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins' => Http::response([
                'id' => 12345,
                'code' => 'ABCD',
            ]),
        ]);

        $plexAuth = app(PlexPinAuth::class);
        $result = $plexAuth->start();

        $this->assertIsArray($result);
        $this->assertEquals(12345, $result['id']);
        $this->assertStringContainsString('https://app.plex.tv/auth', $result['url']);
        $this->assertStringContainsString('code=ABCD', $result['url']);
        $this->assertStringContainsString('Screening+Room', $result['url']);
    }

    public function test_start_includes_required_headers(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins' => Http::response([
                'id' => 12345,
                'code' => 'ABCD',
            ]),
        ]);

        $plexAuth = app(PlexPinAuth::class);
        $plexAuth->start();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Plex-Client-Identifier') &&
                   $request->hasHeader('X-Plex-Product', 'Screening Room') &&
                   $request->hasHeader('X-Plex-Version', '1.0') &&
                   $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_start_persists_and_reuses_client_identifier(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins' => Http::response([
                'id' => 12345,
                'code' => 'ABCD',
            ]),
        ]);

        $plexAuth = app(PlexPinAuth::class);
        $plexAuth->start();

        $firstClientId = SecureStorage::get('plex_client_id');
        $this->assertNotEmpty($firstClientId);

        // Second call should reuse the same client ID
        $plexAuth->start();
        $secondClientId = SecureStorage::get('plex_client_id');

        $this->assertEquals($firstClientId, $secondClientId);
    }

    public function test_start_throws_on_connection_error(): void
    {
        Http::fake(fn () => throw new ConnectionException);

        $plexAuth = app(PlexPinAuth::class);

        $this->expectException(ApiUnavailable::class);
        $this->expectExceptionMessage('Couldn\'t reach Plex');

        $plexAuth->start();
    }

    public function test_start_throws_on_server_error(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins' => Http::response([], 500),
        ]);

        $plexAuth = app(PlexPinAuth::class);

        $this->expectException(ApiUnavailable::class);
        $this->expectExceptionMessage('Couldn\'t reach Plex');

        $plexAuth->start();
    }

    public function test_check_returns_null_when_pending(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins/12345' => Http::response([
                'id' => 12345,
                'code' => 'ABCD',
                'authToken' => null,
            ]),
        ]);

        $plexAuth = app(PlexPinAuth::class);
        $result = $plexAuth->check(12345);

        $this->assertNull($result);
    }

    public function test_check_returns_token_when_complete(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins/12345' => Http::response([
                'id' => 12345,
                'code' => 'ABCD',
                'authToken' => 'test-plex-token',
            ]),
        ]);

        $plexAuth = app(PlexPinAuth::class);
        $result = $plexAuth->check(12345);

        $this->assertEquals('test-plex-token', $result);
    }

    public function test_check_throws_on_connection_error(): void
    {
        Http::fake(fn () => throw new ConnectionException);

        $plexAuth = app(PlexPinAuth::class);

        $this->expectException(ApiUnavailable::class);
        $this->expectExceptionMessage('Couldn\'t reach Plex');

        $plexAuth->check(12345);
    }

    public function test_check_uses_the_client_id_the_pin_was_created_with(): void
    {
        Http::fake([
            'https://plex.tv/api/v2/pins' => Http::response(['id' => 12345, 'code' => 'ABCD']),
            'https://plex.tv/api/v2/pins/12345' => Http::response(['id' => 12345, 'authToken' => null]),
        ]);

        $pin = app(PlexPinAuth::class)->start();
        $this->assertStringContainsString('clientID='.$pin['client_id'], $pin['url']);

        // Simulate the device's secure storage not keeping the identifier.
        $this->storedData = [];

        app(PlexPinAuth::class)->check($pin['id'], $pin['client_id']);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v2/pins/12345')
            && $request->header('X-Plex-Client-Identifier')[0] === $pin['client_id']);
    }
}
