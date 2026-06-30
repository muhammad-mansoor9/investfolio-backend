<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChartLayout extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'user_id', 'name', 'description', 'symbol', 'timeframe', 'is_favorite'
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
