<?php

namespace Tests\Feature;

use App\NativeComponents\Launch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

class LaunchScreenTest extends TestCase
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

    public function test_navigates_to_up_next_when_signed_in(): void
    {
        $this->storedData['api_token'] = 'test-token';

        $testable = Native::test(Launch::class);

        $testable->assertReplacedWith('/up-next');
    }

    public function test_navigates_to_sign_in_when_not_signed_in(): void
    {
        $testable = Native::test(Launch::class);

        $testable->assertReplacedWith('/sign-in');
    }
}
