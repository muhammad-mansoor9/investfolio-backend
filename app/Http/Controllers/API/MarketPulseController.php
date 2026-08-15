<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Index;
use App\Models\Sector;
use App\Models\SectorRotationMetric;
use App\Models\SectorStockScore;
use App\Services\MarketRegimeService;
use App\Services\SectorRotationUnifiedService;
use App\Services\StockUnifiedAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MarketPulseController extends Controller
{
    private const CACHE_TTL = 300; // 5 minutes for market data

    public function __construct(
        private MarketRegimeService $marketRegimeService,
        private SectorRotationUnifiedService $sectorRotationService,
        private StockUnifiedAnalysisService $stockAnalysisService,
    ) {}

    /**
     * Get market pulse overview: KSE100 regime + top sectors by status
     * GET /api/market-pulse
     */
    public function getMarketPulse(): JsonResponse
    {
        try {
            $benchmarkIndex = Index::where('symbol', 'KSE100')->firstOrFail();
            $cacheKey = "market_pulse:{$benchmarkIndex->id}:" . now()->format('Y-m-d');

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($benchmarkIndex) {
                // Get current market regime (V1: simple regime + 4 components)
                $regimeMetric = \App\Models\MarketRegimeMetric::where('index_id', $benchmarkIndex->id)
                    ->orderBy('date', 'desc')
                    ->first();

                $market = $regimeMetric ? [
                    'symbol' => 'KSE100',
                    'regime' => $regimeMetric->regime,
                    'regime_score' => round($regimeMetric->regime_score, 2),
                    'structural_trend' => $regimeMetric->structural_trend,
                    'directional_bias' => $regimeMetric->directional_bias,
                    'tactical_momentum' => $regimeMetric->tactical_momentum,
                    'as_of' => $regimeMetric->date,
                ] : null;

                // Get sectors by status (top 4 each)
                $leading = SectorRotationMetric::where('benchmark_index_id', $benchmarkIndex->id)
                    ->where('status', 'Leading')
                    ->orderBy('date', 'desc')
                    ->take(4)
                    ->get();

                $improving = SectorRotationMetric::where('benchmark_index_id', $benchmarkIndex->id)
                    ->where('status', 'Improving')
                    ->orderBy('date', 'desc')
                    ->take(4)
                    ->get();

                $weakening = SectorRotationMetric::where('benchmark_index_id', $benchmarkIndex->id)
                    ->where('status', 'Weakening')
                    ->orderBy('date', 'desc')
                    ->take(3)
                    ->get();

                $lagging = SectorRotationMetric::where('benchmark_index_id', $benchmarkIndex->id)
                    ->where('status', 'Lagging')
                    ->orderBy('date', 'desc')
                    ->take(3)
                    ->get();

                return [
                    'market' => $market,
                    'rotation' => [
                        'leading' => $this->formatSectorMetricsV1($leading),
                        'improving' => $this->formatSectorMetricsV1($improving),
                        'weakening' => $this->formatSectorMetricsV1($weakening),
                        'lagging' => $this->formatSectorMetricsV1($lagging),
                    ],
                ];
            });

            return $this->successResponse($data, 'Market pulse retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Benchmark index not found', ['benchmark' => 'KSE100']);
            return $this->notFoundResponse('Benchmark index not found');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve market pulse', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->serverErrorResponse('Failed to retrieve market pulse', $e);
        }
    }

    /**
     * Get all sectors with rotation metrics and status
     * GET /api/sector-leadership?benchmark=KSE100&status=leading&sort=strength
     */
    public function getSectorLeadership(): JsonResponse
    {
        try {
            $benchmark = request('benchmark', 'KSE100');
            $status = request('status', null); // leading, improving, weakening, lagging, null for all
            $sort = request('sort', 'strength'); // strength, age, rs_ratio, rs_momentum

            $benchmarkIndex = Index::where('symbol', $benchmark)->firstOrFail();
            $cacheKey = "sector_leadership:{$benchmarkIndex->id}:{$status}:{$sort}:" . now()->format('Y-m-d');

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($benchmarkIndex, $status, $sort) {
                if ($status && in_array($status, ['leading', 'improving', 'weakening', 'lagging'])) {
                    $metrics = $this->sectorRotationService->getRotationByStatus($benchmarkIndex->id, ucfirst($status));
                } else {
                    // Get all sectors with latest metrics
                    $metrics = SectorRotationMetric::where('benchmark_index_id', $benchmarkIndex->id)
                        ->where('date', now()->toDateString())
                        ->with(['sector'])
                        ->get();
                }

                return $this->sortSectorMetrics($metrics, $sort);
            });

            return $this->successResponse([
                'benchmark' => $benchmark,
                'status_filter' => $status,
                'as_of_date' => now()->toDateString(),
                'sectors' => $data,
            ], 'Sector leadership data retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Benchmark index not found', ['benchmark' => $benchmark]);
            return $this->notFoundResponse('Benchmark index not found');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve sector leadership', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->serverErrorResponse('Failed to retrieve sector leadership', $e);
        }
    }

    /**
     * Get detailed analysis for a specific sector (V1: 7 outputs)
     * GET /api/sector-leadership/{sectorId}?benchmark=KSE100
     */
    public function getSectorDetail(string $sectorId): JsonResponse
    {
        try {
            $benchmark = request('benchmark', 'KSE100');
            $benchmarkIndex = Index::where('symbol', $benchmark)->firstOrFail();

            $sector = Sector::findOrFail($sectorId);
            $cacheKey = "sector_detail:{$sectorId}:{$benchmarkIndex->id}:" . now()->format('Y-m-d');

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sector, $sectorId, $benchmarkIndex) {
                // Get current metrics (V1 schema)
                $currentMetrics = SectorRotationMetric::where('sector_id', $sectorId)
                    ->where('benchmark_index_id', $benchmarkIndex->id)
                    ->orderBy('date', 'desc')
                    ->first();

                if (!$currentMetrics) {
                    return null;
                }

                // Get last 10 daily records for history
                $history = SectorRotationMetric::where('sector_id', $sectorId)
                    ->where('benchmark_index_id', $benchmarkIndex->id)
                    ->orderBy('date', 'desc')
                    ->limit(10)
                    ->get();

                return [
                    'sector' => [
                        'id' => $sector->id,
                        'name' => $sector->name,
                        'stock_count' => $sector->stocks()->where('is_active', true)->count(),
                    ],
                    'rotation_status' => [
                        'status' => $currentMetrics->status,
                        'since_date' => $currentMetrics->status_since_date,
                        'trading_sessions_in_status' => $currentMetrics->trading_sessions_in_status,
                    ],
                    'relative_strength' => [
                        'rs_vs_kse100' => round($currentMetrics->rs_vs_kse100, 3),
                        'rs_vs_allshr' => round($currentMetrics->rs_vs_allshr ?? 0, 3),
                        'rs_ratio' => round($currentMetrics->rs_ratio, 3),
                        'rs_momentum' => round($currentMetrics->rs_momentum, 3),
                    ],
                    'direction' => $currentMetrics->direction,
                    'sector_strength' => round($currentMetrics->sector_strength, 2),
                    'breadth' => [
                        'ema_participation' => round($currentMetrics->breadth_ema_participation, 2),
                        'rsi_participation' => round($currentMetrics->breadth_rsi_participation, 2),
                        'macd_participation' => round($currentMetrics->breadth_macd_participation, 2),
                        'di_participation' => round($currentMetrics->breadth_di_participation, 2),
                    ],
                    'participation' => [
                        'free_float_vs_equal_weight' => round($currentMetrics->participation_free_float_vs_ew, 2),
                        'volume_ratio' => round($currentMetrics->participation_volume_ratio, 2),
                        'uin_settlement_pct' => round($currentMetrics->participation_uin_settlement_pct, 2),
                    ],
                    'investor_flow_context' => $currentMetrics->metadata['flow_context'] ?? null,
                    'history' => $history->map(fn($m) => [
                        'date' => $m->date,
                        'status' => $m->status,
                        'strength' => round($m->sector_strength, 2),
                        'direction' => $m->direction,
                    ])->toArray(),
                ];
            });

            if (!$data) {
                return $this->notFoundResponse('No metrics available for this sector');
            }

            return $this->successResponse($data, 'Sector detail retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Sector or benchmark index not found', [
                'sector_id' => $sectorId,
                'benchmark' => $benchmark ?? 'KSE100',
            ]);
            return $this->notFoundResponse('Sector or benchmark index not found');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve sector detail', [
                'sector_id' => $sectorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->serverErrorResponse('Failed to retrieve sector detail', $e);
        }
    }

    /**
     * Get stocks within a sector ranked by watch score (V1: 4 scores + simple state)
     * GET /api/sector-leadership/{sectorId}/stocks?sort=watch_score
     */
    public function getSectorStocks(string $sectorId): JsonResponse
    {
        try {
            Sector::findOrFail($sectorId);

            $sort = request('sort', 'watch_score');
            $validSortFields = ['watch_score', 'stock_strength_score', 'relative_leadership_score', 'trend_structure_score', 'momentum_score', 'participation_score'];

            if (!in_array($sort, $validSortFields)) {
                $sort = 'watch_score';
            }

            $cacheKey = "sector_stocks:{$sectorId}:{$sort}:" . now()->format('Y-m-d');

            $stocks = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorId) {
                $today = now()->toDateString();
                return SectorStockScore::where('sector_id', $sectorId)
                    ->where('date', $today)
                    ->with(['stock', 'sector'])
                    ->get();
            });

            $stockLatest = $stocks->unique('stock_id')->mapWithKeys(function ($score) {
                return [$score->stock_id => $score];
            });

            // Sort by requested field
            usort($stockLatest, function ($a, $b) use ($sort) {
                return ($b->$sort ?? 0) <=> ($a->$sort ?? 0);
            });

            $data = [
                'sector_id' => $sectorId,
                'count' => count($stockLatest),
                'sort_by' => $sort,
                'stocks' => array_map(fn($score) => [
                    'stock_id' => $score->stock_id,
                    'symbol' => $score->stock->symbol,
                    'simple_state' => $score->simple_state,
                    'relative_leadership_score' => round($score->relative_leadership_score, 2),
                    'trend_structure_score' => round($score->trend_structure_score, 2),
                    'momentum_score' => round($score->momentum_score, 2),
                    'participation_score' => round($score->participation_score, 2),
                    'stock_strength_score' => round($score->stock_strength_score, 2),
                    'watch_score' => round($score->watch_score, 2),
                    'metadata' => $score->metadata ?? [],
                ], $stockLatest),
            ];

            return $this->successResponse($data, 'Sector stocks retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Sector not found', ['sector_id' => $sectorId]);
            return $this->notFoundResponse('Sector not found');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve sector stocks', [
                'sector_id' => $sectorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->serverErrorResponse('Failed to retrieve sector stocks', $e);
        }
    }

    // V1 Helper Methods

    private function formatSectorMetricsV1($metrics): array
    {
        return $metrics->map(fn($metric) => [
            'sector_id' => $metric->sector_id,
            'name' => $metric->sector->name ?? null,
            'status' => $metric->status,
            'status_since_date' => $metric->status_since_date,
            'trading_sessions_in_status' => $metric->trading_sessions_in_status,
            'rs_vs_kse100' => round($metric->rs_vs_kse100, 3),
            'rs_vs_allshr' => round($metric->rs_vs_allshr ?? 0, 3),
            'direction' => $metric->direction,
            'sector_strength' => round($metric->sector_strength, 2),
        ])->toArray();
    }
}
