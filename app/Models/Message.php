<?php
namespace App\Models;

use App\Traits\HasUuid;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends PgsqlModel
{
    use HasUuid;
    use HasFactory;

    public $timestamps = false;
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->select('id', 'first_name', 'last_name', 'profile_image');
    }
}

