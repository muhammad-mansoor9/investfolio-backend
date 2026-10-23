<?php

namespace App\Http\Middleware;

use App\Support\AdminModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// App users only hold bearer tokens, which a browser cannot send here. Basic auth stays as a fallback for when
// no admin exists or the panel is broken.
class LogViewerBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::isPanelAdmin()) {
            return $next($request);
        }

        if (! self::configured()) {
            abort(404);
        }

        if (! self::passes($request)) {
            return response('Unauthorized.', 401, ['WWW-Authenticate' => 'Basic realm="Log Viewer", charset="UTF-8"']);
        }

        return $next($request);
    }

    public static function passes(Request $request): bool
    {
        if (self::isPanelAdmin()) {
            return true;
        }

        return self::configured()
            && hash_equals((string) config('log-viewer.basic_auth.username'), (string) $request->getUser())
            && hash_equals((string) config('log-viewer.basic_auth.password'), (string) $request->getPassword());
    }

    private static function isPanelAdmin(): bool
    {
        return AdminModules::allows(AdminModules::SYSTEM);
    }

    private static function configured(): bool
    {
        return filled(config('log-viewer.basic_auth.username')) && filled(config('log-viewer.basic_auth.password'));
    }
}
