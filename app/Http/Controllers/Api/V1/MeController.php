<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Settings\UpdateAccentColor;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Services\Stats\StatsCacheVersion;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request, UpdateAccentColor $updateAccentColor, StatsCacheVersion $statsCacheVersion): UserResource
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'accent' => ['sometimes', 'string'],
        ]);

        if (array_key_exists('accent', $validated)) {
            $updateAccentColor->handle($user, $validated['accent']);
            unset($validated['accent']);
        }

        $user->update($validated);

        if (array_key_exists('timezone', $validated)) {
            $statsCacheVersion->bump();
        }

        return new UserResource($user->fresh());
    }
}
