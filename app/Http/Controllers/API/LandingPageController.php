<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LandingPageController extends BaseController
{
    public function newsletterSignup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed', $validator->errors(), 422);
        }

        $email = strtolower(trim($request->email));

        try {
            $subscriber = \DB::table('newsletter_subscribers')->where('email', $email)->first();

            if ($subscriber && $subscriber->is_active) {
                return $this->sendResponse([], 'You are already subscribed to our newsletter.');
            }

            \DB::table('newsletter_subscribers')->upsert(
                [[
                    'email' => $email,
                    'is_active' => true,
                    'subscribed_at' => now(),
                    'unsubscribed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['email'],
                ['is_active', 'subscribed_at', 'unsubscribed_at', 'updated_at'],
            );

            return $this->sendResponse([], 'Successfully subscribed to our newsletter!');
        } catch (\Exception $e) {
            return $this->sendError('Failed to subscribe', [], 500);
        }
    }

    public function getStats()
    {
        try {
            $stats = [
                'active_users' => \DB::table('users')->count(),
                'listed_stocks' => \DB::table('stocks')->where('is_active', true)->count(),
                'last_trading_date' => \DB::table('stock_prices')->max('date'),
            ];

            return $this->sendResponse($stats, 'Stats retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Failed to get stats', [], 500);
        }
    }
}
