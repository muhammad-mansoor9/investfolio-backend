<?php

namespace App\Http\Controllers\API;

use App\Services\DataIntegrity\DailyDataIntegrityService;
use App\Services\DataIntegrity\FinancialDataIntegrityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class DataIntegrityController extends BaseController
{
    public function __construct(
        private DailyDataIntegrityService $dailyService,
        private FinancialDataIntegrityService $financialService,
    ) {}

    /**
     * GET /api/data-integrity/daily
     * Get monthly data integrity report
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDailyIntegrity(Request $request): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'month' => 'sometimes|date_format:Y-m',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            // Default to current month
            $month = $request->input('month', Carbon::now()->format('Y-m'));

            // Validate month is not in the future
            $requestedMonth = Carbon::createFromFormat('Y-m', $month)->endOfMonth();
            if ($requestedMonth > Carbon::now()) {
                return $this->validationErrorResponse(['month' => ['Cannot request future month']]);
            }

            // Fetch monthly integrity report
            $data = $this->dailyService->getMonthlyIntegrity($month);

            return $this->sendResponse($data, 'Monthly data integrity report generated successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Error generating monthly integrity report', $e);
        }
    }

    /**
     * GET /api/data-integrity/daily/{date}
     * Get detailed daily integrity report
     *
     * @param Request $request
     * @param string $date
     * @return JsonResponse
     */
    public function getDailyDetails(Request $request, string $date): JsonResponse
    {
        try {
            // Validate date format
            $validator = Validator::make(
                ['date' => $date],
                ['date' => 'date_format:Y-m-d']
            );

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            // Fetch daily integrity report
            $data = $this->dailyService->getDailyIntegrity($date);

            return $this->sendResponse($data, 'Daily data integrity details retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Error retrieving daily integrity details', $e);
        }
    }

    /**
     * GET /api/data-integrity/financials
     * Get financial data integrity report
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFinancialIntegrity(Request $request): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:quarterly,annual',
                'search' => 'sometimes|string|max:20',
                'status' => 'sometimes|in:all,problems',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $type = $request->input('type', 'quarterly');
            $searchSymbol = $request->input('search');
            $statusFilter = $request->input('status', 'all') === 'problems' ? 'broken' : null;

            // Fetch financial integrity report
            $data = $this->financialService->getFinancialIntegrity($type, $searchSymbol, $statusFilter);

            return $this->sendResponse($data, 'Financial data integrity report generated successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Error generating financial integrity report', $e);
        }
    }

    /**
     * GET /api/data-integrity/financials/{stockId}/periods
     * Get detailed period history for a stock
     *
     * @param Request $request
     * @param string $stockId
     * @return JsonResponse
     */
    public function getStockPeriodHistory(Request $request, string $stockId): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make(
                array_merge(['stock_id' => $stockId], $request->all()),
                [
                    'stock_id' => 'uuid|exists:stocks,id',
                    'type' => 'sometimes|in:quarterly,annual',
                ]
            );

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $type = $request->input('type', 'quarterly');

            // Fetch period history
            $data = $this->financialService->getStockPeriodHistory($stockId, $type);

            if (isset($data['error'])) {
                return $this->sendError($data['error'], [], 404);
            }

            return $this->sendResponse($data, 'Stock period history retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Error retrieving stock period history', $e);
        }
    }

    /**
     * GET /api/data-integrity/financial-results
     * Get financial results integrity report (quarterly only)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFinancialResultsIntegrity(Request $request): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'search' => 'sometimes|string|max:20',
                'status' => 'sometimes|in:all,problems',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $searchSymbol = $request->input('search');
            $statusFilter = $request->input('status', 'all') === 'problems' ? 'broken' : null;

            // Fetch financial results integrity report
            $data = $this->financialService->getFinancialResultsIntegrity($searchSymbol, $statusFilter);

            return $this->sendResponse($data, 'Financial results integrity report generated successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Error generating financial results integrity report', $e);
        }
    }

    /**
     * GET /api/data-integrity/financial-results/{stockId}/periods
     * Get detailed period history for a stock from financial_results
     *
     * @param Request $request
     * @param string $stockId
     * @return JsonResponse
     */
    public function getFinancialResultsPeriodHistory(Request $request, string $stockId): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make(
                ['stock_id' => $stockId],
                ['stock_id' => 'uuid|exists:stocks,id']
            );

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            // Fetch period history
            $data = $this->financialService->getFinancialResultsPeriodHistory($stockId);

            if (isset($data['error'])) {
                return $this->sendError($data['error'], [], 404);
            }

            return $this->sendResponse($data, 'Financial results period history retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Error retrieving financial results period history', $e);
        }
    }

}
