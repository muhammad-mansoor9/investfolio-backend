<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;

class BrokerResearch extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'stock_id',
        'broker_name',
        'date',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
        'date' => 'date',
    ];

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
// Sync marker: 2026-08-20 17:39:39
