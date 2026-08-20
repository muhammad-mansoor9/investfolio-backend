<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionFeature extends PgsqlModel
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'name',
        'description',
        'feature_key',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function plans()
    {
        return $this->belongsToMany(SubscriptionPlan::class, 'subscription_plan_features')
            ->withPivot('value_limit', 'is_enabled')
            ->withTimestamps();
    }
}

