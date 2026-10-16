<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Session-based authentication for the SPA (Sanctum stateful API).
 * The browser first calls GET /sanctum/csrf-cookie, then these endpoints.
 */
class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        // Self-registration always creates a bidder; admins are seeded.
        $user = User::create($request->validated());

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): UserResource
    {
        if (! Auth::guard('web')->attempt($request->validated())) {
            // Same message for unknown email and wrong password: no account enumeration.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // New session id after login prevents session fixation.
        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Who is signed in, if anyone. Public, so the SPA can check its session on
     * load without a 401 for visitors who are simply not logged in.
     */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        return response()->json(['data' => $user ? new UserResource($user) : null]);
    }
}
