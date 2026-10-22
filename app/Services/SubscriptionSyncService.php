<?php

namespace App\Services;

use App\Exceptions\DuplicateSubscriptionException;
use App\Exceptions\PaddleException;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies Paddle subscription, transaction and adjustment entities to local state. Used by the webhook
 * and by API-driven actions (checkout sync, cancel, resume, plan change) so both paths behave the same.
 */
class SubscriptionSyncService
{
    private const ZERO_DECIMAL_CURRENCIES = ['JPY', 'KRW'];

    private const PADDLE_STATUSES = ['active', 'trialing', 'past_due', 'paused', 'canceled'];

    // A transaction's payment status only moves forward; late or retried events can't undo a settlement.
    private const PAYMENT_STATUS_RANK = ['pending' => 0, 'failed' => 1, 'completed' => 2, 'refunded' => 3];

    public function __construct(private PaddleService $paddle)
    {
    }

    /**
     * Returns null when the entity can't be tied to a local user.
     *
     * @throws DuplicateSubscriptionException when the user already has a different live subscription
     */
    public function applySubscription(array $entity, ?User $user = null): ?UserSubscription
    {
        $user ??= $this->resolveUser($entity['custom_data'] ?? null, $entity['customer_id'] ?? null, $entity['id'] ?? null);
        if (!$user) {
            Log::warning('Paddle subscription without a matching user', ['subscription_id' => $entity['id'] ?? null]);

            return null;
        }

        return DB::transaction(function () use ($entity, $user) {
            $user->ensureActiveSubscription();
            $subscription = UserSubscription::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $status = $entity['status'] ?? null;
            if (!in_array($status, self::PADDLE_STATUSES, true)) {
                throw new \UnexpectedValueException("Unknown Paddle subscription status: {$status}");
            }

            $isCurrent = $subscription->paddle_subscription_id === null || $subscription->paddle_subscription_id === $entity['id'];
            if (!$isCurrent) {
                if ($status === 'canceled') {
                    return $subscription->load('plan', 'price');
                }
                Log::warning('Second live Paddle subscription for user', [
                    'user_id' => $user->id,
                    'current_subscription_id' => $subscription->paddle_subscription_id,
                    'duplicate_subscription_id' => $entity['id'] ?? null,
                ]);
                throw new DuplicateSubscriptionException($subscription->paddle_subscription_id, $entity['id'] ?? '');
            }

            // Paddle's updated_at versions the entity, so a late webhook can't overwrite newer state.
            $version = isset($entity['updated_at']) ? Carbon::parse($entity['updated_at']) : now();
            if ($subscription->last_event_at && $version->lessThanOrEqualTo($subscription->last_event_at)) {
                return $subscription->load('plan', 'price');
            }

            if (!empty($entity['customer_id']) && $user->paddle_customer_id === null) {
                $user->forceFill(['paddle_customer_id' => $entity['customer_id']])->save();
            }

            $before = $subscription->only(['plan_id', 'status', 'current_period_end']);

            if ($status === 'canceled') {
                $subscription->fill([
                    'plan_id' => SubscriptionPlan::default()->id,
                    'plan_price_id' => null,
                    'paddle_subscription_id' => null,
                    'status' => 'active',
                    'current_period_end' => null,
                    'cancel_at_period_end' => false,
                    'canceled_at' => isset($entity['canceled_at']) ? Carbon::parse($entity['canceled_at']) : now(),
                ]);
            } else {
                $price = $this->priceFor($entity['items'][0]['price']['id'] ?? null);
                $subscription->fill([
                    'plan_id' => $price->plan_id,
                    'plan_price_id' => $price->id,
                    'paddle_subscription_id' => $entity['id'],
                    'status' => $status,
                    'current_period_end' => isset($entity['current_billing_period']['ends_at'])
                        ? Carbon::parse($entity['current_billing_period']['ends_at'])
                        : null,
                    'cancel_at_period_end' => ($entity['scheduled_change']['action'] ?? null) === 'cancel',
                    'canceled_at' => null,
                ]);
            }

            $subscription->last_event_at = $version;
            $subscription->save();
            $this->recordHistory($subscription, $before, $entity['id'] ?? null);

            return $subscription->load('plan', 'price');
        });
    }

