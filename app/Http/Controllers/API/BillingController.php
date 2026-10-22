<?php

namespace App\Http\Controllers\API;

use App\Exceptions\DuplicateSubscriptionException;
use App\Exceptions\PaddleException;
use App\Models\SubscriptionPlanPrice;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\EntitlementService;
use App\Services\PaddleService;
use App\Services\SubscriptionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends BaseController
{
    public function __construct(
        private PaddleService $paddle,
        private SubscriptionSyncService $sync,
        private EntitlementService $entitlements,
    ) {
    }

    public function subscription(Request $request): JsonResponse
    {
        return $this->sendResponse($this->subscriptionPayload($request->user()), 'Subscription retrieved');
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate(['price_id' => 'required|uuid']);
        $user = $request->user();

        if ($user->email_verified_at === null) {
            return $this->errorResponse('Verify your email before upgrading.', 422);
        }

        $price = SubscriptionPlanPrice::with('plan')->find($data['price_id']);
        if (!$price || !$price->isPurchasable()) {
            return $this->errorResponse('This plan is not available for purchase.', 422);
        }

        $subscription = $user->ensureActiveSubscription();
        if ($subscription->hasPaddleSubscription()) {
            $message = $subscription->isPaused()
                ? 'Your subscription is paused. Resume it from the billing page.'
                : 'You already have a paid subscription. Switch plans from the billing page instead.';

            return $this->errorResponse($message, 409);
        }

        return $this->sendResponse([
            'paddle_price_id' => $price->paddle_price_id,
            'customer' => $user->paddle_customer_id ? ['id' => $user->paddle_customer_id] : ['email' => $user->email],
            'custom_data' => ['user_id' => $user->id],
        ], 'Checkout ready');
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate(['transaction_id' => ['required', 'string', 'regex:/^txn_[a-z0-9]+$/']]);
        $user = $request->user();

        return $this->withPaddle(function () use ($data, $user) {
            $transaction = $this->paddle->getTransaction($data['transaction_id']);

            $ownsTransaction = ($transaction['custom_data']['user_id'] ?? null) === $user->id
                || ($user->paddle_customer_id !== null && ($transaction['customer_id'] ?? null) === $user->paddle_customer_id);
            if (!$ownsTransaction) {
                return $this->forbiddenResponse('This transaction belongs to another account.');
            }

            try {
                $this->sync->recordTransaction($transaction, $user);
                if (!empty($transaction['subscription_id'])) {
                    $this->sync->applySubscription($this->paddle->getSubscription($transaction['subscription_id']), $user);
                }
            } catch (DuplicateSubscriptionException) {
                return $this->errorResponse(
                    'This payment started a second subscription; your existing one is unchanged. Contact support@investfolio.pk to refund the duplicate.',
                    409
                );
            }

            return $this->sendResponse($this->subscriptionPayload($user->refresh()), 'Subscription updated');
        }, 'Payment received — activation is pending; it will complete automatically shortly.');
    }

    public function portal(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->paddle_customer_id) {
            return $this->errorResponse('You have no billing account yet.', 422);
        }

        $subscriptionId = $user->ensureActiveSubscription()->paddle_subscription_id;

        return $this->withPaddle(fn () => $this->sendResponse([
            'url' => $this->paddle->createPortalSession($user->paddle_customer_id, array_filter([$subscriptionId])),
        ], 'Portal session created'));
    }

    public function cancel(Request $request): JsonResponse
    {
        // A paused subscription has no upcoming billing period to cancel at, so it ends now.
        return $this->onPaidSubscription($request->user(), fn (UserSubscription $subscription) => $subscription->isPaused()
            ? $this->paddle->cancelImmediately($subscription->paddle_subscription_id)
            : $this->paddle->cancelAtPeriodEnd($subscription->paddle_subscription_id));
    }

    public function resume(Request $request): JsonResponse
    {
        return $this->onPaidSubscription($request->user(), function (UserSubscription $subscription) {
            if ($subscription->isPaused()) {
                return $this->paddle->resumeImmediately($subscription->paddle_subscription_id);
            }
            if (!$subscription->cancel_at_period_end) {
                return null;
            }

            return $this->paddle->removeScheduledChange($subscription->paddle_subscription_id);
        });
    }

    public function changePlan(Request $request): JsonResponse
    {
        $data = $request->validate(['price_id' => 'required|uuid']);
        $price = SubscriptionPlanPrice::with('plan')->find($data['price_id']);
        if (!$price || !$price->isPurchasable()) {
            return $this->errorResponse('This plan is not available.', 422);
        }

        if ($request->user()->ensureActiveSubscription()->isPaused()) {
            return $this->errorResponse('Resume your subscription before changing plans.', 422);
        }

        return $this->onPaidSubscription($request->user(), function (UserSubscription $subscription) use ($price) {
            if ($subscription->plan_price_id === $price->id) {
                return null;
            }

            return $this->paddle->changePrice($subscription->paddle_subscription_id, $price->paddle_price_id);
        });
    }

    public function transactions(Request $request): JsonResponse
    {
        $transactions = SubscriptionTransaction::where('user_id', $request->user()->id)
            ->orderByDesc('billing_period_start')
            ->limit(50)
            ->get()
            ->map(fn (SubscriptionTransaction $transaction) => [
                'id' => $transaction->id,
                'amount' => $transaction->amount,
                'currency' => trim($transaction->currency),
                'status' => $transaction->payment_status,
                'billed_at' => ($transaction->metadata['billed_at'] ?? null) ?: $transaction->billing_period_start->toIso8601String(),
                'invoice_number' => $transaction->invoice_number,
                'description' => $transaction->metadata['description'] ?? 'Investfolio subscription',
            ]);

        return $this->sendResponse(['transactions' => $transactions], 'Transactions retrieved');
    }

    public function invoice(Request $request, string $id): JsonResponse
    {
        $transaction = SubscriptionTransaction::where('user_id', $request->user()->id)->find($id);
        if (!$transaction) {
            return $this->notFoundResponse('Transaction not found.');
        }

        return $this->withPaddle(fn () => $this->sendResponse(['url' => $this->paddle->getInvoiceUrl($transaction->paddle_transaction_id)], 'Invoice link created'));
    }

    /**
     * Runs a Paddle subscription mutation and applies the entity Paddle returns, so the response
     * reflects the new state before the webhook arrives. $action may return null for a no-op.
     */
    private function onPaidSubscription(User $user, callable $action): JsonResponse
    {
        $subscription = $user->ensureActiveSubscription();
        if (!$subscription->hasPaddleSubscription()) {
            return $this->errorResponse('You are on the free plan.', 422);
        }

        return $this->withPaddle(function () use ($user, $subscription, $action) {
            $entity = $action($subscription);
            if ($entity) {
                $this->sync->applySubscription($entity, $user);
            }

            return $this->sendResponse($this->subscriptionPayload($user->refresh()), 'Subscription updated');
        });
    }

    /**
     * $unappliedMessage is returned when Paddle succeeded but its entity can't be mapped locally
     * (e.g. a price missing from subscription_plan_prices); the webhook retries until it's fixed.
     */
    private function withPaddle(callable $callback, string $unappliedMessage = 'Your change was accepted and will appear here shortly.'): JsonResponse
    {
        try {
            return $callback();
        } catch (PaddleException $e) {
            return $this->errorResponse($e->getMessage(), 502);
        } catch (\UnexpectedValueException $e) {
            Log::error('Paddle entity could not be applied', ['error' => $e->getMessage()]);

            return $this->errorResponse($unappliedMessage, 502);
        }
    }

    private function subscriptionPayload(User $user): array
    {
        $subscription = $user->ensureActiveSubscription()->load('plan', 'price');
        $price = $subscription->price;

        return [
            'plan' => [
                'id' => $subscription->plan->id,
                'slug' => $subscription->plan->slug,
                'name' => $subscription->plan->name,
                'is_default' => $subscription->plan->is_default,
            ],
            'status' => $subscription->status,
            'billing_cycle' => $price?->billing_cycle,
            'price' => $price?->toApiArray(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'canceled_at' => $subscription->canceled_at?->toIso8601String(),
            'is_paid' => $subscription->hasPaddleSubscription(),
            'entitlements' => $this->entitlements->entitlements($user),
        ];
    }
}
