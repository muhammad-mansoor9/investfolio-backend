<?php

namespace App\Http\Controllers\API;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use Illuminate\Http\JsonResponse;

class PlanController extends BaseController
{
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::active()
            ->with(['prices' => fn ($q) => $q->where('is_active', true)->orderBy('price'), 'features'])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'description' => $plan->description,
                'is_default' => $plan->is_default,
                'is_featured' => $plan->is_featured,
                'trial_days' => $plan->trial_days,
                'prices' => $plan->prices->map(fn (SubscriptionPlanPrice $price) => $price->toApiArray())->values(),
                'features' => $plan->features->map(fn ($feature) => [
                    'key' => $feature->feature_key,
                    'name' => $feature->name,
                    'description' => $feature->description,
                    'icon' => $feature->icon,
                    'display_text' => $feature->pivot->display_text ?: $feature->name,
                    'usage_limit' => $feature->pivot->usage_limit !== null ? (int) $feature->pivot->usage_limit : null,
                    'track_usage' => $feature->track_usage,
                ])->values(),
            ]);

        return $this->sendResponse(['plans' => $plans], 'Plans retrieved successfully');
    }
}
