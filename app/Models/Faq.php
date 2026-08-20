<?php
namespace App\Models;

use App\Traits\HasUuid;
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
// Sync marker: 2026-08-20 17:39:39
