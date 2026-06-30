<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class IndexStock extends Pivot
{
    protected $table = 'index_stocks';
    protected $fillable = ['index_id', 'stock_id'];
}
