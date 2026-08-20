<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PaymentMethod extends PgsqlModel
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'display_order',
        'is_enabled',
        'configuration',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'configuration' => 'json',
        'display_order' => 'integer',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }
}
// Sync marker: 2026-08-20 17:39:39
