<?php

namespace Tests\Feature;

use App\Exceptions\ApiUnavailable;
use App\Exceptions\Unauthenticated;
use App\Services\Api\ScreeningRoomApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class ScreeningRoomApiTest extends TestCase
{
    use RefreshDatabase;

    private array $storedData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storedData = [
            'server_url' => 'https://example.com',
            'api_token' => 'test-token',
        ];

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

    public function test_get_request_includes_bearer_token(): void
    {
        Http::fake([
            'https://example.com/api/v1/test' => Http::response(['data' => 'test']),
        ]);

        $api = app(ScreeningRoomApi::class);
        $response = $api->get('test');

        $this->assertEquals(['data' => 'test'], $response->json());
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_post_request_sends_data(): void
    {
        Http::fake([
            'https://example.com/api/v1/test' => Http::response(['success' => true]),
        ]);

        $api = app(ScreeningRoomApi::class);
        $response = $api->post('test', ['key' => 'value']);

        $this->assertEquals(['success' => true], $response->json());
        Http::assertSent(function ($request) {
            return $request->data() === ['key' => 'value'];
        });
    }

    public function test_401_response_clears_token_and_throws_unauthenticated(): void
    {
        Http::fake([
            'https://example.com/api/v1/test' => Http::response([], 401),
        ]);

        $api = app(ScreeningRoomApi::class);

        $this->expectException(Unauthenticated::class);
        $this->expectExceptionMessage('Session expired');

        try {
            $api->get('test');
        } finally {
            $this->assertNull(SecureStorage::get('api_token'));
        }
    }

    public function test_server_error_throws_api_unavailable(): void
    {
        Http::fake([
            'https://example.com/api/v1/test' => Http::response(['message' => 'Server Error'], 500),
        ]);

        $this->expectException(ApiUnavailable::class);

        app(ScreeningRoomApi::class)->get('test');
    }

    public function test_validation_error_is_returned_to_the_caller(): void
    {
        Http::fake([
            'https://example.com/api/v1/test' => Http::response(['errors' => ['email' => ['Bad']]], 422),
        ]);

        $response = app(ScreeningRoomApi::class)->post('test', ['email' => 'x']);

        $this->assertSame(422, $response->status());
    }

    public function test_connection_error_throws_api_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException);

        $api = app(ScreeningRoomApi::class);

        $this->expectException(ApiUnavailable::class);
        $this->expectExceptionMessage('Unable to connect to server');

        $api->get('test');
    }

    public function test_throws_when_server_url_not_configured(): void
    {
        $this->storedData = [];

        $api = app(ScreeningRoomApi::class);

        $this->expectException(ApiUnavailable::class);
        $this->expectExceptionMessage('Server URL not configured');

        $api->get('test');
    }
}
