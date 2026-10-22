<?php
namespace App\Providers;

use App\Http\Middleware\LogViewerBasicAuth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        /**
         * Configure Sanctum to use the custom PersonalAccessToken model
         * with UUID primary key support.
         */
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // Behind a TLS-terminating proxy the request looks like http, which would
        // make url() emit http:// links in the MCP OAuth discovery metadata
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        LogViewer::auth(fn ($request) => LogViewerBasicAuth::passes($request));
    }
}
