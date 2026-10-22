<?php

namespace App\Http\Controllers\API;

use App\Exceptions\AIProviderException;
use App\Models\UserAiSetting;
use App\Services\AIAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AiSettingsController extends BaseController
{
    public function show(Request $request): JsonResponse
    {
        return $this->sendResponse($this->payload(UserAiSetting::find($request->user()->id)), 'AI settings retrieved');
    }

    public function update(Request $request): JsonResponse
    {
        $validator = $this->validator($request);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user = $request->user();
        $existing = UserAiSetting::find($user->id);
        $apiKey = $this->resolveApiKey($request, $existing);
        if ($apiKey === null) {
            return $this->validationErrorResponse(['api_key' => ['An API key is required for this provider.']]);
        }

        $settings = UserAiSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'provider' => $request->provider,
                'model' => $request->filled('model') ? trim($request->model) : null,
                'api_key' => $apiKey,
            ]
        );

        return $this->sendResponse($this->payload($settings), 'AI settings saved');
    }

    public function destroy(Request $request): JsonResponse
    {
        UserAiSetting::where('user_id', $request->user()->id)->delete();

        return $this->deletedResponse('AI settings removed');
    }

    public function test(Request $request): JsonResponse
    {
        $validator = $this->validator($request);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $apiKey = $this->resolveApiKey($request, UserAiSetting::find($request->user()->id));
        if ($apiKey === null) {
            return $this->validationErrorResponse(['api_key' => ['An API key is required for this provider.']]);
        }

        try {
            (new AIAnalysisService($request->provider, $apiKey, $request->model))->testConnection();
        } catch (AIProviderException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->sendResponse(null, 'Connection successful');
    }

    public function models(Request $request): JsonResponse
    {
        $validator = $this->validator($request);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $apiKey = $this->resolveApiKey($request, UserAiSetting::find($request->user()->id));
        if ($apiKey === null) {
            return $this->validationErrorResponse(['api_key' => ['An API key is required for this provider.']]);
        }

        try {
            $models = (new AIAnalysisService($request->provider, $apiKey))->availableModels();
        } catch (AIProviderException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->sendResponse($models, 'Models retrieved');
    }

    private function validator(Request $request)
    {
        return Validator::make($request->all(), [
            'provider' => 'required|string|in:' . implode(',', array_keys(AIAnalysisService::PROVIDERS)),
            'model' => 'nullable|string|max:150',
            'api_key' => 'nullable|string|min:8|max:500',
        ]);
    }

    // A stored key is only reused for the provider it was issued by.
    private function resolveApiKey(Request $request, ?UserAiSetting $existing): ?string
    {
        if ($request->filled('api_key')) {
            return trim($request->api_key);
        }

        return $existing && $existing->provider === $request->provider ? $existing->api_key : null;
    }

    private function payload(?UserAiSetting $settings): array
    {
        $providers = collect(AIAnalysisService::PROVIDERS)
            ->map(fn ($config, $id) => [
                'id' => $id,
                'label' => $config['label'],
                'key_url' => $config['key_url'],
            ])
            ->values();

        return [
            'configured' => (bool) $settings,
            'provider' => $settings?->provider,
            'model' => $settings?->model,
            'api_key_hint' => $settings ? '••••' . substr($settings->api_key, -4) : null,
            'updated_at' => $settings?->updated_at,
            'providers' => $providers,
        ];
    }
}
