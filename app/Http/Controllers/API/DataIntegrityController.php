<?php

namespace App\Http\Controllers\API;

use App\Services\DataIntegrity\DailyDataIntegrityService;
use App\Services\DataIntegrity\FinancialDataIntegrityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DataIntegrityController extends BaseController
{
    public function __construct(
        private DailyDataIntegrityService $dailyService,
        private FinancialDataIntegrityService $financialService,
    ) {}

    public function getDailyIntegrity(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'month' => 'sometimes|date_format:Y-m',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $currentMonth = Carbon::now('Asia/Karachi')->format('Y-m');
        $month = $request->input('month', $currentMonth);

        // Y-m strings compare chronologically.
        if ($month > $currentMonth) {
            return $this->validationErrorResponse(['month' => ['Cannot request a future month']]);
        }

        try {
            return $this->sendResponse($this->dailyService->getMonth($month), 'Daily data integrity report generated successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Error generating daily integrity report', $e);
        }
    }

    public function getFinancialIntegrity(): JsonResponse
    {
        return $this->financialReport(FinancialDataIntegrityService::SOURCE_FINANCIAL_DATA);
    }

    public function getFinancialResultsIntegrity(): JsonResponse
    {
        return $this->financialReport(FinancialDataIntegrityService::SOURCE_FINANCIAL_RESULTS);
    }

    private function financialReport(string $source): JsonResponse
    {
        try {
            return $this->sendResponse($this->financialService->getReport($source), 'Financial integrity report generated successfully');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Error generating financial integrity report', $e);
        }
    }
}
