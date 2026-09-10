<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;

class JournalInsight extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'prompt',
        'response',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
