<?php
namespace App\Models;

use App\Traits\HasUuid;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChannelConversation extends PgsqlModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [];

    //relation
    public function conversationFiles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ConversationFile::class, 'conversation_id', 'id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChannelList::class);
    }
}
// Sync marker: 2026-08-20 17:39:39
