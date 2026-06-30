<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BonusRule extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'country',
        'tax_rate',
        'is_taxable',
        'tax_event',
        'cost_basis_adjustment',
        'is_active',
        'notes',
        'effective_date',
    ];

    protected $casts = [
        'tax_rate' => 'float',
        'is_taxable' => 'boolean',
        'is_active' => 'boolean',
        'effective_date' => 'date',
    ];
}
