<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class MansoorSpecialFilterService
{
    private const ALLOWED_EMAILS = [
        'mansoorsheikh9@gmail.com',
        'mahnoorsiddique96@gmail.com',
    ];

    public function isAuthorized(Request $request = null): bool
    {
        // Try auth guard first (standard auth)
        $user = auth()->user();
        if ($user && in_array($user->email, self::ALLOWED_EMAILS)) {
            return true;
        }

        // Try API guard (Sanctum Bearer token)
        if ($request) {
            $user = Auth::guard('api')->user();
            if ($user && in_array($user->email, self::ALLOWED_EMAILS)) {
                return true;
            }

            // If API guard didn't work, manually validate Sanctum token
            $user = $this->validateSanctumToken($request);
            if ($user && in_array($user->email, self::ALLOWED_EMAILS)) {
                return true;
            }
        }

        return false;
    }

    private function validateSanctumToken(Request $request)
    {
        $bearerToken = $this->extractBearerToken($request);
        if (!$bearerToken) {
            return null;
        }

        try {
            // Validate Sanctum token
            $token = PersonalAccessToken::findToken($bearerToken);

            if ($token && $token->user) {
                return $token->user;
            }
        } catch (\Exception $e) {
            // Token validation failed
        }

        return null;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        return substr($authHeader, 7);
    }

    public function getAuthorizationError(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Mansoor Special Criteria filter is not available for this user'
        ], 403);
    }

    public function getStocksWhereClause(): string
    {
        return "(
            (s.free_float::numeric >= (s.total_shares_outstanding::numeric * 0.15)
             AND s.free_float::numeric <= (s.total_shares_outstanding::numeric * 0.70))
            OR
            ((sp.price::numeric * s.total_shares_outstanding::numeric) > 50000000000
             AND s.free_float::numeric > (s.total_shares_outstanding::numeric * 0.70))
        )";
    }

    public function getStockPricesWhereClause(): string
    {
        return "(
            (s.free_float::numeric >= (s.total_shares_outstanding::numeric * 0.15)
             AND s.free_float::numeric <= (s.total_shares_outstanding::numeric * 0.70))
            OR
            ((lc.close::numeric * s.total_shares_outstanding::numeric) > 50000000000
             AND s.free_float::numeric > (s.total_shares_outstanding::numeric * 0.70))
        )";
    }
}
