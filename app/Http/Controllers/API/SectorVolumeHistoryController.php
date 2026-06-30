<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SectorVolumeHistoryController extends Controller
{
    /**
     * Get sector volume analysis data with filters
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSectorVolumeAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'sector_id' => 'nullable|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $sectorId = $request->get('sector_id');
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            // Validate sector exists if not "all"
            if ($sectorId && $sectorId !== 'all') {
                $sectorExists = DB::table('sectors')->where('id', $sectorId)->exists();
                if (!$sectorExists) {
                    return $this->validationErrorResponse(['sector_id' => ['The selected sector does not exist.']]);
                }
                // Individual sector analysis (daily time series)
                return $this->getIndividualSectorAnalysis($sectorId, $startDate, $endDate);
            } else {
                // All sectors analysis (aggregated)
                return $this->getAllSectorsAnalysis($startDate, $endDate);
            }

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving sector volume analysis', $e);
        }
    }

    /**
     * Get analysis for individual sector (daily time series)
     */
    private function getIndividualSectorAnalysis($sectorId, $startDate, $endDate): JsonResponse
    {
        // Get sector details
        $sector = DB::table('sectors')
            ->where('id', $sectorId)
            ->first();

        if (!$sector) {
            return $this->notFoundResponse('Sector not found');
        }

        // Get daily aggregated data for all stocks in this sector
        $dailyData = DB::table('stock_prices as sp')
            ->join('stocks as s', 'sp.stock_id', '=', 's.id')
            ->select([
                DB::raw("TO_CHAR(sp.date, 'YYYY-MM-DD') as date"),
                DB::raw('SUM(sp.volume) as total_volume'),
                DB::raw('COUNT(DISTINCT s.id) as active_stocks'),
            ])
            ->where('s.sector_id', $sectorId)
            ->where('s.is_active', true)
            ->where('s.market_cap', '>', 0)
            ->whereBetween('sp.date', [$startDate, $endDate])
            ->groupBy('sp.date')
            ->orderBy('sp.date', 'asc')
            ->get();

        if ($dailyData->isEmpty()) {
            return $this->notFoundResponse('No data found for this sector in the selected date range');
        }

        // Calculate moving averages based on trading days (available data points)
        $dataWithAverages = $dailyData->map(function ($item, $index) use ($dailyData) {
            // 3-day average volume (last 3 trading days including current)
            $startIndex3 = max(0, $index - 2);
            $count3 = $index + 1 - $startIndex3; // Actual number of trading days
            $slice3 = $dailyData->slice($startIndex3, $count3);
            $avg3 = $slice3->avg('total_volume');

            // 20-day average volume (last 20 trading days including current)
            $startIndex20 = max(0, $index - 19);
            $count20 = $index + 1 - $startIndex20; // Actual number of trading days
            $slice20 = $dailyData->slice($startIndex20, $count20);
            $avg20 = $slice20->avg('total_volume');

            return [
                'date' => $item->date,
                'total_volume' => $item->total_volume,
                'volume_avg_3d' => $avg3,
                'volume_avg_20d' => $avg20,
                'active_stocks' => $item->active_stocks,
            ];
        });

        // Calculate summary
        $firstDay = $dataWithAverages->first();
        $lastDay = $dataWithAverages->last();

        $totalVolume = $dataWithAverages->sum('total_volume');
        $avgVolume = $dataWithAverages->avg('total_volume');

        $summary = [
            'total_records' => $dataWithAverages->count(),
            'total_volume' => (float) $totalVolume,
            'avg_volume' => (float) $avgVolume,
            'max_volume' => (float) $dataWithAverages->max('total_volume'),
            'min_volume' => (float) $dataWithAverages->min('total_volume'),
            'avg_active_stocks' => (float) $dataWithAverages->avg('active_stocks'),
        ];

        // Format data
        $data = $dataWithAverages->map(function ($item) {
            return [
                'date' => $item['date'],
                'total_volume' => (float) $item['total_volume'],
                'volume_avg_3d' => (float) $item['volume_avg_3d'],
                'volume_avg_20d' => (float) $item['volume_avg_20d'],
                'active_stocks' => (int) $item['active_stocks'],
            ];
        });

        // Get list of stocks in this sector
        $sectorStocks = DB::table('stocks')
            ->select('id', 'symbol', 'description')
            ->where('sector_id', $sectorId)
            ->where('is_active', true)
            ->where('market_cap', '>', 0)
            ->orderBy('symbol', 'asc')
            ->get();

        return $this->successResponse([
            'analysis_type' => 'individual_sector',
            'sector' => [
                'id' => $sector->id,
                'name' => $sector->name,
                'total_stocks' => $sectorStocks->count(),
                'stocks' => $sectorStocks,
            ],
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => $summary,
            'data' => $data,
        ], 'Sector volume analysis data retrieved successfully');
    }

    /**
     * Get analysis for all sectors (aggregated)
     */
    private function getAllSectorsAnalysis($startDate, $endDate): JsonResponse
    {
        // Get aggregated data per sector for the entire date range
        $sectorsData = DB::table('stock_prices as sp')
            ->join('stocks as s', 'sp.stock_id', '=', 's.id')
            ->join('sectors as sec', 's.sector_id', '=', 'sec.id')
            ->select([
                'sec.id as sector_id',
                'sec.name as sector_name',
                DB::raw('COUNT(DISTINCT s.id) as total_stocks'),
                DB::raw('SUM(sp.volume) as total_volume'),
                DB::raw('AVG(sp.volume) as avg_volume'),
                DB::raw('MAX(sp.volume) as max_volume'),
                DB::raw('COUNT(DISTINCT sp.date) as trading_days'),
            ])
            ->where('s.is_active', true)
            ->where('s.market_cap', '>', 0)
            ->whereBetween('sp.date', [$startDate, $endDate])
            ->groupBy('sec.id', 'sec.name')
            ->orderBy('sec.name', 'asc')
            ->get();

        // Calculate moving averages
        $data = $sectorsData->map(function ($item) use ($startDate, $endDate) {
            // Calculate 3-day and 20-day moving averages for this sector
            $volumes = DB::table('stock_prices as sp')
                ->join('stocks as s', 'sp.stock_id', '=', 's.id')
                ->where('s.sector_id', $item->sector_id)
                ->where('s.is_active', true)
                ->where('s.market_cap', '>', 0)
                ->whereBetween('sp.date', [$startDate, $endDate])
                ->select(DB::raw('sp.date, SUM(sp.volume) as daily_volume'))
                ->groupBy('sp.date')
                ->orderBy('sp.date', 'desc')
                ->limit(20)
                ->pluck('daily_volume')
                ->toArray();

            $volumesCount = count($volumes);
            $avg3d = $volumesCount >= 3 ? array_sum(array_slice($volumes, 0, 3)) / 3 : null;
            $avg20d = $volumesCount >= 20 ? array_sum($volumes) / 20 : null;

            return [
                'sector_id' => $item->sector_id,
                'sector_name' => $item->sector_name,
                'total_stocks' => (int) $item->total_stocks,
                'total_volume' => (float) $item->total_volume,
                'avg_volume' => (float) $item->avg_volume,
                'volume_avg_3d' => $avg3d,
                'volume_avg_20d' => $avg20d,
                'max_volume' => (float) $item->max_volume,
                'trading_days' => (int) $item->trading_days,
            ];
        });

        // Overall summary
        $summary = [
            'total_sectors' => $data->count(),
            'total_volume' => $data->sum('total_volume'),
            'avg_volume_per_sector' => $data->avg('total_volume'),
            'highest_volume_sector' => $data->first() ? [
                'name' => $data->first()['sector_name'],
                'volume' => $data->first()['total_volume'],
            ] : null,
            'total_stocks_analyzed' => $data->sum('total_stocks'),
        ];

        return $this->successResponse([
            'analysis_type' => 'all_sectors',
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => $summary,
            'data' => $data,
        ], 'Sector volume analysis data retrieved successfully');
    }
}
