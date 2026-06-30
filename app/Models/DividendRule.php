<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DividendRule extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'country',
        'tax_rate',
        'withholding_tax_rate',
        'exemption_amount',
        'currency',
        'is_active',
        'notes',
        'effective_date',
    ];

    protected $casts = [
        'tax_rate' => 'float',
        'withholding_tax_rate' => 'float',
        'exemption_amount' => 'float',
        'is_active' => 'boolean',
        'effective_date' => 'date',
    ];
}
