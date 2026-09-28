<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name')->trim()->value(),
            'email' => $request->string('email')->lower()->trim()->value(),
            'password' => Hash::make($request->string('password')->value()),
        ]);

        event(new Registered($user));

        Auth::guard('web')->login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return ApiResponse::success([
            'user' => new UserResource($user),
            'authenticated' => true,
        ], 201);
    }

    /**
     * Authenticate an existing user and create a session.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = [
            'email' => $request->string('email')->lower()->trim()->value(),
            'password' => $request->string('password')->value(),
        ];

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return ApiResponse::success([
            'user' => new UserResource($user),
            'authenticated' => true,
        ]);
    }

    /**
     * Log the current user out and invalidate the session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if (Auth::guard('sanctum')->check()) {
            Auth::guard('sanctum')->forgetUser();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success([
            'message' => 'Successfully logged out.',
            'authenticated' => false,
        ]);
    }

    /**
     * Get the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success([
            'user' => new UserResource($user),
        ]);
    }
}
