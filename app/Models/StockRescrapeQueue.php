<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRescrapeQueue extends PgsqlModel
{
    protected $table = 'stock_rescrape_queue';

    protected $fillable = [
        'stock_id',
        'reason',
        'status',
        'attempts',
        'max_attempts',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'max_attempts' => 'integer',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
