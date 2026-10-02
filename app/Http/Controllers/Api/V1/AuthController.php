<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Plex\PlexDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $token = $user->createToken($validated['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Exchange a completed "Sign in with Plex" auth token (the client ran its own PIN flow) for a
     * Sanctum token — same linked-account rule as the login screen: only an EXISTING user whose
     * `plex_account_id` matches the Plex account's stable uuid, matched on the uuid only, never
     * email. Never creates or links an account.
     */
    public function plex(Request $request, PlexDiscovery $discovery): JsonResponse
    {
        $validated = $request->validate([
            'plex_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $account = $discovery->account($validated['plex_token']);
        } catch (Throwable) {
            $account = null;
        }

        $uuid = $account['uuid'] ?? null;
        $user = filled($uuid) ? User::query()->where('plex_account_id', $uuid)->first() : null;

        if ($user === null) {
            throw ValidationException::withMessages([
                'plex_token' => __('This Plex account isn\'t linked to Screening Room.'),
            ]);
        }

        $token = $user->createToken($validated['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
