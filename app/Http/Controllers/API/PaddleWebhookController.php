<?php

namespace App\Http\Controllers\API;

use App\Exceptions\DuplicateSubscriptionException;
use App\Models\PaddleWebhookEvent;
use App\Services\PaddleService;
use App\Services\SubscriptionSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaddleWebhookController extends BaseController
{
    public function __construct(private PaddleService $paddle, private SubscriptionSyncService $sync)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        if (!$this->paddle->verifyWebhookSignature($rawBody, $request->header('Paddle-Signature'))) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || empty($payload['event_id']) || empty($payload['event_type']) || !is_array($payload['data'] ?? null)) {
            return response()->json(['error' => 'Malformed event'], 400);
        }

        $event = PaddleWebhookEvent::createOrFirst(['event_id' => $payload['event_id']], [
            'event_type' => $payload['event_type'],
            'occurred_at' => isset($payload['occurred_at']) ? Carbon::parse($payload['occurred_at']) : now(),
            'payload' => $payload,
            'status' => 'received',
        ]);

        if (in_array($event->status, ['processed', 'ignored'], true)) {
            return response()->json(['received' => true]);
        }

        try {
            $handled = DB::transaction(fn () => $this->process($payload['event_type'], $payload['data']));
            $event->update(['status' => $handled ? 'processed' : 'ignored', 'error' => null, 'processed_at' => now()]);
        } catch (DuplicateSubscriptionException $e) {
            // Retrying can't fix a double purchase; keep it visible for support to refund.
            $event->update(['status' => 'ignored', 'error' => $e->getMessage(), 'processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Paddle webhook processing failed', ['event_id' => $payload['event_id'], 'type' => $payload['event_type'], 'error' => $e->getMessage()]);
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            // A non-2xx response makes Paddle retry the delivery.
            return response()->json(['error' => 'Processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function process(string $type, array $data): bool
    {
        if (str_starts_with($type, 'subscription.')) {
            return $this->sync->applySubscription($data) !== null;
        }

        return match ($type) {
            'transaction.completed' => $this->sync->recordTransaction($data) !== null,
            'transaction.payment_failed' => $this->sync->recordTransaction($data, null, 'failed') !== null,
            'adjustment.created', 'adjustment.updated' => $this->sync->applyAdjustment($data),
            default => false,
        };
    }
}
