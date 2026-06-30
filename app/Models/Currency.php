<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends PgsqlModel
{
    protected $table = 'currencies';

    protected $fillable = [
        'name',
        'symbol',
        'code',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $timestamps = true; // because created_at and updated_at are used

    protected $attributes = [
        'status' => 0,
    ];
}
