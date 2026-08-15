<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Stock;

class AnalystTarget extends Model
{
    use HasUuids;

    protected $table = 'analyst_targets';

    protected $fillable = [
        'user_id',
        'stock_id',
        'analyst_name',
        'stop_loss',
        'target_1',
        'target_2',
        'status',
    ];

    protected $casts = [
        'stop_loss' => 'decimal:4',
        'target_1' => 'decimal:4',
        'target_2' => 'decimal:4',
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
