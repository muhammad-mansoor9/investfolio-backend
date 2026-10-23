<?php

namespace App\Models;

use App\Support\AdminModules;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminRole extends PgsqlModel
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'name',
        'module_access',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class, 'admin_role_id');
    }

    public function hasFullAccess(): bool
    {
        return trim((string) $this->module_access) === AdminModules::ALL;
    }

    public function modules(): array
    {
        return $this->hasFullAccess()
            ? array_keys(AdminModules::LABELS)
            : array_values(array_filter(array_map('trim', explode(',', (string) $this->module_access))));
    }

    public function allowsModule(string $module): bool
    {
        return in_array($module, $this->modules(), true);
    }
}
