<?php

namespace App\Support;

use App\Models\Admin;

// admin_roles.module_access stores these comma-separated, or "*" for all.
final class AdminModules
{
    public const ALL = '*';

    public const MARKET = 'market';

    public const QUALITY = 'quality';

    public const CUSTOMERS = 'customers';

    public const BILLING = 'billing';

    public const SYSTEM = 'system';

    public const LABELS = [
        self::MARKET => 'Market data (stocks, sectors)',
        self::QUALITY => 'Data quality (integrity, scraper jobs, rescrape queue)',
        self::CUSTOMERS => 'Customers (users, accounts)',
        self::BILLING => 'Billing (plans, subscriptions, Paddle)',
        self::SYSTEM => 'System (admins and roles)',
    ];

    public static function allows(string $module): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->canAccessModule($module);
    }
}
