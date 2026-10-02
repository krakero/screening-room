<?php

use App\Http\Middleware\EnsureCollectionEnabled;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('middleware allows access when collection is enabled', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $request = Request::create('/collection');
    $middleware = new EnsureCollectionEnabled;

    $response = $middleware->handle($request, fn () => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('middleware returns 404 when collection is disabled', function () {
    $user = User::factory()->create(['collection_enabled' => false]);
    $this->actingAs($user);

    $request = Request::create('/collection');
    $middleware = new EnsureCollectionEnabled;

    expect(fn () => $middleware->handle($request, fn () => response('OK')))
        ->toThrow(NotFoundHttpException::class);
});

test('middleware returns 404 when user is not authenticated', function () {
    $request = Request::create('/collection');
    $middleware = new EnsureCollectionEnabled;

    expect(fn () => $middleware->handle($request, fn () => response('OK')))
        ->toThrow(NotFoundHttpException::class);
});
