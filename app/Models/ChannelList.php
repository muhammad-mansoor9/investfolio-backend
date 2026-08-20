<?php
namespace App\Models;

use App\Traits\HasUuid;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChannelList extends PgsqlModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'reference_id',
        'reference_type',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'channel_users', 'channel_id', 'user_id');
    }

    //relation
    public function channelUsers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChannelUser::class, 'channel_id', 'id')->with('user');
    }

    public function channelConversations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChannelConversation::class, 'channel_id', 'id');
    }

    protected static function newFactory()
    {
        return \Modules\ChattingModule\Database\factories\ChannelListFactory::new();
    }
}

