<?php

namespace App\Http\Controllers\API;

use App\Services\MarketIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketIntelligenceController extends BaseController
{
    public function __construct(
        private MarketIntelligenceService $marketIntelligenceService,
    ) {}

    public function getDashboard(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'date' => 'required|date_format:Y-m-d',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $date = $request->get('date');
            $data = $this->marketIntelligenceService->getDashboardData($date);

            return $this->sendResponse($data, 'Market intelligence dashboard data retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving dashboard data', $e);
        }
    }

    public function getSectorDetail(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'sector_name' => 'required|string|max:255',
                'date' => 'required|date_format:Y-m-d',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $sectorName = $request->get('sector_name');
            $date = $request->get('date');

            $data = $this->marketIntelligenceService->getSectorDetail($sectorName, $date);

            return $this->sendResponse($data, 'Sector detail retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving sector detail', $e);
        }
    }

    public function getAccumulationLeaders(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'date' => 'required|date_format:Y-m-d',
                'sector_name' => 'sometimes|string|max:255',
                'state' => 'sometimes|string|in:strong_accumulation,accumulation,neutral,distribution,strong_distribution',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $date = $request->get('date');
            $sectorName = $request->get('sector_name');
            $state = $request->get('state');

            $data = $this->marketIntelligenceService->getAccumulationLeaders($date, $sectorName, $state);

            return $this->sendResponse([
                'date' => $date,
                'stocks' => $data,
                'total_count' => count($data),
            ], 'Accumulation leaders retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving accumulation leaders', $e);
        }
    }

    public function getRotationHistory(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'date' => 'required|date_format:Y-m-d',
                'days' => 'sometimes|integer|min:5|max:180',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $date = $request->get('date');
            $days = $request->get('days', 30);

            $data = $this->marketIntelligenceService->getRotationHistory($date, $days);

            return $this->sendResponse([
                'date' => $date,
                'days' => $days,
                'snapshots' => $data,
            ], 'Rotation history retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving rotation history', $e);
        }
    }

    public function getStockFlowHistory(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'symbol' => 'required|string|max:50',
                'date' => 'required|date_format:Y-m-d',
                'days' => 'sometimes|integer|min:5|max:365',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $symbol = $request->get('symbol');
            $date = $request->get('date');
            $days = $request->get('days', 60);

            $data = $this->marketIntelligenceService->getStockFlowHistory($symbol, $date, $days);

            return $this->sendResponse($data, 'Stock flow history retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stock flow history', $e);
        }
    }

    public function getStockDetail(Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'symbol' => 'required|string|max:50',
                'date' => 'required|date_format:Y-m-d',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $symbol = $request->get('symbol');
            $date = $request->get('date');

            $data = $this->marketIntelligenceService->getStockDetail($symbol, $date);

            if (isset($data['error'])) {
                return $this->notFoundResponse($data['error']);
            }

            return $this->sendResponse($data, 'Stock detail retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving stock detail', $e);
        }
    }
}
