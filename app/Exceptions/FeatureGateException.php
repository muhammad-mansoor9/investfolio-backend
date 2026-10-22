<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * The user's plan doesn't include a feature, or its limit is used up. Rendered as the 403 body the
 * frontend reads to open the upgrade dialog (FeatureGateError in psx-react/lib/types).
 */
class FeatureGateException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly string $feature,
        public readonly ?int $limit = null,
        public readonly ?int $used = null,
    ) {
        parent::__construct($message);
    }

    public function report(): void
    {
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => [
                'code' => $this->reason,
                'feature' => $this->feature,
                'limit' => $this->limit,
                'used' => $this->used,
            ],
        ], 403);
    }
}
