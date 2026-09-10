<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SectorPEService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PEAnalysisController extends Controller
{
    private const MAX_PE_THRESHOLD = 30;

    public function __construct(
        private SectorPEService $sectorPEService,
    ) {}

    /**
     * Get P/E analysis for stocks or sectors
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPEAnalysis(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:stocks,sectors',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $type = $request->get('type');

            if ($type === 'stocks') {
                return $this->getStocksPEAnalysis();
            } else {
                return $this->getSectorsPEAnalysis();
            }

        } catch (\Exception $e) {
            return $this->serverErrorResponse('An error occurred while retrieving P/E analysis', $e);
        }
    }

    /**
     * Get P/E analysis for stocks.
     *
     * Individual PE ratio is only meaningful for profitable stocks
     * (ttm_eps > 0), so loss-making stocks are excluded from this
     * per-stock/discount-vs-sector-average view. This is a DIFFERENT
     * metric than the sector-wide `sector_pe` (see SectorPEService), which
     * is market-cap weighted and does include loss-making stocks. Both are
     * returned per row so callers can see either.
     */
    private function getStocksPEAnalysis(): JsonResponse
    {
        try {
            $calcDate = now()->toDateString();
            $stocks = SectorPEService::stockTtmData($calcDate);

            $stockPeData = $stocks
                ->filter(fn ($s) => $s->latest_price > 0 && $s->ttm_eps !== null && $s->ttm_eps > 0)
                ->map(function ($s) {
                    $s->pe_ratio = round($s->latest_price / $s->ttm_eps, 2);

                    return $s;
                });

            // Simple (unweighted) average PE per sector; requires >= 3
            // qualifying (profitable) stocks, matching the original rule.
            $sectorAvgPe = $stockPeData->groupBy('sector_id')
                ->filter(fn ($group) => $group->count() >= 3)
                ->map(fn ($group) => round($group->avg('pe_ratio'), 2));

            $sectorNames = DB::table('sectors')->pluck('name', 'id');
            $sectorPEMetrics = $this->sectorPEService::calculateAll($calcDate);

            $rows = $stockPeData
                ->filter(fn ($s) => $sectorAvgPe->has($s->sector_id)
                    && $s->pe_ratio > 0
                    && $s->pe_ratio <= self::MAX_PE_THRESHOLD)
                ->map(function ($s) use ($sectorAvgPe, $sectorNames, $sectorPEMetrics) {
                    $avgPe = $sectorAvgPe[$s->sector_id];
                    $sectorPE = $sectorPEMetrics[$s->sector_id] ?? [];

                    return [
                        'stock_id' => $s->id,
                        'symbol' => $s->symbol,
                        'description' => $s->description,
                        'sector' => [
                            'id' => $s->sector_id,
                            'name' => $sectorNames[$s->sector_id] ?? null,
                        ],
                        'current_price' => (float) $s->latest_price,
                        'ttm_eps' => (float) $s->ttm_eps,
                        'pe_ratio' => (float) $s->pe_ratio,
                        'sector_avg_pe' => (float) $avgPe,
                        'sector_pe' => $sectorPE['sector_pe'] ?? null,
                        'sector_top_pe' => $sectorPE['sector_top_pe'] ?? null,
                        'pe_vs_sector' => $avgPe > 0 ? round($s->pe_ratio / $avgPe, 2) : null,
                        'discount_percent' => $avgPe > 0 ? round((($avgPe - $s->pe_ratio) / $avgPe) * 100, 2) : null,
                        'latest_volume' => $s->latest_volume,
                    ];
                })
                ->values()
                ->all();

            // Rank by biggest discount to sector avg first (tie-break: cheapest PE),
            // but the list itself is returned ordered by plain PE ascending —
            // matching the original query's two separate orderings.
            usort($rows, function ($a, $b) {
                if ($a['discount_percent'] === $b['discount_percent']) {
                    return $a['pe_ratio'] <=> $b['pe_ratio'];
                }

                return $b['discount_percent'] <=> $a['discount_percent'];
            });
            foreach ($rows as $i => &$row) {
                $row['rank'] = $i + 1;
            }
            unset($row);

            usort($rows, fn ($a, $b) => $a['pe_ratio'] <=> $b['pe_ratio']);

            $data = collect($rows);

            $summary = [
                'total_stocks' => $data->count(),
                'avg_discount' => $data->avg('discount_percent') ?: 0,
                'max_discount' => $data->max('discount_percent') ?: 0,
                'min_discount' => $data->min('discount_percent') ?: 0,
            ];

            return $this->successResponse([
                'type' => 'stocks',
                'summary' => $summary,
                'data' => $data,
            ], 'Stock P/E analysis retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to analyze stock P/E data', $e);
        }
    }

    /**
     * Get P/E analysis for sectors: min/max/avg/median of individual
     * (profitable-only) stock PE ratios, alongside the market-cap-weighted
     * sector_pe / sector_top_pe from SectorPEService.
     */
    private function getSectorsPEAnalysis(): JsonResponse
    {
        try {
            $calcDate = now()->toDateString();
            $stocks = SectorPEService::stockTtmData($calcDate);

            $stockPeData = $stocks
                ->filter(fn ($s) => $s->latest_price > 0 && $s->ttm_eps !== null && $s->ttm_eps > 0)
                ->map(function ($s) {
                    $s->pe_ratio = round($s->latest_price / $s->ttm_eps, 2);

                    return $s;
                });

            $sectorNames = DB::table('sectors')->pluck('name', 'id');
            $sectorPEMetrics = $this->sectorPEService::calculateAll($calcDate);

            $rows = $stockPeData->groupBy('sector_id')
                ->filter(fn ($group) => $group->count() >= 3)
                ->map(function ($group, $sectorId) use ($sectorNames, $sectorPEMetrics) {
                    $peValues = $group->pluck('pe_ratio')->sort()->values();
                    $sectorPE = $sectorPEMetrics[$sectorId] ?? [];

                    return [
                        'sector_id' => $sectorId,
                        'sector_name' => $sectorNames[$sectorId] ?? null,
                        'total_stocks' => $group->count(),
                        'avg_pe' => round($peValues->avg(), 2),
                        'min_pe' => round($peValues->min(), 2),
                        'max_pe' => round($peValues->max(), 2),
                        'median_pe' => $this->median($peValues),
                        'sector_pe' => $sectorPE['sector_pe'] ?? null,
                        'sector_top_pe' => $sectorPE['sector_top_pe'] ?? null,
                    ];
                })
                ->sortBy('avg_pe')
                ->values();

            $data = $rows->map(function ($row, $i) {
                $row['rank'] = $i + 1;

                return $row;
            });

            $summary = [
                'total_sectors' => $data->count(),
                'avg_sector_pe' => $data->avg('avg_pe') ?: 0,
            ];

            return $this->successResponse([
                'type' => 'sectors',
                'summary' => $summary,
                'data' => $data,
            ], 'Sector P/E analysis retrieved successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to analyze sector P/E data', $e);
        }
    }

    /**
     * Median of an already-sorted, re-indexed (0-based) collection of numbers.
     */
    private function median(Collection $sorted): float
    {
        $count = $sorted->count();

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return round(($sorted->get($middle - 1) + $sorted->get($middle)) / 2, 2);
        }

        return round($sorted->get($middle), 2);
    }

    public function cacheSectorAndStockPE(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stocks' => 'required|array|min:1',
                'stocks.*.stock_id' => 'required|uuid|exists:stocks,id',
                'stocks.*.symbol' => 'required|string|max:10',
                'stocks.*.ttm_pe' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $stocksData = $request->get('stocks');
            $stocksBySector = [];
            $cachedStocks = [];

            foreach ($stocksData as $stock) {
                Cache::forever(
                    "psx:stock_ttm_pe_latest:{$stock['symbol']}",
                    [
                        'stock_id' => $stock['stock_id'],
                        'symbol' => $stock['symbol'],
                        'ttm_pe' => (float) $stock['ttm_pe'],
                    ]
                );

                $dbStock = DB::table('stocks')
                    ->select('id', 'sector_id', 'symbol', 'market_cap')
                    ->where('id', $stock['stock_id'])
                    ->first();

                // A PE of exactly 0 can't be weighted (division by zero) and
                // isn't a meaningful ratio anyway, so it's skipped for the
                // sector aggregate but still cached individually above.
                if ($dbStock && (float) $stock['ttm_pe'] > 0) {
                    $stocksBySector[$dbStock->sector_id][] = [
                        'market_cap' => (float) $dbStock->market_cap,
                        'ttm_pe' => (float) $stock['ttm_pe'],
                    ];
                }

                $cachedStocks[] = [
                    'symbol' => $stock['symbol'],
                    'ttm_pe' => (float) $stock['ttm_pe'],
                ];
            }

            $cachedSectors = [];
            foreach ($stocksBySector as $sectorId => $entries) {
                // Market-cap-weighted, consistent with SectorPEService:
                //   Sector PE = SUM(market_cap) / SUM(market_cap / ttm_pe)
                // (market_cap / pe === market_cap * eps / price, i.e. implied
                // net income) rather than a plain average of PE ratios, so a
                // single large-cap constituent isn't diluted by several
                // small-cap ones the way a simple average would.
                $totalMarketCap = array_sum(array_column($entries, 'market_cap'));
                $totalImpliedEarnings = array_sum(array_map(
                    fn ($e) => $e['market_cap'] / $e['ttm_pe'],
                    $entries
                ));

                $weightedPe = $totalImpliedEarnings > 0
                    ? round($totalMarketCap / $totalImpliedEarnings, 2)
                    : null;

                $sector = DB::table('sectors')
                    ->select('id', 'name')
                    ->where('id', $sectorId)
                    ->first();

                if ($sector) {
                    $sectorData = [
                        'sector_id' => $sector->id,
                        'sector_name' => $sector->name,
                        'avg_pe' => $weightedPe,
                        'total_stocks' => count($entries),
                    ];

                    Cache::put(
                        "psx:sector_pe_avg:{$sectorId}",
                        $sectorData,
                        24 * 60 * 60
                    );

                    $cachedSectors[] = $sectorData;
                }
            }

            Log::info('Sector and stock P/E cached', [
                'stocks_cached' => count($cachedStocks),
                'sectors_cached' => count($cachedSectors),
            ]);

            return $this->successResponse([
                'stocks_cached' => count($cachedStocks),
                'sectors_cached' => count($cachedSectors),
                'sector_data' => $cachedSectors,
                'stock_data' => $cachedStocks,
            ], 'Stock TTM PE and sector average PE cached successfully');

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to cache P/E data', $e);
        }
    }
}
