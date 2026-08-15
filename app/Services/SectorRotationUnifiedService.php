<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sector;
use App\Models\SectorRotationMetric;
use App\Models\Index;
use App\Models\IndexPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Unified 4-score sector rotation system.
 *
 * Calculates daily sector rotation metrics using four independent dimensions:
 * 1. Relative Strength Score: Sector strength vs benchmark (RS ratio + momentum)
 * 2. Breadth Score: Technical confirmation from constituents (EMA, RSI, MACD, DI)
 * 3. Participation Score: Breadth of move (free-float vs equal-weight + UIN settlement)
 * 4. Flow Score: Institutional money confirmation (FIPI/LIPI net flows)
 *
 * These four scores independently contribute to rotation status classification.
 */
class SectorRotationUnifiedService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const RS_MOMENTUM_LOOKBACK = 10;
    private const BASELINE_DAYS = 20;

    public function __construct(
        private SectorIndexBuilder $indexBuilder,
        private SectorBreadthService $breadthService,
        private SectorSettlementService $settlementService,
        private FIPILIPIFlowService $flowService,
        private SectorRelativeStrengthService $rsService,
    ) {}

    /**
     * Calculate and persist daily unified metrics for all sectors vs benchmark.
     *
     * Orchestrates calculation of all four scores, classifies rotation status,
     * and updates rotation periods on status changes.
     *
     * @param string $benchmarkIndexId Benchmark index UUID (e.g., KSE100)
     * @param string $date Trading date (Y-m-d)
     * @return void
     */
    public function calculateAndPersistDailyMetrics(string $benchmarkIndexId, string $date): void
    {
        Log::info('Starting unified sector rotation metrics calculation', [
            'benchmark_id' => $benchmarkIndexId,
            'date' => $date,
        ]);

        $benchmarkIndex = Index::find($benchmarkIndexId);
        if (!$benchmarkIndex) {
            Log::error('Benchmark index not found', ['benchmark_id' => $benchmarkIndexId]);
            return;
        }

        $sectors = Sector::all();
        $dateStr = Carbon::parse($date)->format('Y-m-d');

        foreach ($sectors as $sector) {
            try {
                $this->calculateSectorUnifiedMetrics($sector->id, $benchmarkIndexId, $dateStr);
            } catch (\Exception $e) {
                Log::error('Error calculating unified rotation metrics', [
                    'sector_id' => $sector->id,
                    'benchmark_id' => $benchmarkIndexId,
                    'date' => $dateStr,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Completed unified sector rotation metrics calculation', [
            'benchmark_id' => $benchmarkIndexId,
            'date' => $dateStr,
            'sector_count' => $sectors->count(),
        ]);
    }

    /**
     * Calculate all four scores for a single sector on a given date.
     *
     * @param string $sectorId Sector UUID
     * @param string $benchmarkIndexId Benchmark index UUID
     * @param string $date Trading date (Y-m-d)
     * @return void
     */
    protected function calculateSectorUnifiedMetrics(string $sectorId, string $benchmarkIndexId, string $date): void
    {
        $dateObj = Carbon::parse($date);
        $dateStr = $dateObj->format('Y-m-d');

        $rsScore = $this->calculateRelativeStrengthScore($sectorId, $benchmarkIndexId, $dateStr);
        $breadthScore = $this->calculateBreadthScore($sectorId, $dateStr);
        $participationScore = $this->calculateParticipationScore($sectorId, $dateStr);
        $flowScore = $this->calculateFlowScore($sectorId, $dateStr);

        if ($rsScore === null) {
            Log::warning('Could not calculate RS score; skipping metrics', [
                'sector_id' => $sectorId,
                'date' => $dateStr,
            ]);
            return;
        }

        // Classify rotation status from the four scores
        $rotationStatus = $this->classifyRotationStatus(
            $rsScore['score'],
            $breadthScore['score'],
            $participationScore['score'],
            $flowScore['score']
        );

        // Calculate quality/strength scores based on classification
        $improvementScore = null;
        $leadershipQualityScore = null;
        $strengthScore = null;

        if ($rotationStatus['status'] === 'improving') {
            $improvementScore = $this->calculateImprovementScore(
                $rsScore,
                $breadthScore,
                $participationScore
            );
        }

        if ($rotationStatus['status'] === 'leading') {
            $leadershipQualityScore = $this->calculateLeadershipQualityScore(
                $rsScore,
                $breadthScore,
                $participationScore,
                $flowScore
            );
        }

        $strengthScore = $this->calculateStrengthScore(
            $rsScore['score'],
            $breadthScore['score'],
            $participationScore['score'],
            $flowScore['score']
        );

        // Fetch prior metrics for delta calculations
        $oneDay = $dateObj->copy()->subDay()->format('Y-m-d');
        $fiveDay = $dateObj->copy()->subDays(5)->format('Y-m-d');

        $rsMetrics1Day = $this->getRSMetricsForDate($sectorId, $benchmarkIndexId, $oneDay);
        $rsMetrics5Day = $this->getRSMetricsForDate($sectorId, $benchmarkIndexId, $fiveDay);

        $deltas = $this->rsService->calculateRSDeltas(
            $rsScore['rs_ratio'],
            $rsMetrics1Day['rs_ratio'] ?? null,
            $rsMetrics5Day['rs_ratio'] ?? null,
            $rsScore['rs_momentum'],
            $rsMetrics1Day['rs_momentum'] ?? null,
            $rsMetrics5Day['rs_momentum'] ?? null
        );

        // Get benchmark close
        $benchmarkClose = $this->getBenchmarkClose($benchmarkIndexId, $dateStr);

        // Build metadata JSONB
        $metadata = [
            'calculated_at' => now()->toIso8601String(),
            'rotation_status' => $rotationStatus['status'],
            'quadrant' => $rotationStatus['quadrant'],
            'rs' => [
                'rs_ratio' => round($rsScore['rs_ratio'], 4),
                'rs_momentum' => round($rsScore['rs_momentum'], 4),
                'direction' => $rsScore['direction'],
            ],
            'breadth' => $breadthScore['breakdown'] ?? [],
            'participation' => $participationScore['breakdown'] ?? [],
            'flow' => $flowScore['breakdown'] ?? [],
        ];

        // Extract breadth components from breakdown
        $breadthDetail = $breadthScore['breakdown']['metrics'] ?? [];
        $participationDetail = $participationScore['breakdown'] ?? [];

        // Map V2 scores to V1 schema fields
        $metricData = [
            'sector_id' => $sectorId,
            'benchmark_index_id' => $benchmarkIndexId,
            'date' => $dateStr,
            'status' => $rotationStatus['status'],
            'status_since_date' => now()->format('Y-m-d'),
            'trading_sessions_in_status' => 1,
            'rs_vs_kse100' => round($rsScore['rs_ratio'], 10),
            'rs_vs_allshr' => round($rsScore['rs_ratio'], 10),
            'rs_ratio' => round($rsScore['rs_ratio'], 8),
            'rs_momentum' => round($rsScore['rs_momentum'], 8),
            'direction' => $rsScore['direction'],
            'sector_strength' => round($strengthScore, 2),
            'breadth_ema_participation' => isset($breadthDetail['metrics']['price_above_ema20']) ? round($breadthDetail['metrics']['price_above_ema20'], 2) : 0,
            'breadth_rsi_participation' => isset($breadthDetail['metrics']['rsi_above_50']) ? round($breadthDetail['metrics']['rsi_above_50'], 2) : 0,
            'breadth_macd_participation' => isset($breadthDetail['metrics']['macd_bullish']) ? round($breadthDetail['metrics']['macd_bullish'], 2) : 0,
            'breadth_di_participation' => isset($breadthDetail['metrics']['di_plus_above_di_minus']) ? round($breadthDetail['metrics']['di_plus_above_di_minus'], 2) : 0,
            'participation_free_float_vs_ew' => isset($participationDetail['free_float_vs_ew']) ? round($participationDetail['free_float_vs_ew'], 2) : 0,
            'participation_volume_ratio' => isset($participationDetail['volume_ratio']) ? round($participationDetail['volume_ratio'], 2) : 0,
            'participation_uin_settlement_pct' => isset($participationDetail['uin_settlement']) ? round($participationDetail['uin_settlement'], 2) : 0,
            'metadata' => $metadata,
        ];

        // Upsert metric record
        SectorRotationMetric::updateOrCreate(
            ['sector_id' => $sectorId, 'benchmark_index_id' => $benchmarkIndexId, 'date' => $dateStr],
            $metricData
        );

        Log::debug('Calculated unified rotation metrics', [
            'sector_id' => $sectorId,
            'date' => $dateStr,
            'status' => $rotationStatus['status'],
            'rs_score' => $rsScore['score'],
            'breadth_score' => $breadthScore['score'],
            'participation_score' => $participationScore['score'],
            'flow_score' => $flowScore['score'],
            'strength_score' => $strengthScore,
        ]);
    }

    /**
     * Calculate Relative Strength Score (0-100).
     *
     * Combines RS ratio and RS momentum to assess how strong the sector is vs benchmark.
     * Returns score 0-100, where 100 = maximum relative strength.
     *
     * @param string $sectorId Sector UUID
     * @param string $benchmarkIndexId Benchmark index UUID
     * @param string $date Trading date (Y-m-d)
     * @return array|null Score with components, or null if insufficient data
     */
    public function calculateRelativeStrengthScore(string $sectorId, string $benchmarkIndexId, string $date): ?array
    {
        $cacheKey = "rs_score:{$sectorId}:{$benchmarkIndexId}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorId, $benchmarkIndexId, $date) {
            $sectorIndexData = $this->getSectorIndexData($sectorId, $date, 60);
            $benchmarkData = $this->getBenchmarkIndexData($benchmarkIndexId, $date, 60);

            if (empty($sectorIndexData) || empty($benchmarkData)) {
                return null;
            }

            $rsMetrics = $this->rsService->calculateRelativeStrength(
                $sectorIndexData,
                $benchmarkData,
                $date
            );

            if ($rsMetrics['rs_ratio'] === null || $rsMetrics['rs_momentum'] === null) {
                return null;
            }

            $rsRatio = $rsMetrics['rs_ratio'];
            $rsMomentum = $rsMetrics['rs_momentum'];

            // Normalize RS ratio to 0-100 score
            // RS ratio = 100 is neutral, >100 strong, <100 weak
            $rsRatioScore = $this->normalizeRSRatio($rsRatio);

            // Normalize RS momentum to 0-100 score
            // RS momentum = 100 is neutral, >100 strengthening, <100 weakening
            $rsMomentumScore = $this->normalizeRSMomentum($rsMomentum);

            // Combined score: 60% RS ratio + 40% momentum
            $combinedScore = ($rsRatioScore * 0.6) + ($rsMomentumScore * 0.4);
            $score = min(100, max(0, $combinedScore));

            // Determine direction
            $direction = match (true) {
                $rsMomentum > 102 => 'strengthening',
                $rsMomentum < 98 => 'weakening',
                default => 'stable',
            };

            return [
                'score' => $score,
                'rs_value' => $rsMetrics['rs_value'],
                'rs_ratio' => $rsRatio,
                'rs_momentum' => $rsMomentum,
                'rs_ratio_score' => $rsRatioScore,
                'rs_momentum_score' => $rsMomentumScore,
                'direction' => $direction,
                'breakdown' => [
                    'rs_ratio' => round($rsRatio, 4),
                    'rs_momentum' => round($rsMomentum, 4),
                    'direction' => $direction,
                ],
            ];
        });
    }

    /**
     * Calculate Breadth Score (0-100).
     *
     * Measures what percentage of constituents confirm the sector move via technical indicators.
     * Combines: % above EMA20/50/100/200, % RSI > 50, % MACD bullish, % DI+ > DI-
     *
     * @param string $sectorId Sector UUID
     * @param string $date Trading date (Y-m-d)
     * @return array Score with breakdown, eligible_count, coverage_ratio
     */
    public function calculateBreadthScore(string $sectorId, string $date): array
    {
        $cacheKey = "breadth_score:{$sectorId}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorId, $date) {
            $breadthMetrics = $this->breadthService->calculateBreadthMetrics($sectorId, $date);
            $score = $this->breadthService->calculateBreadthScore($breadthMetrics);

            $validation = $breadthMetrics['validation'] ?? [];

            return [
                'score' => $score,
                'eligible_count' => $validation['eligible'] ?? 0,
                'coverage_ratio' => $validation['coverage_percentage'] ?? 0.0,
                'breakdown' => $breadthMetrics['metrics'] ?? [],
                'validation' => $validation,
            ];
        });
    }

    /**
     * Calculate Participation Score (0-100).
     *
     * Measures breadth of the move (concentration vs broad-based).
     * Combines: free-float vs equal-weight return comparison + UIN settlement breadth.
     * Higher score = broader move (less concentrated).
     *
     * @param string $sectorId Sector UUID
     * @param string $date Trading date (Y-m-d)
     * @return array Score with breakdown
     */
    public function calculateParticipationScore(string $sectorId, string $date): array
    {
        $cacheKey = "participation_score:{$sectorId}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorId, $date) {
            // Get sector returns: free-float weighted vs equal-weight
            $returns = $this->indexBuilder->buildSectorReturnForDate($sectorId, $date);

            $freeFloatReturn = $returns['sector_return'] ?? 0;
            $equalWeightReturn = $returns['equal_weight_return'] ?? 0;

            // Calculate concentration ratio: how much does equal-weight outperform free-float?
            // Values near 1.0 = broad participation (good)
            // Values near 0.9 or 1.1 = concentrated (bad)
            $concentrationRatio = 1.0;
            if ($freeFloatReturn !== 0 && $freeFloatReturn !== null) {
                $concentrationRatio = $equalWeightReturn / $freeFloatReturn;
            }

            // Normalize: perfect breadth is 1.0 ratio (equal-weight = free-float)
            // Allow ±10% deviation = score 100
            $deviationFromPerfect = abs($concentrationRatio - 1.0);
            $weightedVsEqualScore = max(0, 100 - ($deviationFromPerfect * 1000));

            // Get UIN settlement breadth (% of stocks above own 20-day baseline)
            $settlementBreadth = $this->settlementService->calculateSettlementBreadth($sectorId, $date);
            $uinBreadthPercentage = $settlementBreadth['metrics']['above_own_baseline'] ?? 0;

            // Combine: 60% concentration + 40% UIN breadth
            $score = ($weightedVsEqualScore * 0.6) + ($uinBreadthPercentage * 0.4);
            $score = min(100, max(0, $score));

            return [
                'score' => $score,
                'breakdown' => [
                    'weighted_vs_equal' => round($concentrationRatio, 4),
                    'concentration_score' => round($weightedVsEqualScore, 2),
                    'uin_breadth_percentage' => round($uinBreadthPercentage, 2),
                    'classification' => $this->classifyBreadth($concentrationRatio),
                ],
            ];
        });
    }

    /**
     * Calculate Flow Score (0-100) or null if unavailable.
     *
     * Measures FIPI/LIPI institutional money confirmation.
     * Returns null for aggregate sectors or unmapped sectors.
     *
     * @param string $sectorId Sector UUID
     * @param string $date Trading date (Y-m-d)
     * @return array Score (may be null), breakdown, and availability
     */
    public function calculateFlowScore(string $sectorId, string $date): array
    {
        $cacheKey = "flow_score:{$sectorId}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorId, $date) {
            if (!$this->flowService->flowAvailable($sectorId)) {
                return [
                    'score' => null,
                    'available' => false,
                    'message' => 'FIPI/LIPI flow not available for this sector',
                    'breakdown' => [],
                ];
            }

            $flowData = $this->flowService->getFlowForSector($sectorId, $date);

            if (!$flowData['available'] || !isset($flowData['flows'])) {
                return [
                    'score' => null,
                    'available' => false,
                    'message' => $flowData['message'] ?? 'No flow data available',
                    'breakdown' => [],
                ];
            }

            $score = $this->flowService->calculateFlowScore($flowData['flows']);
            $direction = $this->flowService->classifyFlowDirection($flowData['flows']['20d'] ?? 0);

            return [
                'score' => $score,
                'available' => true,
                'breakdown' => [
                    'fipi_5d_net' => $flowData['flows']['5d'] ?? 0,
                    'fipi_20d_net' => $flowData['flows']['20d'] ?? 0,
                    'direction' => $direction['direction'],
                    'currency' => $flowData['currency'] ?? 'USD',
                ],
            ];
        });
    }

    /**
     * Classify rotation status from four unified scores.
     *
     * Returns: Leading (strong RS + strong breadth), Improving (weak RS + strengthening breadth),
     *          Weakening (strong RS + weakening breadth), Lagging (weak RS + weak breadth)
     *
     * @param float $rsScore Relative Strength Score (0-100)
     * @param float $breadthScore Breadth Score (0-100)
     * @param float $participationScore Participation Score (0-100)
     * @param float|null $flowScore Flow Score (0-100) or null
     * @return array Classification with status, quadrant, and evidence
     */
    public function classifyRotationStatus(
        float $rsScore,
        float $breadthScore,
        float $participationScore,
        ?float $flowScore = null
    ): array {
        // Thresholds for strong (>50) vs weak (<50) scores
        $rsStrong = $rsScore > 55;
        $breadthStrong = $breadthScore > 55;
        $participationStrong = $participationScore > 50;
        $flowStrong = $flowScore !== null && $flowScore > 55;

        // Classification logic (similar to quadrant rotation but using 4 scores)
        if ($rsStrong && $breadthStrong && $participationStrong) {
            $status = 'leading';
            $quadrant = 'QI';
            $confidence = 'high';
        } elseif (!$rsStrong && $breadthStrong && $participationStrong) {
            $status = 'improving';
            $quadrant = 'QII';
            $confidence = 'high';
        } elseif (!$rsStrong && !$breadthStrong) {
            $status = 'lagging';
            $quadrant = 'QIII';
            $confidence = 'high';
        } else { // $rsStrong && !$breadthStrong
            $status = 'weakening';
            $quadrant = 'QIV';
            $confidence = 'high';
        }

        return [
            'status' => $status,
            'quadrant' => $quadrant,
            'confidence' => $confidence,
            'evidence' => [
                'rs_strong' => $rsStrong,
                'breadth_strong' => $breadthStrong,
                'participation_strong' => $participationStrong,
                'flow_confirmation' => $flowStrong,
            ],
        ];
    }

    /**
     * Calculate Improvement Score for "Improving" sectors.
     *
     * Measures quality of improvement: how much breadth and participation are supporting the RS recovery?
     * Returns 0-100, where 100 = excellent quality improvement.
     *
     * @param array $rsScore RS score breakdown
     * @param array $breadthScore Breadth score breakdown
     * @param array $participationScore Participation score breakdown
     * @return float Quality score 0-100
     */
    public function calculateImprovementScore(array $rsScore, array $breadthScore, array $participationScore): float
    {
        // For improving sectors: breadth + participation should be strong to confirm recovery
        $breadthConfirm = $breadthScore['score'];
        $participationConfirm = $participationScore['score'];
        $rsMomentum = min(100, max(0, $rsScore['rs_momentum_score']));

        // Weighted quality: 40% breadth, 40% participation, 20% momentum strength
        $score = ($breadthConfirm * 0.4) + ($participationConfirm * 0.4) + ($rsMomentum * 0.2);

        return min(100, max(0, $score));
    }

    /**
     * Calculate Leadership Quality Score for "Leading" sectors.
     *
     * Measures quality of leadership: how sustained and broad is the dominance?
     * Returns 0-100, where 100 = excellent quality leadership.
     *
     * @param array $rsScore RS score breakdown
     * @param array $breadthScore Breadth score breakdown
     * @param array $participationScore Participation score breakdown
     * @param array $flowScore Flow score breakdown
     * @return float Quality score 0-100
     */
    public function calculateLeadershipQualityScore(
        array $rsScore,
        array $breadthScore,
        array $participationScore,
        array $flowScore
    ): float {
        $rsRatioScore = min(100, max(0, $rsScore['rs_ratio_score']));
        $breadthConfirm = $breadthScore['score'];
        $participationBreadth = $participationScore['score'];

        // Flow confirmation (if available)
        $flowConfirm = $flowScore['score'] ?? 50;

        // Weighted leadership quality: 30% RS strength, 25% breadth, 25% participation, 20% flow
        $score = ($rsRatioScore * 0.3) + ($breadthConfirm * 0.25) + ($participationBreadth * 0.25) + ($flowConfirm * 0.2);

        return min(100, max(0, $score));
    }

    /**
     * Calculate Strength Score (0-100).
     *
     * Composite quality assessment combining all four dimensions.
     * Higher = healthier rotation signal, lower = requires caution.
     *
     * @param float $rsScore Relative Strength Score
     * @param float $breadthScore Breadth Score
     * @param float $participationScore Participation Score
     * @param float|null $flowScore Flow Score (null if unavailable)
     * @return float Composite score 0-100
     */
    public function calculateStrengthScore(
        float $rsScore,
        float $breadthScore,
        float $participationScore,
        ?float $flowScore = null
    ): float {
        // If flow is unavailable, weight it as neutral (50)
        $flowScoreEffective = $flowScore ?? 50;

        // Weights: RS 35%, breadth 35%, participation 20%, flow 10%
        $score = ($rsScore * 0.35) + ($breadthScore * 0.35) + ($participationScore * 0.20) + ($flowScoreEffective * 0.10);

        return min(100, max(0, $score));
    }


    // ======== Helper Methods ========

    /**
     * Get sector rotation metrics for a date range.
     *
     * @param string $sectorId Sector UUID
     * @param string $benchmarkId Benchmark index UUID
     * @param string $fromDate Start date (Y-m-d)
     * @param string $toDate End date (Y-m-d)
     * @return \Illuminate\Support\Collection
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
     * Get sectors in a specific rotation status.
     *
     * @param string $benchmarkId Benchmark index UUID
     * @param string $status Rotation status (leading, improving, weakening, lagging)
     * @return \Illuminate\Support\Collection
     */
    public function getRotationByStatus(string $benchmarkId, string $status): \Illuminate\Support\Collection
    {
        return SectorRotationMetric::where('benchmark_index_id', $benchmarkId)
            ->where('status', $status)
            ->orderByDesc('date')
            ->get()
            ->unique('sector_id');
    }

    /**
     * Normalize RS ratio to 0-100 score.
     * 100 = neutral, >100 = strong, <100 = weak.
     *
     * @param float $rsRatio Relative strength ratio
     * @return float Score 0-100
     */
    protected function normalizeRSRatio(float $rsRatio): float
    {
        // RS ratio = 100 is neutral (score = 50)
        // RS ratio = 120 is strong (score = 100)
        // RS ratio = 80 is weak (score = 0)
        // Linear mapping: 20% deviation from 100 = 100 score

        $deviation = $rsRatio - 100;
        $score = 50 + ($deviation / 0.2); // Each 0.2 ratio change = 50 score points

        return min(100, max(0, $score));
    }

    /**
     * Normalize RS momentum to 0-100 score.
     * 100 = neutral, >100 = strengthening, <100 = weakening.
     *
     * @param float $rsMomentum Relative strength momentum
     * @return float Score 0-100
     */
    protected function normalizeRSMomentum(float $rsMomentum): float
    {
        // RS momentum = 100 is neutral (score = 50)
        // RS momentum = 115 is strengthening (score = 100)
        // RS momentum = 85 is weakening (score = 0)
        // Linear mapping: 15% deviation from 100 = 100 score

        $deviation = $rsMomentum - 100;
        $score = 50 + ($deviation / 0.15); // Each 0.15 momentum change = 50 score points

        return min(100, max(0, $score));
    }

    /**
     * Classify breadth quality.
     *
     * @param float $concentrationRatio Free-float / equal-weight ratio
     * @return string Classification (Broad, Concentrated, VeryConcentrated)
     */
    protected function classifyBreadth(float $concentrationRatio): string
    {
        $deviation = abs($concentrationRatio - 1.0);

        return match (true) {
            $deviation < 0.05 => 'Broad',
            $deviation < 0.15 => 'Concentrated',
            default => 'VeryConcentrated',
        };
    }

    /**
     * Get sector index data series calculated from constituent stocks.
     *
     * Builds rolling index from constituent stock prices for each date in lookback period.
     *
     * @param string $sectorId Sector UUID
     * @param string $date Trading date (Y-m-d)
     * @param int $lookbackDays Lookback period
     * @return array Price series with calculated index values
     */
    protected function getSectorIndexData(string $sectorId, string $date, int $lookbackDays): array
    {
        $dateObj = Carbon::parse($date);
        $fromDate = $dateObj->copy()->subDays($lookbackDays)->format('Y-m-d');

        $indexSeries = [];
        $indexValue = 100;

        for ($i = $lookbackDays; $i >= 0; $i--) {
            $currentDate = $dateObj->copy()->subDays($i)->format('Y-m-d');

            if ($currentDate < $fromDate) {
                continue;
            }

            $dayReturn = $this->indexBuilder->buildSectorReturnForDate($sectorId, $currentDate);
            if ($dayReturn['sector_return'] !== null) {
                $indexValue = $indexValue * (1 + ($dayReturn['sector_return'] / 100));
            }

            $indexSeries[] = [
                'date' => $currentDate,
                'close' => round($indexValue, 4),
            ];
        }

        return $indexSeries;
    }

    /**
     * Get benchmark index price data series.
     *
     * @param string $benchmarkIndexId Benchmark index UUID
     * @param string $date Trading date (Y-m-d)
     * @param int $lookbackDays Lookback period
     * @return array Price series
     */
    protected function getBenchmarkIndexData(string $benchmarkIndexId, string $date, int $lookbackDays): array
    {
        $fromDate = Carbon::parse($date)->subDays($lookbackDays)->format('Y-m-d');

        return IndexPrice::where('index_id', $benchmarkIndexId)
            ->whereBetween('date', [$fromDate, $date])
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date->format('Y-m-d'),
                'close' => $row->close,
            ])
            ->toArray();
    }

    /**
     * Get RS metrics from a prior date.
     *
     * @param string $sectorId Sector UUID
     * @param string $benchmarkIndexId Benchmark index UUID
     * @param string $date Trading date (Y-m-d)
     * @return array RS ratio and momentum, or nulls
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
     * Get benchmark closing price.
     *
     * @param string $benchmarkIndexId Benchmark index UUID
     * @param string $date Trading date (Y-m-d)
     * @return float|null Closing price
     */
    protected function getBenchmarkClose(string $benchmarkIndexId, string $date): ?float
    {
        $price = IndexPrice::where('index_id', $benchmarkIndexId)
            ->where('date', $date)
            ->first();

        return $price?->close;
    }

    /**
     * Get sector return.
     *
     * @param string $sectorId Sector UUID
     * @param string $date Trading date (Y-m-d)
     * @return float|null Sector return
     */
    protected function getSectorReturn(string $sectorId, string $date): ?float
    {
        $returns = $this->indexBuilder->buildSectorReturnForDate($sectorId, $date);
        return $returns['sector_return'] ?? null;
    }

    /**
     * Get equal-weight return.
     *
     * @param string $sectorId Sector UUID
     * @param string $date Trading date (Y-m-d)
     * @return float|null Equal-weight return
     */
    protected function getEqualWeightReturn(string $sectorId, string $date): ?float
    {
        $returns = $this->indexBuilder->buildSectorReturnForDate($sectorId, $date);
        return $returns['equal_weight_return'] ?? null;
    }

}
