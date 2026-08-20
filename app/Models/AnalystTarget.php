<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalystTarget extends Model
{
    use HasUuids;

    protected $table = 'analyst_targets';

    protected $fillable = [
        'user_id',
        'stock_id',
        'analyst_name',
        'buy_1',
        'buy_2',
        'stop_loss',
        'target_1',
        'target_2',
        'publish_date',
        'expiry_date',
        'status',
    ];

    protected $casts = [
        'buy_1' => 'decimal:4',
        'buy_2' => 'decimal:4',
        'stop_loss' => 'decimal:4',
        'target_1' => 'decimal:4',
        'target_2' => 'decimal:4',
        'publish_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
// Sync marker: 2026-08-20 17:39:39
