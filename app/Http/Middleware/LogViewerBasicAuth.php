<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API has no session login (Sanctum bearer tokens only), so the log viewer, which a browser opens
 * directly, is guarded by HTTP Basic credentials from the environment instead of a user gate.
 */
class LogViewerBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!self::configured()) {
            abort(404);
        }

        if (!self::passes($request)) {
            return response('Unauthorized.', 401, ['WWW-Authenticate' => 'Basic realm="Log Viewer", charset="UTF-8"']);
        }

        return $next($request);
    }

    public static function passes(Request $request): bool
    {
        return self::configured()
            && hash_equals((string) config('log-viewer.basic_auth.username'), (string) $request->getUser())
            && hash_equals((string) config('log-viewer.basic_auth.password'), (string) $request->getPassword());
    }

    private static function configured(): bool
    {
        return filled(config('log-viewer.basic_auth.username')) && filled(config('log-viewer.basic_auth.password'));
    }
}
