<?php

namespace App\Http\Controllers\API;

use App\Exceptions\AIProviderException;
use App\Models\JournalInsight;
use App\Services\AIAnalysisService;
use App\Services\EntitlementService;
use App\Services\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class JournalController extends BaseController
{
    public function __construct(private JournalService $journal, private EntitlementService $entitlements)
    {
    }

    public function getEntries(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'tags', 'tag_match', 'start_date', 'end_date', 'days', 'limit']);
            if (is_string($filters['tags'] ?? null)) {
                $filters['tags'] = array_filter(explode(',', $filters['tags']));
            }
            if (empty($filters['start_date']) && empty($filters['end_date']) && empty($filters['days'])) {
                $filters['days'] = 90;
            }

            $result = $this->journal->search(Auth::user(), $filters);

            return $this->sendResponse(
                ['entries' => $result['entries'], 'total' => $result['total']],
                'Journal entries retrieved successfully'
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return $this->sendError('Failed to retrieve entries', ['error' => $e->getMessage()], 500);
        }
    }

    public function storeEntry(Request $request): JsonResponse
    {
        try {
            $entry = $this->journal->create(Auth::user(), $request->only(['content', 'tags']));

            return $this->sendResponse($entry, 'Entry created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return $this->sendError('Failed to create entry', ['error' => $e->getMessage()], 500);
        }
    }

    public function updateEntry(Request $request, string $id): JsonResponse
    {
        try {
            $entry = $this->journal->update(Auth::user(), $id, $request->only(['content', 'tags']));
            if (!$entry) {
                return $this->notFoundResponse('Journal entry not found');
            }

            return $this->sendResponse($entry, 'Entry updated successfully');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return $this->sendError('Failed to update entry', ['error' => $e->getMessage()], 500);
        }
    }

    public function deleteEntry(string $id): JsonResponse
    {
        try {
            if (!$this->journal->delete(Auth::user(), $id)) {
                return $this->notFoundResponse('Journal entry not found');
            }

            return $this->deletedResponse('Entry deleted successfully');
        } catch (\Exception $e) {
            return $this->sendError('Failed to delete entry', ['error' => $e->getMessage()], 500);
        }
    }

    public function getTags(): JsonResponse
    {
        return $this->sendResponse(['tags' => $this->journal->tags(Auth::user())], 'Journal tags retrieved successfully');
    }

    public function getTagSuggestions(): JsonResponse
    {
        return $this->sendResponse(['suggestions' => $this->journal->tagSuggestions()], 'Tag suggestions retrieved successfully');
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
        $validator = Validator::make($request->all(), [
            'prompt' => 'required|string|min:1',
            'days' => 'nullable|integer|min:1|max:365',
            'tags' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user = Auth::user();
        $this->entitlements->assertCanUse($user, 'journal_ai_insights');

        try {
            $aiService = AIAnalysisService::forUser($user);

            $entries = $this->journal->search($user, [
                'days' => $request->days ?? 90,
                'tags' => $request->tags,
                'order' => 'asc',
            ])['entries'];

            $response = $aiService->analyzeJournal($entries, $request->prompt);

            $insight = JournalInsight::create([
                'user_id' => $user->id,
                'prompt' => $request->prompt,
                'response' => $response,
            ]);
            $this->entitlements->recordUsage($user, 'journal_ai_insights');

            return $this->sendResponse($insight, 'Insight generated successfully', 201);
        } catch (AIProviderException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('Journal insight failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->sendError('Failed to generate insight', ['error' => $e->getMessage()], 500);
        }
    }
}
