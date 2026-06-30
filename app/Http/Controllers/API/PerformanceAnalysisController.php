<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PerformanceAnalysisController extends Controller
{
    protected $performanceService;

    public function __construct(PerformanceService $performanceService)
    {
        $this->performanceService = $performanceService;
    }

    /**
     * Get performance analysis for a sector
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPerformanceData(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'sector_id' => 'required|uuid|exists:sectors,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $sectorId = $request->input('sector_id');
            $result = $this->performanceService->getSectorPerformance($sectorId);

            return response()->json([
                'success' => true,
                'message' => 'Performance data retrieved successfully',
                'data' => $result,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sector not found',
                'errors' => 'The specified sector does not exist',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch performance data',
                'errors' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'debug' => config('app.debug') ? [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                ] : null
            ], 500);
        }
    }
}
