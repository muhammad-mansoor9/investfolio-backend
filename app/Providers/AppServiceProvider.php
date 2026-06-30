<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $aliases = [
            'Investfolio\InvestfolioShared\Traits\HasUuid'              => \App\Traits\HasUuid::class,
            'Investfolio\InvestfolioShared\Traits\HasMarketData'        => \App\Traits\HasMarketData::class,
            'Investfolio\InvestfolioShared\Traits\HasConnectedAccounts' => \App\Traits\HasConnectedAccounts::class,
        ];

        foreach ($aliases as $abstract => $concrete) {
            if (!class_exists($abstract)) {
                class_alias($concrete, $abstract);
            }
        }
    }

    public function boot(): void
    {
        /**
         * Configure Sanctum to use the custom PersonalAccessToken model
         * with UUID primary key support.
         */
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
    }
}