    /**
     * Upserts a Paddle transaction. $paymentStatus overrides the status derived from the entity
     * (e.g. 'failed' for transaction.payment_failed).
     */
    public function recordTransaction(array $entity, ?User $user = null, ?string $paymentStatus = null): ?SubscriptionTransaction
    {
        $user ??= $this->resolveUser($entity['custom_data'] ?? null, $entity['customer_id'] ?? null, $entity['subscription_id'] ?? null);
        if (!$user) {
            Log::warning('Paddle transaction without a matching user', ['transaction_id' => $entity['id'] ?? null]);

            return null;
        }

        $subscription = $user->ensureActiveSubscription();
        $currency = strtoupper($entity['currency_code'] ?? 'USD');
        $grandTotal = (int) ($entity['details']['totals']['grand_total'] ?? 0);
        $price = SubscriptionPlanPrice::with('plan')->where('paddle_price_id', $entity['items'][0]['price']['id'] ?? null)->first();
        $billedAt = $entity['billed_at'] ?? $entity['created_at'] ?? null;

        $paymentStatus ??= in_array($entity['status'] ?? null, ['paid', 'completed'], true) ? 'completed' : 'pending';
        $existingStatus = SubscriptionTransaction::where('paddle_transaction_id', $entity['id'])->value('payment_status');
        if ($existingStatus !== null && self::PAYMENT_STATUS_RANK[$existingStatus] > self::PAYMENT_STATUS_RANK[$paymentStatus]) {
            $paymentStatus = $existingStatus;
        }

        return SubscriptionTransaction::updateOrCreate(
            ['paddle_transaction_id' => $entity['id']],
            [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'amount' => in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true) ? $grandTotal : $grandTotal / 100,
                'currency' => $currency,
                'payment_status' => $paymentStatus,
                'billing_period_start' => isset($entity['billing_period']['starts_at'])
                    ? Carbon::parse($entity['billing_period']['starts_at'])
                    : ($billedAt ? Carbon::parse($billedAt) : now()),
                'billing_period_end' => isset($entity['billing_period']['ends_at']) ? Carbon::parse($entity['billing_period']['ends_at']) : null,
                'invoice_number' => $entity['invoice_number'] ?? null,
                'metadata' => [
                    'description' => $price?->label() ?? 'Investfolio subscription',
                    'paddle_status' => $entity['status'] ?? null,
                    'paddle_subscription_id' => $entity['subscription_id'] ?? null,
                    'origin' => $entity['origin'] ?? null,
                    'billed_at' => $billedAt,
                ],
            ]
        );
    }

    /**
     * Marks the transaction of an approved refund as refunded. A full refund also ends the subscription
     * it paid for, as the refund policy states. Returns false when not applicable.
     */
    public function applyAdjustment(array $entity): bool
    {
        if (($entity['action'] ?? null) !== 'refund' || ($entity['status'] ?? null) !== 'approved' || empty($entity['transaction_id'])) {
            return false;
        }

        $transaction = SubscriptionTransaction::where('paddle_transaction_id', $entity['transaction_id'])->first();
        if (!$transaction) {
            return false;
        }

        $transaction->update(['payment_status' => 'refunded']);

        if ($this->isFullRefund($entity, $transaction) && !empty($entity['subscription_id'])) {
            $this->cancelRefundedSubscription($entity['subscription_id'], $transaction->user);
        }

        return true;
    }

    private function isFullRefund(array $adjustment, SubscriptionTransaction $transaction): bool
    {
        if (isset($adjustment['type'])) {
            return $adjustment['type'] === 'full';
        }

        $currency = strtoupper($adjustment['currency_code'] ?? $transaction->currency);
        $refunded = (int) ($adjustment['totals']['total'] ?? 0);
        $paid = in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)
            ? (int) round($transaction->amount)
            : (int) round($transaction->amount * 100);

        return $paid > 0 && $refunded >= $paid;
    }

    private function cancelRefundedSubscription(string $paddleSubscriptionId, User $user): void
    {
        $subscription = $user->ensureActiveSubscription();
        if ($subscription->paddle_subscription_id !== $paddleSubscriptionId) {
            return;
        }

        try {
            $entity = $this->paddle->cancelImmediately($paddleSubscriptionId);
        } catch (PaddleException $e) {
            // Retried deliveries hit an already-canceled subscription; anything else is rethrown for a retry.
            $entity = $this->paddle->getSubscription($paddleSubscriptionId);
            if (($entity['status'] ?? null) !== 'canceled') {
                throw $e;
            }
        }

        $this->applySubscription($entity, $user);
    }

    private function resolveUser(?array $customData, ?string $customerId, ?string $subscriptionId): ?User
    {
        $userId = $customData['user_id'] ?? null;
        if (is_string($userId) && Str::isUuid($userId) && ($user = User::find($userId))) {
            return $user;
        }
        if ($customerId && ($user = User::where('paddle_customer_id', $customerId)->first())) {
            return $user;
        }
        if ($subscriptionId) {
            return UserSubscription::where('paddle_subscription_id', $subscriptionId)->first()?->user;
        }

        return null;
    }

    private function priceFor(?string $paddlePriceId): SubscriptionPlanPrice
    {
        $price = $paddlePriceId ? SubscriptionPlanPrice::where('paddle_price_id', $paddlePriceId)->first() : null;
        if (!$price) {
            throw new \UnexpectedValueException("Paddle price {$paddlePriceId} is not mapped to a plan price (subscription_plan_prices.paddle_price_id)");
        }

        return $price;
    }

    private function recordHistory(UserSubscription $subscription, array $before, ?string $paddleSubscriptionId): void
    {
        $planChanged = $before['plan_id'] !== $subscription->plan_id;
        if (!$planChanged && $before['status'] === $subscription->status) {
            return;
        }

        DB::table('subscription_history')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'action' => $planChanged ? 'plan_changed' : 'status_changed',
            'old_plan_id' => $before['plan_id'],
            'new_plan_id' => $subscription->plan_id,
            'old_status' => $before['status'],
            'new_status' => $subscription->status,
            'old_ends_at' => $before['current_period_end'],
            'new_ends_at' => $subscription->current_period_end,
            'metadata' => json_encode(['paddle_subscription_id' => $paddleSubscriptionId]),
            'created_at' => now(),
        ]);
    }
}
