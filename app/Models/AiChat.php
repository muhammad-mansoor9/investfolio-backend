<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;

class AiChat extends PgsqlModel
{
    use HasUuid;

    protected $fillable = [
        'role',
        'content',
    ];

    protected $hidden = [];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($chat) {

            $chat->user_id = auth()->user()->id;
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chatable()
    {
        return $this->morphTo();
    }
}

