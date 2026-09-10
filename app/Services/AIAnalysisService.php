<?php

namespace App\Services;

use Illuminate\Support\Collection;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class AIAnalysisService
{
    private Client $client;
    private ?string $apiKey;

    public function __construct()
    {
        $this->client = new Client();
        $this->apiKey = env('GROQ_API_KEY');
    }

    public function analyzeJournal(Collection $entries, string $prompt): ?string
    {
        if (!$this->apiKey) {
            Log::warning('Groq API key not configured');
            return null;
        }

        if ($entries->isEmpty()) {
            return "No journal entries found for the specified period. Please add some entries first to get insights.";
        }

        $journalText = $entries
            ->map(fn($entry) => $entry->created_at->format('Y-m-d H:i') . ': ' . $entry->content)
            ->join("\n\n");

        return $this->callGroq($journalText, $prompt);
    }

    private function callGroq(string $journalText, string $userPrompt): ?string
    {
        try {
            $response = $this->client->post('https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'mixtral-8x7b-32768',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a trading journal analyst. Analyze the user\'s trading journal entries and provide insights about their trading patterns, decisions, and behavior. Be specific about patterns you observe. Distinguish between recorded facts and your interpretations. Do not invent missing information. Keep responses concise and actionable.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Here is my trading journal:\n\n{$journalText}\n\nMy question: {$userPrompt}",
                        ],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 1024,
                ],
                'timeout' => 30,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['choices'][0]['message']['content'])) {
                return $body['choices'][0]['message']['content'];
            }

            Log::warning('Unexpected Groq response format', $body);
            return null;
        } catch (\Exception $e) {
            Log::error('Groq API error', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
