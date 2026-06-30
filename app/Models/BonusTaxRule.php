<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusTaxRule extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'id',
        'stock_id',
        'is_filer',
        'is_general',
        'bonus_type',
        'tax_rate',
    ];

    protected $casts = [
        'id' => 'string',
        'is_filer' => 'boolean',
        'is_general' => 'boolean',
        'tax_rate' => 'decimal:2',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
