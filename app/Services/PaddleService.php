<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class PaddleService
{
    protected $apiKey;
    protected $environment;
    protected $webhookSecret;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('paddle.api_key');
        $this->environment = config('paddle.environment', 'sandbox');
        $this->webhookSecret = config('paddle.webhook_secret');

        // Set base URL based on environment
        $this->baseUrl = $this->environment === 'production'
            ? 'https://api.paddle.com'
            : 'https://sandbox-api.paddle.com';
    }

    /**
     * Create a transaction for Paddle Billing overlay checkout
     */
    public function createTransaction(array $data)
    {
        try {
            $user = $data['user'];
            $package = $data['package'];
            $returnUrl = $data['return_url'];

            // Create or get customer
            $customer = $this->createOrGetCustomer($user);
            if (!$customer) {
                throw new \Exception('Failed to create customer');
            }

            // Create the transaction
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transactions', [
                'items' => [
                    [
                        'price_id' => $package->paddle_product_id,
                        'quantity' => 1
                    ]
                ],
                'customer_id' => $customer['id'],
                'custom_data' => [
                    'user_id' => $user->id,
                    'package_id' => $package->id,
                    'user_email' => $user->email,
                    'source' => 'chrome_extension',
                    'created_at' => now()->toISOString()
                ],
                'checkout' => [
                    'url' => $returnUrl ?: config('app.url') . '/dashboard'
                ],
                'billing_details' => [
                    'enable_checkout' => true,
                    'purchase_order_number' => 'TP-' . $user->id . '-' . time(),
                    'additional_information' => 'TickerPilot Premium Subscription'
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Paddle transaction creation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'user_id' => $user->id
                ]);
                return null;
            }

            $transactionData = $response->json();

            Log::info('Paddle transaction created', [
                'transaction_id' => $transactionData['data']['id'],
                'customer_id' => $customer['id'],
                'user_id' => $user->id
            ]);

            return [
                'transaction_id' => $transactionData['data']['id'],
                'customer_id' => $customer['id'],
                'transaction_data' => $transactionData['data']
            ];

        } catch (\Exception $e) {
            Log::error('Error creating Paddle transaction', [
                'error' => $e->getMessage(),
                'user_id' => $data['user']->id ?? null
            ]);
            return null;
        }
    }

    /**
     * Create or get existing customer
     */
    protected function createOrGetCustomer($user)
    {
        try {
            // Search for existing customer by email first
            $searchResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/customers', [
                'email' => [$user->email]
            ]);

            if ($searchResponse->successful()) {
                $searchData = $searchResponse->json();
                if (!empty($searchData['data'])) {
                    Log::info('Found existing Paddle customer', [
                        'customer_id' => $searchData['data'][0]['id'],
                        'user_id' => $user->id
                    ]);
                    return $searchData['data'][0];
                }
            }

            // Create new customer if not found
            $createResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/customers', [
                'email' => $user->email,
                'name' => $user->name,
                'custom_data' => [
                    'user_id' => $user->id,
                    'source' => 'chrome_extension',
                    'registration_date' => $user->created_at->toISOString()
                ]
            ]);

            if (!$createResponse->successful()) {
                Log::error('Failed to create Paddle customer', [
                    'status' => $createResponse->status(),
                    'body' => $createResponse->body(),
                    'user_id' => $user->id
                ]);
                return null;
            }

            $customerData = $createResponse->json();

            Log::info('Created new Paddle customer', [
                'customer_id' => $customerData['data']['id'],
                'user_id' => $user->id
            ]);

            return $customerData['data'];

        } catch (\Exception $e) {
            Log::error('Error creating/getting Paddle customer', [
                'error' => $e->getMessage(),
                'user_id' => $user->id
            ]);
            return null;
        }
    }

    /**
     * Cancel a subscription
     */
    public function cancelSubscription($subscriptionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/subscriptions/' . $subscriptionId . '/cancel', [
                'effective_from' => 'end_of_billing_period'
            ]);

            if (!$response->successful()) {
                Log::error('Failed to cancel Paddle subscription', [
                    'subscription_id' => $subscriptionId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return ['success' => false, 'error' => $response->body()];
            }

            $responseData = $response->json();

            Log::info('Paddle subscription cancelled', [
                'subscription_id' => $subscriptionId,
                'effective_from' => $responseData['data']['scheduled_change']['effective_at'] ?? null
            ]);

            return ['success' => true, 'data' => $responseData['data']];

        } catch (\Exception $e) {
            Log::error('Error cancelling Paddle subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get subscription details
     */
    public function getSubscription($subscriptionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/subscriptions/' . $subscriptionId);

            if (!$response->successful()) {
                Log::error('Failed to get Paddle subscription', [
                    'subscription_id' => $subscriptionId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            return $response->json()['data'];

        } catch (\Exception $e) {
            Log::error('Error getting Paddle subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get customer details
     */
    public function getCustomer($customerId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/customers/' . $customerId);

            if (!$response->successful()) {
                Log::error('Failed to get Paddle customer', [
                    'customer_id' => $customerId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            return $response->json()['data'];

        } catch (\Exception $e) {
            Log::error('Error getting Paddle customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Verify webhook signature - FIXED METHOD
     */
    public function verifyWebhook($payload, $signature)
    {
        try {
            if (empty($this->webhookSecret)) {
                Log::error('Paddle webhook secret not configured');
                return false;
            }

            if (empty($signature)) {
                Log::error('No signature provided in webhook');
                return false;
            }

            // Parse the signature header - Paddle format: ts=timestamp;h1=signature
            $signatures = [];
            foreach (explode(';', $signature) as $pair) {
                if (strpos($pair, '=') !== false) {
                    [$key, $value] = explode('=', $pair, 2);
                    $signatures[trim($key)] = trim($value);
                }
            }

            $timestamp = $signatures['ts'] ?? '';
            $h1 = $signatures['h1'] ?? '';

            if (empty($timestamp) || empty($h1)) {
                Log::error('Missing timestamp or signature in Paddle-Signature header', [
                    'parsed_signatures' => $signatures,
                    'raw_signature' => $signature
                ]);
                return false;
            }

            // Check timestamp to prevent replay attacks (optional but recommended)
            $currentTime = time();
            $webhookTime = (int) $timestamp;
            $timeDifference = abs($currentTime - $webhookTime);

            // Allow 5 minutes of time difference
            if ($timeDifference > 300) {
                Log::warning('Webhook timestamp too old', [
                    'webhook_time' => $webhookTime,
                    'current_time' => $currentTime,
                    'difference' => $timeDifference
                ]);
                // Uncomment the next line in production for stricter security
                // return false;
            }

            // Create the signed payload: timestamp + ':' + raw_body
            $signedPayload = $timestamp . ':' . $payload;

            // Calculate the expected signature
            $expectedSignature = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

            $isValid = hash_equals($expectedSignature, $h1);

            if (!$isValid) {
                Log::error('Signature verification failed', [
                    'expected' => $expectedSignature,
                    'received' => $h1,
                    'timestamp' => $timestamp,
                    'payload_length' => strlen($payload),
                    'webhook_secret_length' => strlen($this->webhookSecret)
                ]);
            } else {
                Log::debug('Webhook signature verified successfully', [
                    'timestamp' => $timestamp,
                    'payload_length' => strlen($payload)
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Error verifying webhook signature', [
                'error' => $e->getMessage(),
                'signature' => $signature,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Get transaction details
     */
    public function getTransaction($transactionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/transactions/' . $transactionId);

            if (!$response->successful()) {
                Log::error('Failed to get Paddle transaction', [
                    'transaction_id' => $transactionId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            return $response->json()['data'];

        } catch (\Exception $e) {
            Log::error('Error getting Paddle transaction', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get products
     */
    public function getProducts()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/products');

            if (!$response->successful()) {
                Log::error('Failed to get Paddle products', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            return $response->json()['data'];

        } catch (\Exception $e) {
            Log::error('Error getting Paddle products', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get prices for a product
     */
    public function getPrices($productId = null)
    {
        try {
            $url = $this->baseUrl . '/prices';
            $params = [];

            if ($productId) {
                $params['product_id'] = $productId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($url, $params);

            if (!$response->successful()) {
                Log::error('Failed to get Paddle prices', [
                    'product_id' => $productId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            return $response->json()['data'];

        } catch (\Exception $e) {
            Log::error('Error getting Paddle prices', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
};
