<?php

namespace App\Http\Controllers\API;

use App\Models\User;
use App\Notifications\VerifyEmail;
use App\Services\EntitlementService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends BaseController
{
    // MCP connection tokens (mcp:*) live alongside these and are left alone, except when an account is
    // proven to have been unverified (Google linking or a password reset) and may belong to a squatter.
    private const WEB_TOKEN = 'psx-web';

    private const MAX_WEB_SESSIONS = 5;

    private const WEB_TOKEN_DAYS = 30;

    private const GOOGLE_CODE_TTL_SECONDS = 60;

    private const STATE_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/';

    // Checked when the user doesn't exist so a failed login takes as long as a wrong password.
    private const DUMMY_PASSWORD_HASH = '$2y$10$KuvpUGDpTWChtSaWrCBBpuPbUXNGlR4F8d1ZL40T9Ll53bRfN8s/m';

    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => self::normalizeEmail($request->input('email'))]);

        $data = $request->validate([
            'name'     => 'required|string|max:150',
            'email'    => [
                'required',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_string($value) && self::findByEmail($value)) {
                        $fail('The email has already been taken.');
                    }
                },
            ],
            'password' => ['required', 'string', 'confirmed', ...self::passwordRules()],
        ]);

        try {
            $user = User::create([
                'name'              => $data['name'],
                'email'             => $data['email'],
                'password'          => $data['password'],
                'is_active'         => true,
                'email_verified_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->validationErrorResponse(['email' => ['The email has already been taken.']]);
        }

        $this->sendVerificationEmail($user);
        $user->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Account created. Check your inbox to verify your email address.',
            'data'    => ['user' => $this->userPayload($user), 'token' => $this->issueWebToken($user)],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge(['email' => self::normalizeEmail($request->input('email'))]);

        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = self::findByEmail($data['email']);
        $passwordMatches = Hash::check($data['password'], $user?->password ?? self::DUMMY_PASSWORD_HASH);

        if (!$user || $user->password === null || !$passwordMatches) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => 'Account is deactivated.'], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data'    => ['user' => $this->userPayload($user), 'token' => $this->issueWebToken($user)],
        ]);
    }

    public function googleRedirect(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');

        if (!preg_match(self::STATE_PATTERN, $state)) {
            return $this->redirectToCallback(['error' => 'google_failed']);
        }

        // Stateless (no session), so the frontend's nonce rides along as the OAuth state and is
        // checked by the frontend when the callback hands it back.
        return Socialite::driver('google')->stateless()->with(['state' => $state])->redirect();
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $state = preg_match(self::STATE_PATTERN, $state) ? $state : '';

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning('Google sign-in failed', ['error' => $e->getMessage()]);

            return $this->redirectToCallback(['error' => 'google_failed', 'state' => $state]);
        }

        $email = self::normalizeEmail($googleUser->getEmail());

        if (!$email || !Arr::get($googleUser->user, 'email_verified')) {
            return $this->redirectToCallback(['error' => 'email_unverified', 'state' => $state]);
        }

        $user = User::where('google_id', $googleUser->getId())->first() ?? self::findByEmail($email);

        if ($user && !$user->is_active) {
            return $this->redirectToCallback(['error' => 'account_deactivated', 'state' => $state]);
        }

        if ($user) {
            $this->linkGoogleAccount($user, $googleUser->getId(), $googleUser->getAvatar());
        } else {
            $user = User::create([
                'name'              => Str::limit($googleUser->getName() ?: Str::before($email, '@'), 150, ''),
                'email'             => $email,
                'google_id'         => $googleUser->getId(),
                'provider'          => 'google',
                'image'             => $googleUser->getAvatar(),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);
        }

        $code = Str::random(64);
        Cache::put(self::googleCodeKey($code), $user->id, self::GOOGLE_CODE_TTL_SECONDS);

        return $this->redirectToCallback(['code' => $code, 'state' => $state]);
    }

    public function googleExchange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|size:64']);

        // get + forget instead of pull: only the request whose forget actually removes the entry may use it,
        // so a code raced by two requests mints one token.
        $key    = self::googleCodeKey($data['code']);
        $userId = Cache::get($key);
        $user   = $userId && Cache::forget($key) ? User::find($userId) : null;

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'This sign-in link has expired. Please try again.'], 422);
        }

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => 'Account is deactivated.'], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data'    => ['user' => $this->userPayload($user), 'token' => $this->issueWebToken($user)],
        ]);
    }

    public function verifyEmail(Request $request, string $id, string $hash): RedirectResponse
    {
        // Signed relative to the path: behind a TLS-terminating proxy the request scheme/host differ from APP_URL.
        if (!URL::hasCorrectSignature($request, false)) {
            return $this->redirectToVerifyPage('invalid');
        }

        if (!URL::signatureHasNotExpired($request)) {
            return $this->redirectToVerifyPage('expired');
        }

        $user = User::find($id);

        if (!$user || !hash_equals(sha1($user->email), $hash)) {
            return $this->redirectToVerifyPage('invalid');
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $this->redirectToVerifyPage('verified');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return response()->json(['success' => true, 'message' => 'Your email address is already verified.']);
        }

        if (!$this->sendVerificationEmail($user)) {
            return response()->json(['success' => false, 'message' => 'We could not send the email right now. Please try again shortly.'], 503);
        }

        return response()->json(['success' => true, 'message' => 'Verification email sent.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->merge(['email' => self::normalizeEmail($request->input('email'))]);
        $data = $request->validate(['email' => 'required|email']);

        $user = self::findByEmail($data['email']);

        // Sent after the response so existing and unknown emails take equally long to answer.
        if ($user) {
            dispatch(function () use ($user) {
                try {
                    Password::sendResetLink(['email' => $user->email]);
                } catch (\Throwable $e) {
                    Log::error('Password reset email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            })->afterResponse();
        }

        return response()->json(['success' => true, 'message' => 'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'string', 'confirmed', ...self::passwordRules()],
        ]);

        $email = self::findByEmail($data['email'])?->email ?? $data['email'];

        $status = Password::reset(
            ['email' => $email] + $request->only('password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // The reset link proves the email is theirs; anything issued while it was unverified may be a squatter's.
                $wasUnverified = $user->email_verified_at === null;

                $user->forceFill([
                    'password'          => $password,
                    'remember_token'    => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                $wasUnverified
                    ? $user->tokens()->delete()
                    : $user->tokens()->where('name', self::WEB_TOKEN)->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['success' => true, 'message' => 'Password reset successfully. Please sign in again.']);
        }

        return response()->json(['success' => false, 'message' => __($status)], 422);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['user' => $this->userPayload($request->user())]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->where('name', self::WEB_TOKEN)->delete();
        return response()->json(['success' => true, 'message' => 'Logged out from all devices']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        $sessions = $request->user()
            ->tokens()
            ->where('name', self::WEB_TOKEN)
            ->orderByDesc('last_used_at')
            ->get(['id', 'last_used_at', 'expires_at', 'created_at'])
            ->map(fn ($token) => [
                'id'           => $token->id,
                'last_used_at' => $token->last_used_at,
                'expires_at'   => $token->expires_at,
                'created_at'   => $token->created_at,
                'is_current'   => $token->id === $currentId,
            ]);

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('name', self::WEB_TOKEN)->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'Session not found.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Session revoked.']);
    }

    private function issueWebToken(User $user): string
    {
        $token = $user->createToken(self::WEB_TOKEN, ['web'], now()->addDays(self::WEB_TOKEN_DAYS));
        $newId = $token->accessToken->getKey();

        $stale = $user->tokens()
            ->where('name', self::WEB_TOKEN)
            ->where('id', '!=', $newId)
            ->orderByDesc('created_at')
            ->skip(self::MAX_WEB_SESSIONS - 1)
            ->pluck('id');

        $user->tokens()
            ->where('name', self::WEB_TOKEN)
            ->where('id', '!=', $newId)
            ->where(fn ($query) => $query->whereIn('id', $stale)->orWhere('expires_at', '<', now()))
            ->delete();

        return $token->plainTextToken;
    }

    // Google has proven ownership of the email. An unverified local account with that email may have
    // been registered by someone else to squat on it, so its password and sessions are discarded.
    private function linkGoogleAccount(User $user, string $googleId, ?string $avatar): void
    {
        if ($user->email_verified_at === null) {
            $user->tokens()->delete();
            $user->forceFill(['email_verified_at' => now(), 'password' => null]);
        }

        $user->forceFill([
            'google_id' => $googleId,
            'image'     => $user->image ?: $avatar,
            'provider'  => $user->provider && $user->password !== null ? $user->provider : 'google',
        ])->save();
    }

    private function userPayload(User $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'image'          => $user->image,
            'provider'       => $user->provider,
            'is_active'      => $user->is_active,
            'created_at'     => $user->created_at,
            'email_verified' => $user->email_verified_at !== null,
            'entitlements'   => app(EntitlementService::class)->entitlements($user),
        ];
    }

    private function sendVerificationEmail(User $user): bool
    {
        try {
            $user->notify(new VerifyEmail());

            return true;
        } catch (\Throwable $e) {
            Log::error('Verification email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function redirectToCallback(array $fragment): RedirectResponse
    {
        return redirect(self::frontendUrl() . '/auth/callback#' . http_build_query($fragment));
    }

    private function redirectToVerifyPage(string $status): RedirectResponse
    {
        return redirect(self::frontendUrl() . '/auth/verify-email?status=' . $status);
    }

    private static function frontendUrl(): string
    {
        return rtrim(config('app.frontend_url'), '/');
    }

    private static function googleCodeKey(string $code): string
    {
        return 'google_login:' . hash('sha256', $code);
    }

    private static function passwordRules(): array
    {
        return [
            PasswordRule::min(8)->uncompromised(),
            // bcrypt ignores everything past 72 bytes; multibyte characters make bytes exceed characters.
            function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must not be longer than 72 bytes.');
                }
            },
        ];
    }

    private static function normalizeEmail(mixed $email): mixed
    {
        return is_string($email) ? Str::lower(trim($email)) : $email;
    }

    // Older rows may hold mixed-case emails, so lookups ignore case.
    private static function findByEmail(string $email): ?User
    {
        return User::whereRaw('lower(email) = ?', [Str::lower($email)])->first();
    }
}
