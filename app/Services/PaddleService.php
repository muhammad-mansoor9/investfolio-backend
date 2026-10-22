<?php

namespace App\Services;

use App\Exceptions\PaddleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaddleService
{
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function getTransaction(string $transactionId): array
    {
        return $this->send('GET', "/transactions/{$transactionId}");
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->send('GET', "/subscriptions/{$subscriptionId}");
    }

    public function cancelAtPeriodEnd(string $subscriptionId): array
    {
        return $this->send('POST', "/subscriptions/{$subscriptionId}/cancel", ['effective_from' => 'next_billing_period']);
    }

    public function cancelImmediately(string $subscriptionId): array
    {
        return $this->send('POST', "/subscriptions/{$subscriptionId}/cancel", ['effective_from' => 'immediately']);
    }

    public function resumeImmediately(string $subscriptionId): array
    {
        return $this->send('POST', "/subscriptions/{$subscriptionId}/resume", ['effective_from' => 'immediately']);
    }

    public function removeScheduledChange(string $subscriptionId): array
    {
        return $this->send('PATCH', "/subscriptions/{$subscriptionId}", ['scheduled_change' => null]);
    }

    public function changePrice(string $subscriptionId, string $paddlePriceId): array
    {
        return $this->send('PATCH', "/subscriptions/{$subscriptionId}", [
            'items' => [['price_id' => $paddlePriceId, 'quantity' => 1]],
            'proration_billing_mode' => 'prorated_immediately',
        ]);
    }

    public function createPortalSession(string $customerId, array $subscriptionIds): string
    {
        $data = $this->send('POST', "/customers/{$customerId}/portal-sessions", ['subscription_ids' => array_values($subscriptionIds)]);
        $url = $data['urls']['general']['overview'] ?? null;
        if (!$url) {
            throw new PaddleException('Paddle did not return a customer portal link.');
        }

        return $url;
    }

    public function getInvoiceUrl(string $transactionId): string
    {
        $url = $this->send('GET', "/transactions/{$transactionId}/invoice")['url'] ?? null;
        if (!$url) {
            throw new PaddleException('Paddle did not return an invoice link.');
        }

        return $url;
    }

    /**
     * Verifies a Paddle-Signature header ("ts=…;h1=…"; h1 may repeat during secret rotation).
     */
    public function verifyWebhookSignature(string $rawBody, ?string $header): bool
    {
        $secret = (string) config('paddle.webhook_secret');
        if ($secret === '' || !$header) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(';', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 'ts') {
                $timestamp = $value;
            } elseif ($key === 'h1') {
                $signatures[] = $value;
            }
        }

        if (!ctype_digit((string) $timestamp) || !$signatures || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}:{$rawBody}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function send(string $method, string $path, ?array $body = null): array
    {
        $apiKey = config('paddle.api_key');
        if (!$apiKey) {
            throw new PaddleException('Billing is not configured.');
        }

        $baseUrl = config('paddle.environment') === 'production' ? 'https://api.paddle.com' : 'https://sandbox-api.paddle.com';

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->acceptJson()
                ->timeout(15)
                ->send($method, $path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            Log::warning('Paddle unreachable', ['path' => $path, 'message' => $e->getMessage()]);
            throw new PaddleException('Could not reach the billing provider. Please try again.');
        }

        if ($response->failed()) {
            Log::warning('Paddle request failed', ['path' => $path, 'status' => $response->status(), 'error' => $response->json('error.code')]);
            throw new PaddleException($response->json('error.detail') ?: "Billing request failed (HTTP {$response->status()}).", $response->status());
        }

        return $response->json('data') ?? [];
    }
}
