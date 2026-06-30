<?php

namespace App\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends BaseController
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
        ]);

        $user  = User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('psx-web')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully',
            'data'    => ['user' => $user, 'token' => $token],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => 'Account is deactivated.'], 403);
        }

        if ($user->tokens()->count() >= 5) {
            $user->tokens()->oldest()->first()?->delete();
        }

        $token = $user->createToken('psx-web')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data'    => ['user' => $user, 'token' => $token],
        ]);
    }

    public function googleRedirect(): JsonResponse
    {
        $url = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
        return response()->json(['url' => $url]);
    }

    public function googleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect("{$frontendUrl}/auth/login?error=google_failed");
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            $user->update([
                'google_id' => $googleUser->getId(),
                'provider'  => 'google',
                'image'     => $googleUser->getAvatar() ?? $user->image,
            ]);
        } else {
            $user = User::create([
                'name'              => $googleUser->getName(),
                'email'             => $googleUser->getEmail(),
                'google_id'         => $googleUser->getId(),
                'provider'          => 'google',
                'image'             => $googleUser->getAvatar(),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);
        }

        if ($user->tokens()->count() >= 5) {
            $user->tokens()->oldest()->first()?->delete();
        }

        $token       = $user->createToken('psx-web')->plainTextToken;
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        return redirect("{$frontendUrl}/auth/callback?token={$token}");
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        return response()->json(['success' => true, 'message' => 'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['success' => true, 'message' => 'Password reset successfully.']);
        }

        return response()->json(['success' => false, 'message' => __($status)], 422);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['user' => $request->user()]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out from all devices']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $tokens = $request->user()
            ->tokens()
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'last_used_at', 'created_at']);

        return response()->json(['success' => true, 'data' => $tokens]);
    }

    public function revokeSession(Request $request, string $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'Session not found.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Session revoked.']);
    }
}
