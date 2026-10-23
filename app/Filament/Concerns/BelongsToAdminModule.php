<?php

namespace App\Filament\Concerns;

use App\Support\AdminModules;

// Using classes must declare: protected static string $adminModule
trait BelongsToAdminModule
{
    public static function canAccess(): bool
    {
        return AdminModules::allows(static::$adminModule) && parent::canAccess();
    }
}
