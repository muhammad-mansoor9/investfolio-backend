<?php
namespace App\Models;

use App\Models\PgsqlModel;
use Investfolio\InvestfolioShared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class  AdminRole extends PgsqlModel
{
    use HasUuid;
    use HasFactory;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
