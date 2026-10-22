<?php

return [
    // Read by Illuminate\Http\Middleware\TrustProxies when App\Http\Middleware\TrustProxies::$proxies is null.
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
