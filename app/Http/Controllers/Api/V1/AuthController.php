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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

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

        $token = $user->createToken('jobflow-web')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
            'authenticated' => true,
        ], 201);
    }

    /**
     * Authenticate an existing user and create a personal access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = [
            'email' => $request->string('email')->lower()->trim()->value(),
            'password' => $request->string('password')->value(),
        ];

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        $token = $user->createToken('jobflow-web')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
            'authenticated' => true,
        ]);
    }

    /**
     * Revoke the current personal access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
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
