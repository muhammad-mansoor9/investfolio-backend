<?php
namespace App\Models;

use Investfolio\InvestfolioShared\Traits\HasUuid;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChannelUser extends PgsqlModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $casts = [
        'is_read' => 'boolean'
    ];
    protected $fillable = [
        'channel_id',
        'user_id',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChannelList::class);
    }

    protected static function newFactory()
    {
        return \Modules\ChattingModule\Database\factories\ChannelUserFactory::new();
    }
}
