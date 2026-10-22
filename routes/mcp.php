<?php

use App\Http\Controllers\API\McpController;
use App\Http\Controllers\API\McpOAuthController;
use Illuminate\Support\Facades\Route;

// OAuth 2.1 discovery + flow for MCP clients (ChatGPT, Claude, Gemini, Claude Code).
// The authorize screen itself lives on the frontend (/mcp-authorize), which calls
// POST /api/oauth/authorize after the user signs in and approves.
Route::get('/.well-known/oauth-protected-resource', [McpOAuthController::class, 'protectedResourceMetadata']);
Route::get('/.well-known/oauth-protected-resource/mcp', [McpOAuthController::class, 'protectedResourceMetadata']);
Route::get('/.well-known/oauth-authorization-server', [McpOAuthController::class, 'authorizationServerMetadata']);
Route::post('/oauth/register', [McpOAuthController::class, 'register'])->middleware('throttle:20,1');
Route::post('/oauth/token', [McpOAuthController::class, 'token'])->middleware('throttle:30,1');

// MCP endpoint — Streamable HTTP transport (JSON-RPC over POST)
// Auth: OAuth-issued bearer token, or token embedded in the URL path for
// clients that only accept an address and no sign-in
Route::middleware('mcp.auth')->group(function () {
    Route::any('/mcp', [McpController::class, 'handle']);
    Route::any('/mcp/{token}', [McpController::class, 'handle']);
});
