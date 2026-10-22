<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            $user->tokens()->where('name', 'psx-web')->delete();

            return response()->json(['success' => false, 'message' => 'Account is deactivated.'], 403);
        }

        return $next($request);
    }
}
