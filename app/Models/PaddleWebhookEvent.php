<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PaddleWebhookEvent extends PgsqlModel
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'paddle_webhook_events';

    protected $fillable = [
        'event_id',
        'event_type',
        'occurred_at',
        'payload',
        'status',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'payload' => 'array',
    ];
}
