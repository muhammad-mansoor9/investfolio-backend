<?php

declare(strict_types=1);

namespace App\Models;

class IndexPrice extends PgsqlModel
{
    protected $fillable = ['index_id', 'date', 'open', 'high', 'low', 'close', 'price', 'change', 'volume', 'change_percent'];

    protected $casts = [
        'date' => 'datetime',
        'open' => 'float',
        'high' => 'float',
        'low' => 'float',
        'close' => 'float',
        'price' => 'float',
        'change' => 'float',
        'change_percent' => 'float',
    ];

    public function index()
    {
        return $this->belongsTo(Index::class);
    }
}
