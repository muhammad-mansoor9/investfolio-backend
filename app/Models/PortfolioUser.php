<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PortfolioUser extends Pivot
{
    protected $table = 'portfolio_user';

    // Primary keys are UUID strings from both FK columns
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'portfolio_id',
        'user_id',
        'is_owner',
        'full_access',
        'invite_accepted_at',
    ];

    protected $casts = [
        'is_owner' => 'boolean',
        'full_access' => 'boolean',
        'invite_accepted_at' => 'datetime',
    ];

    /* --- convenient back-references --- */

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(BasePortfolio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* --- scopes --- */

    public function scopeOwners($query)
    {
        return $query->where('is_owner', true);
    }

    public function scopeFullAccess($query)
    {
        return $query->where('full_access', true);
    }
}
