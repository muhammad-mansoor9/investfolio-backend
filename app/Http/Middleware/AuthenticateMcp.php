<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: $request->route('token');
        $accessToken = is_string($token) && $token !== '' ? PersonalAccessToken::findToken($token) : null;

        // findToken() doesn't check expiry (Sanctum's guard does, but /mcp bypasses the guard).
        $expired = $accessToken?->expires_at !== null && $accessToken->expires_at->isPast();

        if (!$accessToken || $expired || !$accessToken->can('mcp') || !$accessToken->tokenable || !$accessToken->tokenable->is_active) {
            $challenge = 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource') . '"';

            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32001,
                    'message' => $expired
                        ? 'Access token expired; refresh it or reconnect.'
                        : 'Unauthorized: a valid MCP connection token is required. Create one on the MCP Integration page.',
                ],
                'id' => null,
            ], 401, [
                'WWW-Authenticate' => $accessToken ? $challenge . ', error="invalid_token"' : $challenge,
            ]);
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $accessToken->tokenable);

        return $next($request);
    }
}
