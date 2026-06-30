<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends PgsqlModel
{
    protected $fillable = ['name', 'type'];
}
