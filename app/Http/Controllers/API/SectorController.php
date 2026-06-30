<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectorController extends Controller
{
    /**
     * Get all sectors with active stock counts for dropdowns
     * Used by: Screener, Volume Analysis, Sector Volume History
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllSectors(Request $request): JsonResponse
    {
        try {
            $sectors = DB::table('sectors as sec')
                ->select([
                    'sec.id',
                    'sec.name',
                    DB::raw('COUNT(DISTINCT CASE WHEN s.is_active = true AND s.market_cap > 0 THEN s.id END) as stock_count')
                ])
                ->leftJoin('stocks as s', 'sec.id', '=', 's.sector_id')
                ->groupBy('sec.id', 'sec.name')
                ->having(DB::raw('COUNT(DISTINCT CASE WHEN s.is_active = true AND s.market_cap > 0 THEN s.id END)'), '>', 0)
                ->orderBy('sec.name', 'asc')
                ->get();

            $formattedSectors = $sectors->map(function ($sector) {
                return [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'stock_count' => (int) $sector->stock_count,
                ];
            });

            return $this->successResponse([
                'sectors' => $formattedSectors,
                'total_results' => $formattedSectors->count(),
            ], 'Sectors retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving sectors', $e);
        }
    }

    /**
     * Get sector stocks by sector ID
     *
     * @param Request $request
     * @param string $sectorId
     * @return JsonResponse
     */
    public function getSectorStocks(Request $request, string $sectorId): JsonResponse
    {
        try {
            // First, verify the sector exists
            $sector = DB::table('sectors')
                ->where('id', $sectorId)
                ->first();

            if (!$sector) {
                return $this->notFoundResponse('Sector not found');
            }

            // Get stocks for this sector
            $stocks = DB::table('stocks as s')
                ->leftJoin('exchange_markets as em', 's.exchange_id', '=', 'em.id')
                ->leftJoin('stock_prices as sp', function ($join) {
                    $join->on('s.id', '=', 'sp.stock_id')
                        ->whereRaw('sp.date = (SELECT MAX(date) FROM stock_prices WHERE stock_id = s.id)');
                })
                ->select([
                    's.id',
                    's.symbol',
                    's.description',
                    's.is_shariah',
                    's.market_cap',
                    's.total_shares_outstanding',
                    's.free_float',
                    's.face_value',
                    DB::raw('ROUND(((s.free_float::numeric / s.total_shares_outstanding::numeric) * 100), 2) AS free_float_percentage'),
                    'em.id as exchange_id',
                    'em.name as exchange_name',
                    'sp.id as latest_price_id',
                    'sp.price as latest_price',
                    'sp.open as latest_open',
                    'sp.high as latest_high',
                    'sp.low as latest_low',
                    'sp.close as latest_close',
                    'sp.volume as latest_volume',
                    'sp.change as latest_change',
                    'sp.date as latest_price_date'
                ])
                ->where('s.sector_id', $sectorId)
                ->orderBy('s.market_cap', 'desc')
                ->get();

            // Structure the response
            $structuredStocks = $stocks->map(function ($stock) {
                return [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'description' => $stock->description,
                    'is_shariah' => $stock->is_shariah,
                    'market_cap' => $stock->market_cap,
                    'total_shares_outstanding' => $stock->total_shares_outstanding,
                    'free_float' => $stock->free_float,
                    'free_float_percentage' => $stock->free_float_percentage,
                    'face_value' => $stock->face_value,
                    'exchange' => $stock->exchange_id ? [
                        'id' => $stock->exchange_id,
                        'name' => $stock->exchange_name
                    ] : null,
                    'latest_price' => $stock->latest_price_id ? [
                        'price' => $stock->latest_price,
                        'open' => $stock->latest_open,
                        'high' => $stock->latest_high,
                        'low' => $stock->latest_low,
                        'close' => $stock->latest_close,
                        'volume' => $stock->latest_volume,
                        'change' => $stock->latest_change,
                        'date' => $stock->latest_price_date
                    ] : null
                ];
            });

            return $this->successResponse([
                'sector' => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'total_stocks' => $structuredStocks->count()
                ],
                'stocks' => $structuredStocks
            ], 'Sector stocks retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving sector stocks', $e);
        }
    }
}
