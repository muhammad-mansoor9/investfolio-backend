<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CgtTaxRule extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'id',
        'min_holding_days',
        'max_holding_days',
        'is_filer',
        'tax_rate',
    ];

    protected $casts = [
        'id' => 'string',
        'min_holding_days' => 'integer',
        'max_holding_days' => 'integer',
        'is_filer' => 'boolean',
        'tax_rate' => 'decimal:2',
    ];

    public function exchangeMarket()
    {
        return $this->belongsTo(ExchangeMarket::class, 'exchange_id');
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
