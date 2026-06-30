<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PayoutTaxRule extends PgsqlModel
{
    protected $fillable = [
        'stock_id', 'is_filer', 'is_general', 'tax_rate'
    ];

    protected $casts = [
        'is_filer' => 'boolean',
        'is_general' => 'boolean',
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
