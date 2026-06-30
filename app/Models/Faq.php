<?php
namespace App\Models;

use Investfolio\InvestfolioShared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Faq extends PgsqlModel
{
    use HasFactory;
    use HasUuid;

    protected $casts = [
        'is_active' => 'integer'
    ];

    protected $fillable = [];

    protected function scopeOfStatus($query, $status)
    {
        $query->where('is_active', $status);
    }
}
