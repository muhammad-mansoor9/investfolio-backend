<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid;

class PaddleWebhookEvent extends PgsqlModel
{
    use HasUuid;

    protected $table = 'paddle_webhook_events';

    protected $fillable = [
        'subscription_id',
        'paddle_event_id',
        'event_type',
        'event_data',
        'processed_at'
    ];

    protected $casts = [
        'event_data' => 'array',
        'processed_at' => 'datetime'
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class);
    }

    public function scopeProcessed($query)
    {
        return $query->whereNotNull('processed_at');
    }

    public function scopeUnprocessed($query)
    {
        return $query->whereNull('processed_at');
    }

    public function markAsProcessed()
    {
        $this->update(['processed_at' => now()]);
    }
}
