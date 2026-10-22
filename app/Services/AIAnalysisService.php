<?php

namespace App\Services;

use App\Exceptions\AIProviderException;
use App\Models\User;
use App\Models\UserAiSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AIAnalysisService
{
    /**
     * Models are read live from each provider, so new releases appear without code changes.
     * "models" filters the provider's list down to text-chat models; "recommended" picks the default
     * as the newest model of a stable family (cheap and capable enough for journal insights).
     * "fallback_model" is used only when the provider's list can't be read.
     */
    public const PROVIDERS = [
        'openai' => [
            'label' => 'OpenAI',
            'api' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'key_url' => 'https://platform.openai.com/api-keys',
            'models' => '/^gpt-\d+(\.\d+)?(-mini|-nano)?$/',
            'recommended' => '/-mini$/',
            'fallback_model' => 'gpt-4.1-mini',
        ],
        'anthropic' => [
            'label' => 'Anthropic (Claude)',
            'api' => 'anthropic',
            'base_url' => 'https://api.anthropic.com/v1',
            'key_url' => 'https://console.anthropic.com/settings/keys',
            'models' => '/^claude-/',
            'recommended' => '/haiku/',
            'fallback_model' => 'claude-haiku-5-5',
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'api' => 'gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'key_url' => 'https://aistudio.google.com/app/apikey',
            'models' => '/^gemini-\d+(\.\d+)?-(flash-lite|flash|pro)$/',
            'recommended' => '/-flash$/',
            'fallback_model' => 'gemini-2.5-flash',
        ],
        'groq' => [
            'label' => 'Groq',
            'api' => 'openai',
            'base_url' => 'https://api.groq.com/openai/v1',
            'key_url' => 'https://console.groq.com/keys',
            'models' => '/^(?!.*(whisper|tts|guard|embed|playai|orpheus|distil|compound)).+$/i',
            'recommended' => '/^llama-.*-versatile$/',
            'fallback_model' => 'llama-3.3-70b-versatile',
        ],
        // OpenRouter lists hundreds of models; its own router picks one per request instead.
        'openrouter' => [
            'label' => 'OpenRouter',
            'api' => 'openai',
            'base_url' => 'https://openrouter.ai/api/v1',
            'key_url' => 'https://openrouter.ai/keys',
            'models' => null,
            'recommended' => null,
            'fallback_model' => 'openrouter/auto',
        ],
    ];

    private const MODEL_LIST_TTL = 21600;

    private const SYSTEM_PROMPT = 'You are a trading journal analyst. Analyze the user\'s trading journal entries and provide insights about their trading patterns, decisions, and behavior. Be specific about patterns you observe. Distinguish between recorded facts and your interpretations. Do not invent missing information. Keep responses concise and actionable.';

    // Generous because reasoning models spend part of this budget thinking before they answer; only used tokens are billed.
    private const MAX_OUTPUT_TOKENS = 8192;
    private const TEST_OUTPUT_TOKENS = 1024;

    private Client $client;

    private array $config;

    private ?string $model;

    public function __construct(private string $provider, private string $apiKey, ?string $model = null)
    {
        if (!isset(self::PROVIDERS[$provider])) {
            throw new AIProviderException("Unsupported AI provider: {$provider}");
        }

        $this->config = self::PROVIDERS[$provider];
        $this->model = $model ?: null;
        $this->client = new Client(['timeout' => 60, 'http_errors' => false]);
    }

    public static function forUser(User $user): self
    {
        $settings = UserAiSetting::find($user->id);
        if (!$settings || !$settings->api_key) {
            throw new AIProviderException('No AI provider configured. Add your provider and API key in Settings.');
        }

        return new self($settings->provider, $settings->api_key, $settings->model);
    }

    public function analyzeJournal(Collection $entries, string $prompt): string
    {
        if ($entries->isEmpty()) {
            return 'No journal entries found for the specified period. Please add some entries first to get insights.';
        }

        $journalText = $entries
            ->map(function ($entry) {
                $tags = $entry->tags ? ' [' . implode(', ', $entry->tags) . ']' : '';

                return $entry->created_at->format('Y-m-d H:i') . $tags . ': ' . $entry->content;
            })
            ->join("\n\n");

        return $this->complete(self::SYSTEM_PROMPT, "Here is my trading journal:\n\n{$journalText}\n\nMy question: {$prompt}");
    }

    public function testConnection(): void
    {
        $this->complete('Reply with the single word OK.', 'Connection test', self::TEST_OUTPUT_TOKENS);
    }

    /**
     * Chat models this key can use, newest first, and the one used when the user hasn't picked a model.
     *
     * @return array{recommended: string, models: array<int, array{id: string, label: string}>}
     */
    public function availableModels(): array
    {
        if ($this->config['models'] === null) {
            return ['recommended' => $this->config['fallback_model'], 'models' => []];
        }

        $models = Cache::remember(
            "ai_models:{$this->provider}:" . hash('sha256', $this->apiKey),
            self::MODEL_LIST_TTL,
            fn () => $this->fetchModels()
        );

        $ids = array_column($models, 'id');
        $recommended = collect($ids)->first(fn ($id) => preg_match($this->config['recommended'], $id))
            ?? (in_array($this->config['fallback_model'], $ids, true) ? $this->config['fallback_model'] : null)
            ?? $ids[0]
            ?? $this->config['fallback_model'];

        return ['recommended' => $recommended, 'models' => $models];
    }

    private function resolvedModel(): string
    {
        if ($this->model === null) {
            try {
                $this->model = $this->availableModels()['recommended'];
            } catch (AIProviderException) {
                $this->model = $this->config['fallback_model'];
            }
        }

        return $this->model;
    }

    private function fetchModels(): array
    {
        [$url, $headers] = match ($this->config['api']) {
            'anthropic' => [$this->config['base_url'] . '/models?limit=1000', ['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01']],
            'gemini' => [$this->config['base_url'] . '/models?pageSize=1000', ['x-goog-api-key' => $this->apiKey]],
            default => [$this->config['base_url'] . '/models', ['Authorization' => "Bearer {$this->apiKey}"]],
        };

        try {
            $response = $this->client->get($url, ['headers' => $headers, 'timeout' => 15]);
        } catch (GuzzleException $e) {
            Log::warning('AI provider unreachable', ['provider' => $this->provider, 'message' => $e->getMessage()]);

            throw new AIProviderException("Could not reach {$this->config['label']}. Please try again.");
        }

        $json = json_decode((string) $response->getBody(), true) ?: [];
        if ($response->getStatusCode() >= 400) {
            throw new AIProviderException($this->describeError($response->getStatusCode(), $json));
        }

        $models = match ($this->config['api']) {
            'gemini' => collect($json['models'] ?? [])
                ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
                ->map(fn ($m) => ['id' => str_replace('models/', '', $m['name']), 'label' => $m['displayName'] ?? null, 'created' => 0]),
            'anthropic' => collect($json['data'] ?? [])
                ->map(fn ($m) => ['id' => $m['id'], 'label' => $m['display_name'] ?? null, 'created' => strtotime($m['created_at'] ?? '') ?: 0]),
            default => collect($json['data'] ?? [])
                ->reject(fn ($m) => ($m['active'] ?? true) === false)
                ->map(fn ($m) => ['id' => $m['id'], 'label' => null, 'created' => (int) ($m['created'] ?? 0)]),
        };

        // Gemini lists no release dates, so its version number (2.5, 3.0, ...) orders it instead.
        return $models
            ->filter(fn ($m) => preg_match($this->config['models'], $m['id']))
            ->sort(fn ($a, $b) => [$b['created'], self::versionOf($b['id'])] <=> [$a['created'], self::versionOf($a['id'])])
            ->map(fn ($m) => ['id' => $m['id'], 'label' => $m['label'] ?: self::prettify($m['id'])])
            ->values()
            ->all();
    }

    private static function versionOf(string $id): float
    {
        return preg_match('/(\d+(?:\.\d+)?)/', $id, $m) ? (float) $m[1] : 0.0;
    }

    private static function prettify(string $id): string
    {
        return preg_replace(['/^gpt-/', '/-(mini|nano)$/'], ['GPT-', ' $1'], $id);
    }

    private function complete(string $system, string $user, int $maxTokens = self::MAX_OUTPUT_TOKENS): string
    {
        [$url, $headers, $body] = match ($this->config['api']) {
            'anthropic' => $this->anthropicRequest($system, $user, $maxTokens),
            'gemini' => $this->geminiRequest($system, $user, $maxTokens),
            default => $this->openAiRequest($system, $user, $maxTokens),
        };

        try {
            $response = $this->client->post($url, [
                'headers' => $headers + ['Content-Type' => 'application/json'],
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::warning('AI provider unreachable', ['provider' => $this->provider, 'message' => $e->getMessage()]);

            throw new AIProviderException("Could not reach {$this->config['label']}. Please try again.");
        }

        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true) ?: [];

        if ($status >= 400) {
            throw new AIProviderException($this->describeError($status, $json));
        }

        $text = match ($this->config['api']) {
            'anthropic' => collect($json['content'] ?? [])->where('type', 'text')->pluck('text')->implode(''),
            'gemini' => collect($json['candidates'][0]['content']['parts'] ?? [])->pluck('text')->implode(''),
            default => $json['choices'][0]['message']['content'] ?? '',
        };

        if (trim($text) === '') {
            Log::warning('Empty AI provider response', ['provider' => $this->provider, 'model' => $this->model]);

            throw new AIProviderException("{$this->config['label']} returned an empty response for model '{$this->model}'.");
        }

        return $text;
    }

    // OpenAI's newer models reject max_tokens, while some OpenAI-compatible hosts only accept it.
    private function openAiRequest(string $system, string $user, int $maxTokens): array
    {
        $tokenField = $this->provider === 'openai' ? 'max_completion_tokens' : 'max_tokens';

        return [
            $this->config['base_url'] . '/chat/completions',
            ['Authorization' => "Bearer {$this->apiKey}"],
            [
                'model' => $this->resolvedModel(),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                $tokenField => $maxTokens,
            ],
        ];
    }

    private function anthropicRequest(string $system, string $user, int $maxTokens): array
    {
        return [
            $this->config['base_url'] . '/messages',
            ['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'],
            [
                'model' => $this->resolvedModel(),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ],
        ];
    }

    private function geminiRequest(string $system, string $user, int $maxTokens): array
    {
        return [
            $this->config['base_url'] . '/models/' . rawurlencode($this->resolvedModel()) . ':generateContent',
            ['x-goog-api-key' => $this->apiKey],
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                'generationConfig' => ['maxOutputTokens' => $maxTokens],
            ],
        ];
    }

    private function describeError(int $status, array $json): string
    {
        $label = $this->config['label'];
        $detail = $json['error']['message'] ?? (is_string($json['error'] ?? null) ? $json['error'] : null);

        $message = match (true) {
            $status === 401, $status === 403 => "{$label} rejected the API key. Check it in Settings.",
            $status === 404 => "{$label} doesn't offer the selected model ({$this->model}) on your account. Choose another model in Settings.",
            $status === 429 => "{$label} says your account is out of credit or has hit its usage limit. Check your billing with {$label}, or try again in a minute.",
            default => "{$label} request failed (HTTP {$status}).",
        };

        return $detail ? "{$message} Provider said: " . mb_strimwidth($detail, 0, 300, '…') : $message;
    }
}
