<?php

namespace App\Http\Controllers\API;

use App\Models\McpOauthRefreshToken;
use App\Services\EntitlementService;
use App\Services\McpTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class McpConnectionController extends BaseController
{
    private const CLIENTS = ['chatgpt', 'claude', 'gemini', 'claude-code', 'other'];

    // An OAuth connection rotates its tokens, so it is listed once per refresh-token family (keyed by the
    // family id); manually created URL/token connections have no family and are listed by token id.
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $refreshRows = McpOauthRefreshToken::where('user_id', $user->id)->get();
        $familyTokenIds = $refreshRows->pluck('access_token_id')->filter();

        $tokens = $user->tokens()
            ->where('name', 'like', McpTokenService::TOKEN_PREFIX . '%')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->keyBy('id');

        $oauthConnections = $refreshRows
            ->where('expires_at', '>', now())
            ->groupBy('family_id')
            ->map(function ($rows, $familyId) use ($tokens) {
                $familyTokens = $tokens->only($rows->pluck('access_token_id')->filter()->all());

                return [
                    'id' => $familyId,
                    'client' => $rows->first()->client_name,
                    'last_used_at' => $familyTokens->max('last_used_at'),
                    'created_at' => $rows->min('created_at'),
                ];
            })
            ->values();

        $manualConnections = $tokens
            ->reject(fn ($token) => $familyTokenIds->contains($token->id))
            ->reject(fn ($token) => $token->expires_at !== null && $token->expires_at->isPast())
            ->map(fn ($token) => [
                'id' => $token->id,
                'client' => Str::after($token->name, McpTokenService::TOKEN_PREFIX),
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
            ])
            ->values();

        $connections = $oauthConnections->concat($manualConnections)->sortByDesc('created_at')->values();

        return $this->sendResponse([
            'connections' => $connections,
            'endpoint' => url('/mcp'),
        ], 'MCP connections retrieved');
    }

    public function store(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $entitlements->assertCanUse($request->user(), 'mcp_access');

        $data = $request->validate(['client' => 'required|string|in:' . implode(',', self::CLIENTS)]);

        $token = $request->user()->createToken(McpTokenService::TOKEN_PREFIX . $data['client'], ['mcp']);
        // findToken() resolves the bare secret via hash lookup, so the URL
        // variant avoids the "id|" prefix whose pipe is invalid in URLs
        $secret = Str::after($token->plainTextToken, '|');

        return $this->createdResponse([
            'id' => $token->accessToken->id,
            'client' => $data['client'],
            'token' => $token->plainTextToken,
            'url' => url('/mcp/' . $secret),
            'endpoint' => url('/mcp'),
        ], 'MCP connection created');
    }

    public function destroy(Request $request, string $id, McpTokenService $tokens): JsonResponse
    {
        $user = $request->user();
        $familyId = McpOauthRefreshToken::where('user_id', $user->id)->where('family_id', $id)->value('family_id')
            ?? $tokens->familyForAccessToken($id);

        if ($familyId !== null && McpOauthRefreshToken::where('family_id', $familyId)->where('user_id', $user->id)->exists()) {
            $tokens->revokeFamily($familyId);

            return $this->deletedResponse('Connection disconnected.');
        }

        $deleted = $user->tokens()
            ->where('id', $id)
            ->where('name', 'like', McpTokenService::TOKEN_PREFIX . '%')
            ->delete();

        if (!$deleted) {
            return $this->notFoundResponse('Connection not found.');
        }

        return $this->deletedResponse('Connection disconnected.');
    }
}
