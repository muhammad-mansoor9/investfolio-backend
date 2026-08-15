<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sector;
use App\Models\SectorRotationMetric;
use App\Models\Index;
use App\Models\IndexPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SectorRotationService
{
    private SectorIndexBuilder $indexBuilder;
    private SectorRelativeStrengthService $rsService;

    public function __construct(
        SectorIndexBuilder $indexBuilder,
        SectorRelativeStrengthService $rsService
    ) {
        $this->indexBuilder = $indexBuilder;
        $this->rsService = $rsService;
    }

    /**
     * Calculate and persist daily sector rotation metrics for all sectors vs benchmark
     *
     * For each sector:
     * - Build sector returns
     * - Calculate RS metrics vs benchmark
     * - Compute daily deltas
     * - Persist SectorRotationMetric record
     */
    public function calculateAndPersistDailyMetrics(string $benchmarkIndexId, string $date): void
    {
        Log::info('Starting daily sector rotation metrics calculation', [
            'benchmark_id' => $benchmarkIndexId,
            'date' => $date,
        ]);

        // Get benchmark index data
        $benchmarkIndex = Index::find($benchmarkIndexId);
        if (!$benchmarkIndex) {
            Log::error('Benchmark index not found', ['benchmark_id' => $benchmarkIndexId]);
            return;
        }

        // Get all sectors
        $sectors = Sector::all();

        foreach ($sectors as $sector) {
            try {
                $this->calculateSectorMetrics($sector->id, $benchmarkIndexId, $date);
            } catch (\Exception $e) {
                Log::error('Error calculating sector rotation metrics', [
                    'sector_id' => $sector->id,
                    'benchmark_id' => $benchmarkIndexId,
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Completed daily sector rotation metrics calculation', [
            'benchmark_id' => $benchmarkIndexId,
            'date' => $date,
            'sector_count' => $sectors->count(),
        ]);
    }

    /**
     * Calculate rotation metrics for a single sector
     */
    protected function calculateSectorMetrics(string $sectorId, string $benchmarkIndexId, string $date): void
    {
        $dateObj = Carbon::parse($date);
        $dateStr = $dateObj->format('Y-m-d');

        // Build sector index and returns
        $this->indexBuilder->updateSectorIndices($sectorId, $dateStr);

        // Get benchmark price series
        $benchmarkData = $this->getBenchmarkIndexData($benchmarkIndexId, $dateStr, 60);

        if (empty($benchmarkData)) {
            Log::warning('Insufficient data for sector rotation metrics', [
                'sector_id' => $sectorId,
                'benchmark_id' => $benchmarkIndexId,
                'date' => $dateStr,
                'benchmark_data_count' => count($benchmarkData),
            ]);
            return;
        }

        // Calculate RS metrics
        $rsMetrics = $this->rsService->calculateRelativeStrength($sectorIndexData, $benchmarkData, $dateStr);

        if ($rsMetrics['rs_ratio'] === null) {
            Log::warning('Could not calculate RS metrics', [
                'sector_id' => $sectorId,
                'benchmark_id' => $benchmarkIndexId,
                'date' => $dateStr,
            ]);
            return;
        }

        // Get historical values for delta calculations
        $oneDay = $dateObj->copy()->subDay()->format('Y-m-d');
        $fiveDay = $dateObj->copy()->subDays(5)->format('Y-m-d');

        $rsMetrics1Day = $this->getRSMetricsForDate($sectorId, $benchmarkIndexId, $oneDay);
        $rsMetrics5Day = $this->getRSMetricsForDate($sectorId, $benchmarkIndexId, $fiveDay);

        // Calculate deltas
        $deltas = $this->rsService->calculateRSDeltas(
            $rsMetrics['rs_ratio'],
            $rsMetrics1Day['rs_ratio'] ?? null,
            $rsMetrics5Day['rs_ratio'] ?? null,
            $rsMetrics['rs_momentum'],
            $rsMetrics1Day['rs_momentum'] ?? null,
            $rsMetrics5Day['rs_momentum'] ?? null
        );

        // Classify rotation status
        $statusData = $this->rsService->classifyRotationStatus(
            $rsMetrics['rs_ratio'],
            $rsMetrics['rs_momentum']
        );

        // Get benchmark close price
        $benchmarkClose = end($benchmarkData)['close'] ?? null;

        // Build metric record
        $metricData = [
            'sector_id' => $sectorId,
            'benchmark_index_id' => $benchmarkIndexId,
            'date' => $dateStr,
            'timeframe' => 'daily',
            'total_stock_count' => $this->indexBuilder->getConstituentStocks($sectorId, $dateStr)->count(),
            'eligible_stock_count' => null,
            'coverage_ratio' => null,
            'sector_return' => null,
            'equal_weight_return' => null,
            'sector_index_value' => null,
            'equal_weight_index_value' => null,
            'benchmark_close' => $benchmarkClose,
            'rs_value' => $rsMetrics['rs_value'],
            'rs_ratio' => $rsMetrics['rs_ratio'],
            'rs_momentum' => $rsMetrics['rs_momentum'],
            'rs_allshr' => $this->calculateAllShareRS($sectorId, $benchmarkIndexId, $dateStr),
            'rs_ratio_delta_1' => $deltas['rs_ratio_delta_1'],
            'rs_ratio_delta_5' => $deltas['rs_ratio_delta_5'],
            'rs_momentum_delta_1' => $deltas['rs_momentum_delta_1'],
            'rs_momentum_delta_5' => $deltas['rs_momentum_delta_5'],
            'metadata' => [
                'status' => $statusData['status'],
                'quadrant' => $statusData['quadrant'],
                'calculated_at' => now()->toIso8601String(),
            ],
        ];

        // Upsert metric record
        SectorRotationMetric::upsert(
            [$metricData],
            ['sector_id', 'benchmark_index_id', 'date', 'timeframe']
        );

        Log::debug('Calculated sector rotation metrics', [
            'sector_id' => $sectorId,
            'date' => $dateStr,
            'status' => $statusData['status'],
            'rs_ratio' => $rsMetrics['rs_ratio'],
        ]);
    }

    /**
     * Get sector rotation metrics for a date range
     */
    public function getSectorRotationMetrics(string $sectorId, string $benchmarkId, string $fromDate, string $toDate): \Illuminate\Support\Collection
    {
        return SectorRotationMetric::where('sector_id', $sectorId)
            ->where('benchmark_index_id', $benchmarkId)
            ->whereBetween('date', [$fromDate, $toDate])
            ->daily()
            ->orderByDate()
            ->get();
    }

    /**
     * Get benchmark index price data series
     */
    protected function getBenchmarkIndexData(string $benchmarkIndexId, string $date, int $lookbackDays): array
    {
        $fromDate = Carbon::parse($date)->subDays($lookbackDays)->format('Y-m-d');

        $data = IndexPrice::where('index_id', $benchmarkIndexId)
            ->whereBetween('date', [$fromDate, $date])
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn($price) => [
                'date' => $price->date->format('Y-m-d'),
                'close' => $price->close,
                'open' => $price->open,
                'high' => $price->high,
                'low' => $price->low,
            ])
            ->toArray();

        return $data;
    }

    /**
     * Get RS metrics from a prior date
     */
    protected function getRSMetricsForDate(string $sectorId, string $benchmarkIndexId, string $date): array
    {
        $metric = SectorRotationMetric::where('sector_id', $sectorId)
            ->where('benchmark_index_id', $benchmarkIndexId)
            ->where('date', $date)
            ->first();

        if (!$metric) {
            return ['rs_ratio' => null, 'rs_momentum' => null];
        }

        return [
            'rs_ratio' => $metric->rs_ratio,
            'rs_momentum' => $metric->rs_momentum,
        ];
    }

    /**
     * Calculate All-Share relative strength (sector vs market)
     *
     * Simplified: uses latest benchmark close for now
     */
    protected function calculateAllShareRS(string $sectorId, string $benchmarkIndexId, string $date): ?float
    {
        $metric = SectorRotationMetric::where('sector_id', $sectorId)
            ->where('benchmark_index_id', $benchmarkIndexId)
            ->where('date', $date)
            ->first();

        if (!$metric || !$metric->rs_value) {
            return null;
        }

        return round($metric->rs_value, 4);
    }
}
