<?php
namespace App\Models;

use App\Traits\HasUuid;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMember extends PgsqlModel
{
    use HasUuid;
    use HasFactory;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'first_name', 'last_name', 'profile_image']);
    }

    public function chat()
    {
        return $this->hasOne(Chat::class, 'chat_id')->select(['id', 'name']);
    }
}

