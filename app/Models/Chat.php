<?php
namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends PgsqlModel
{
    use HasUuid;
    use HasFactory;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function members()
    {
        return $this->hasMany(ChatMember::class, 'chat_id');
    }
}
// Sync marker: 2026-08-20 17:39:39
