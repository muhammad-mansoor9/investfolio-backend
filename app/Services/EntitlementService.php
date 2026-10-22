<?php

namespace App\Services;

use App\Exceptions\FeatureGateException;
use App\Models\AnalystTarget;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a user's plan includes and how much of each metered feature they have used.
 *
 * A plan includes a feature when a subscription_plan_features row links them; usage_limit NULL is
 * unlimited. track_usage features are counted per calendar month (UTC); analyst_targets is limited
 * by the number of ACTIVE targets the user currently has. A paused subscription is entitled to the
 * default plan's features until it resumes.
 */
class EntitlementService
{
    public function entitlements(User $user): array
    {
        $subscription = $this->subscription($user);
        $plan = $this->effectivePlan($subscription);
        $features = $plan->features;
        $usage = $this->monthlyUsage($subscription, $features->where('track_usage', true));

        $entitlements = [];
        foreach ($features as $feature) {
            $entitlements[$feature->feature_key] = [
                'limit' => $this->pivotLimit($feature),
                'used' => $this->used($user, $feature, $usage),
            ];
        }

        return [
            'plan_slug' => $plan->slug,
            'plan_name' => $plan->name,
            'is_paid' => $subscription->isPaid(),
            'features' => $entitlements ?: new \stdClass(),
        ];
    }

    public function includes(User $user, string $key): bool
    {
        return $this->feature($user, $key) !== null;
    }

    public function assertCanUse(User $user, string $key): void
    {
        $subscription = $this->subscription($user);
        $plan = $this->effectivePlan($subscription);
        $feature = $this->feature($user, $key);

        if ($feature === null) {
            $catalog = SubscriptionFeature::where('feature_key', $key)->first();
            $name = $catalog?->name ?? Str::headline($key);
            $message = $subscription->isPaused()
                ? "{$name} is unavailable while your {$subscription->plan->name} subscription is paused."
                : "{$name} isn't included in the {$plan->name} plan.";
            throw new FeatureGateException($message, 'feature_locked', $key);
        }

        $limit = $this->pivotLimit($feature);
        if ($limit === null) {
            return;
        }

        $used = $this->used($user, $feature, $this->monthlyUsage($subscription, collect([$feature])));
        if ($used !== null && $used >= $limit) {
            $label = $feature->pivot->display_text ?: "{$limit} {$feature->name}";
            $period = $feature->track_usage ? ' this month' : '';
            $message = "{$label} on the {$plan->name} plan; you've reached the limit{$period}.";
            throw new FeatureGateException($message, 'limit_reached', $key, $limit, $used);
        }
    }

    public function recordUsage(User $user, string $key): void
    {
        $subscription = $this->subscription($user);
        $feature = $this->feature($user, $key);
        if ($feature === null || !$feature->track_usage) {
            return;
        }

        DB::statement(
            'INSERT INTO subscription_feature_usage (id, subscription_id, feature_id, usage_count, period_start, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, now(), now())
             ON CONFLICT (subscription_id, feature_id, period_start)
             DO UPDATE SET usage_count = subscription_feature_usage.usage_count + 1, updated_at = now()',
            [(string) Str::uuid(), $subscription->id, $feature->id, $this->periodStart()]
        );
    }

    private function subscription(User $user): UserSubscription
    {
        $subscription = $user->ensureActiveSubscription();
        $subscription->loadMissing('plan.features');

        return $subscription;
    }

    private function feature(User $user, string $key): ?SubscriptionFeature
    {
        return $this->effectivePlan($this->subscription($user))->features->firstWhere('feature_key', $key);
    }

    private function effectivePlan(UserSubscription $subscription): SubscriptionPlan
    {
        return $subscription->isPaused() ? SubscriptionPlan::default()->load('features') : $subscription->plan;
    }

    private function pivotLimit(SubscriptionFeature $feature): ?int
    {
        return $feature->pivot->usage_limit !== null ? (int) $feature->pivot->usage_limit : null;
    }

    /**
     * @return array<string, int> usage this month keyed by feature id
     */
    private function monthlyUsage(UserSubscription $subscription, Collection $features): array
    {
        if ($features->isEmpty()) {
            return [];
        }

        return DB::table('subscription_feature_usage')
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $this->periodStart())
            ->whereIn('feature_id', $features->pluck('id'))
            ->pluck('usage_count', 'feature_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function used(User $user, SubscriptionFeature $feature, array $monthlyUsage): ?int
    {
        if ($feature->track_usage) {
            return $monthlyUsage[$feature->id] ?? 0;
        }
        if ($feature->feature_key === 'analyst_targets') {
            return AnalystTarget::where('user_id', $user->id)->where('status', 'ACTIVE')->count();
        }

        return null;
    }

    private function periodStart(): string
    {
        return now()->utc()->startOfMonth()->toDateString();
    }
}
