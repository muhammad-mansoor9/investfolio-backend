<?php

namespace App\Http\Controllers\API;

use App\Models\JournalEntry;
use App\Models\JournalInsight;
use App\Services\AIAnalysisService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class JournalController extends BaseController
{
    public function getEntries(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $days = $request->query('days', 90);

            $entries = JournalEntry::where('user_id', $user->id)
                ->where('created_at', '>=', Carbon::now()->subDays($days))
                ->orderByDesc('created_at')
                ->get();

            return $this->sendResponse(
                ['entries' => $entries],
                'Journal entries retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->sendError('Failed to retrieve entries', ['error' => $e->getMessage()], 500);
        }
    }

    public function storeEntry(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'content' => 'required|string|min:1',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation failed', $validator->errors(), 422);
            }

            $user = Auth::user();
            $entry = JournalEntry::create([
                'user_id' => $user->id,
                'content' => $request->content,
            ]);

            return $this->sendResponse($entry, 'Entry created successfully', 201);
        } catch (\Exception $e) {
            return $this->sendError('Failed to create entry', ['error' => $e->getMessage()], 500);
        }
    }

    public function getInsights(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $insights = JournalInsight::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->get();

            return $this->sendResponse(
                ['insights' => $insights],
                'Journal insights retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->sendError('Failed to retrieve insights', ['error' => $e->getMessage()], 500);
        }
    }

    public function requestInsight(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'prompt' => 'required|string|min:1',
                'days' => 'nullable|integer|min:1|max:365',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation failed', $validator->errors(), 422);
            }

            $user = Auth::user();
            $days = $request->days ?? 90;
            $prompt = $request->prompt;

            // Fetch journal entries from the specified period
            $entries = JournalEntry::where('user_id', $user->id)
                ->where('created_at', '>=', Carbon::now()->subDays($days))
                ->orderBy('created_at')
                ->get();

            // Generate insight using AI service
            $aiService = new AIAnalysisService();
            $response = $aiService->analyzeJournal($entries, $prompt);

            if (!$response) {
                return $this->sendError('Failed to generate insight', ['error' => 'AI service unavailable'], 500);
            }

            // Save the insight
            $insight = JournalInsight::create([
                'user_id' => $user->id,
                'prompt' => $prompt,
                'response' => $response,
            ]);

            return $this->sendResponse($insight, 'Insight generated successfully', 201);
        } catch (\Exception $e) {
            return $this->sendError('Failed to generate insight', ['error' => $e->getMessage()], 500);
        }
    }
}
