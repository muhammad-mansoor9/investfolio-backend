<?php

namespace App\Http\Controllers\API;

use App\Models\McpOauthClient;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\McpTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class McpOAuthController extends BaseController
{
    private const CODE_TTL_MINUTES = 10;

    public function protectedResourceMetadata(): JsonResponse
    {
        return response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['mcp'],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServerMetadata(): JsonResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => $frontendUrl . '/mcp-authorize',
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['mcp'],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'client_name' => 'nullable|string|max:255',
                'redirect_uris' => 'required|array|min:1|max:10',
                'redirect_uris.*' => 'required|string|url|max:2000',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'invalid_client_metadata',
                'error_description' => collect($e->errors())->flatten()->implode(' '),
            ], 400);
        }

        $client = McpOauthClient::create([
            'name' => $data['client_name'] ?? 'MCP Client',
            'redirect_uris' => $data['redirect_uris'],
        ]);

        return response()->json([
            'client_id' => $client->id,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'client_id_issued_at' => $client->created_at?->timestamp,
        ], 201);
    }

    public function showClient(string $id): JsonResponse
    {
        $client = Str::isUuid($id) ? McpOauthClient::find($id) : null;
        if (!$client) {
            return $this->notFoundResponse('Unknown client.');
        }

        return $this->sendResponse([
            'id' => $client->id,
            'name' => $client->name,
        ], 'Client retrieved');
    }

    public function approve(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $entitlements->assertCanUse($request->user(), 'mcp_access');

        $data = $request->validate([
            'client_id' => 'required|uuid',
            'redirect_uri' => 'required|string|url|max:2000',
            'state' => 'nullable|string|max:5000',
            'code_challenge' => 'required|string|max:256',
            'code_challenge_method' => 'required|in:S256',
            'scope' => 'nullable|string|max:500',
        ]);

        $client = McpOauthClient::find($data['client_id']);
        if (!$client) {
            return $this->sendError('Unknown client.', [], 400);
        }
        if (!in_array($data['redirect_uri'], $client->redirect_uris ?? [], true)) {
            return $this->sendError('redirect_uri is not registered for this client.', [], 400);
        }

        $code = Str::random(64);
        Cache::put('mcp_oauth_code:' . hash('sha256', $code), [
            'user_id' => $request->user()->id,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'redirect_uri' => $data['redirect_uri'],
            'code_challenge' => $data['code_challenge'],
        ], now()->addMinutes(self::CODE_TTL_MINUTES));

        $query = http_build_query(array_filter([
            'code' => $code,
            'state' => $data['state'] ?? null,
        ], fn ($v) => $v !== null));

        $separator = str_contains($data['redirect_uri'], '?') ? '&' : '?';

        return $this->sendResponse([
            'redirect' => $data['redirect_uri'] . $separator . $query,
        ], 'Authorization granted');
    }

    public function token(Request $request, McpTokenService $tokens): JsonResponse
    {
        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchangeCode($request, $tokens),
            'refresh_token' => $this->exchangeRefreshToken($request, $tokens),
            default => $this->oauthError('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    private function exchangeCode(Request $request, McpTokenService $tokens): JsonResponse
    {
        $code = (string) $request->input('code', '');
        $verifier = (string) $request->input('code_verifier', '');
        if ($code === '' || $verifier === '') {
            return $this->oauthError('invalid_request', 'code and code_verifier are required.');
        }

        $grant = Cache::pull('mcp_oauth_code:' . hash('sha256', $code));
        if (!$grant) {
            return $this->oauthError('invalid_grant', 'Authorization code is invalid or expired.');
        }
        if ($request->filled('client_id') && $request->input('client_id') !== $grant['client_id']) {
            return $this->oauthError('invalid_grant', 'client_id does not match the authorization code.');
        }
        if ($request->filled('redirect_uri') && $request->input('redirect_uri') !== $grant['redirect_uri']) {
            return $this->oauthError('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals($grant['code_challenge'], $challenge)) {
            return $this->oauthError('invalid_grant', 'PKCE verification failed.');
        }

        $user = User::find($grant['user_id']);
        if (!$user || !$user->is_active) {
            return $this->oauthError('invalid_grant', 'User account is unavailable.');
        }

        return $this->tokenResponse(
            $tokens->issue($user, $grant['client_id'], Str::limit($grant['client_name'], 60, ''))
        );
    }

    private function exchangeRefreshToken(Request $request, McpTokenService $tokens): JsonResponse
    {
        $refreshToken = (string) $request->input('refresh_token', '');
        if ($refreshToken === '') {
            return $this->oauthError('invalid_request', 'refresh_token is required.');
        }

        $clientId = $request->input('client_id');
        $issued = $tokens->refresh($refreshToken, is_string($clientId) && Str::isUuid($clientId) ? $clientId : null);
        if ($issued === null) {
            return $this->oauthError('invalid_grant', 'Refresh token is invalid, expired or revoked. Reconnect to continue.');
        }

        return $this->tokenResponse($issued);
    }

    private function tokenResponse(array $issued): JsonResponse
    {
        return response()->json([
            'access_token' => $issued['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
            'refresh_token' => $issued['refresh_token'],
            'scope' => 'mcp',
        ], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function oauthError(string $error, string $description): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'error_description' => $description,
        ], 400);
    }
}
