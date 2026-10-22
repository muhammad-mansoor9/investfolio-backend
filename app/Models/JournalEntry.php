<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;

class JournalEntry extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'content',
        'tags',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    protected $attributes = [
        'tags' => '[]',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
