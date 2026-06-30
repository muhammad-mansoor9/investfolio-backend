<?php
namespace App\Models;

use Investfolio\InvestfolioShared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends PgsqlModel
{
    use HasUuid;
    use HasFactory;

    protected $casts = [
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', '=', 1);
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function channel()
    {
        return $this->belongsTo(ChannelList::class, 'channel_id');
    }
}
