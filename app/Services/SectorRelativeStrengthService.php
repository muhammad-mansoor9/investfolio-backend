<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SectorRelativeStrengthService
{
    private static int $EMA_FAST_PERIOD = 10;
    private static int $EMA_SLOW_PERIOD = 30;
    private static int $RS_MOMENTUM_LOOKBACK = 10;
    private static float $RS_RATIO_THRESHOLD = 100.0;
    private static float $RS_MOMENTUM_THRESHOLD = 100.0;
    private static float $HYSTERESIS_BAND = 0.5;

    public function __construct()
    {
        // Load EMA periods from config
        $config = config('market_pulse.sector_rotation.relative_strength');
        if ($config) {
            self::$EMA_FAST_PERIOD = $config['ema_fast_period'] ?? self::$EMA_FAST_PERIOD;
            self::$EMA_SLOW_PERIOD = $config['ema_slow_period'] ?? self::$EMA_SLOW_PERIOD;
            self::$RS_MOMENTUM_LOOKBACK = $config['momentum_lookback'] ?? self::$RS_MOMENTUM_LOOKBACK;
        }

        $quadrants = config('market_pulse.sector_rotation.quadrants');
        if ($quadrants) {
            self::$RS_RATIO_THRESHOLD = $quadrants['rs_ratio_threshold'] ?? self::$RS_RATIO_THRESHOLD;
            self::$RS_MOMENTUM_THRESHOLD = $quadrants['rs_momentum_threshold'] ?? self::$RS_MOMENTUM_THRESHOLD;
            self::$HYSTERESIS_BAND = $quadrants['hysteresis_band'] ?? self::$HYSTERESIS_BAND;
        }
    }

    /**
     * Calculate relative strength metrics for a sector vs benchmark
     *
     * Returns RS value, RS ratio, and RS momentum
     */
    public function calculateRelativeStrength(array $sectorData, array $benchmarkData, string $date): array
    {
        if (empty($sectorData) || empty($benchmarkData)) {
            Log::warning('Insufficient data for relative strength calculation', [
                'sector_data_count' => count($sectorData),
                'benchmark_data_count' => count($benchmarkData),
                'date' => $date,
            ]);
            return [
                'rs_value' => null,
                'rs_ratio' => null,
                'rs_momentum' => null,
            ];
        }

        // Extract close prices
        $sectorPrices = array_column($sectorData, 'close');
        $benchmarkPrices = array_column($benchmarkData, 'close');

        if (empty($sectorPrices) || empty($benchmarkPrices)) {
            return [
                'rs_value' => null,
                'rs_ratio' => null,
                'rs_momentum' => null,
            ];
        }

        // Calculate raw relative strength (sector price / benchmark price * 100)
        $latestSectorPrice = end($sectorPrices);
        $latestBenchmarkPrice = end($benchmarkPrices);

        if ($latestBenchmarkPrice <= 0) {
            return [
                'rs_value' => null,
                'rs_ratio' => null,
                'rs_momentum' => null,
            ];
        }

        $rsValue = ($latestSectorPrice / $latestBenchmarkPrice) * 100;

        // Calculate RS ratio (fast EMA / slow EMA of raw RS)
        $rsRatio = $this->calculateRSRatioInternal($sectorPrices, $benchmarkPrices);

        // Calculate RS momentum
        $rsMomentum = null;
        if ($rsRatio !== null) {
            $rsMomentum = $this->calculateRSMomentumInternal($rsRatio, $sectorPrices, $benchmarkPrices);
        }

        return [
            'rs_value' => round($rsValue, 4),
            'rs_ratio' => $rsRatio,
            'rs_momentum' => $rsMomentum,
        ];
    }

    /**
     * Calculate RS Ratio: fast_ema / slow_ema of raw RS series
     *
     * Raw RS = sector_price / benchmark_price * 100
     */
    public function calculateRSRatio(array $sectorData, array $benchmarkData, string $date): ?float
    {
        if (empty($sectorData) || empty($benchmarkData)) {
            return null;
        }

        $sectorPrices = array_column($sectorData, 'close');
        $benchmarkPrices = array_column($benchmarkData, 'close');

        if (empty($sectorPrices) || empty($benchmarkPrices)) {
            return null;
        }

        return $this->calculateRSRatioInternal($sectorPrices, $benchmarkPrices);
    }

    /**
     * Calculate RS Momentum: rs_ratio(t) / rs_ratio(t-n)
     */
    public function calculateRSMomentum(array $currentRSRatio, array $previousRSRatio, string $date): ?float
    {
        if (empty($currentRSRatio) || empty($previousRSRatio)) {
            return null;
        }

        $currentRatio = end($currentRSRatio);
        $previousRatio = end($previousRSRatio);

        if ($previousRatio === null || $previousRatio <= 0) {
            return null;
        }

        return ($currentRatio / $previousRatio) * 100;
    }

    /**
     * Classify rotation status based on RS ratio and RS momentum
     *
     * Returns quadrant classification: leading, improving, weakening, lagging
     */
    public function classifyRotationStatus(float $rsRatio, float $rsMomentum): array
    {
        $isStrongRS = $rsRatio >= self::$RS_RATIO_THRESHOLD;
        $isStrongMomentum = $rsMomentum >= self::$RS_MOMENTUM_THRESHOLD;

        if ($isStrongRS && $isStrongMomentum) {
            $status = 'leading';
            $quadrant = 'QI';
        } elseif (!$isStrongRS && $isStrongMomentum) {
            $status = 'improving';
            $quadrant = 'QII';
        } elseif (!$isStrongRS && !$isStrongMomentum) {
            $status = 'lagging';
            $quadrant = 'QIII';
        } else { // $isStrongRS && !$isStrongMomentum
            $status = 'weakening';
            $quadrant = 'QIV';
        }

        return [
            'status' => $status,
            'quadrant' => $quadrant,
            'rs_ratio' => round($rsRatio, 4),
            'rs_momentum' => round($rsMomentum, 4),
            'metadata' => [
                'is_strong_rs' => $isStrongRS,
                'is_strong_momentum' => $isStrongMomentum,
                'rs_ratio_threshold' => self::$RS_RATIO_THRESHOLD,
                'rs_momentum_threshold' => self::$RS_MOMENTUM_THRESHOLD,
            ],
        ];
    }

    /**
     * Detect rotation direction based on directional changes
     *
     * Returns direction: strengthening, stable, deteriorating
     */
    public function detectRotationDirection(float $rsRatioDelta5Day, float $rsMomentumDelta5Day): array
    {
        $rsRatioBand = self::$HYSTERESIS_BAND;
        $rsMomentumBand = self::$HYSTERESIS_BAND;

        if ($rsRatioDelta5Day >= $rsRatioBand && $rsMomentumDelta5Day >= $rsMomentumBand) {
            $direction = 'strengthening';
        } elseif ($rsRatioDelta5Day <= -$rsRatioBand && $rsMomentumDelta5Day <= -$rsMomentumBand) {
            $direction = 'deteriorating';
        } else {
            $direction = 'stable';
        }

        return [
            'direction' => $direction,
            'rs_ratio_delta_5' => round($rsRatioDelta5Day, 4),
            'rs_momentum_delta_5' => round($rsMomentumDelta5Day, 4),
            'hysteresis_band' => self::$HYSTERESIS_BAND,
            'metadata' => [
                'strengthening_threshold' => $rsRatioBand,
                'deteriorating_threshold' => -$rsRatioBand,
            ],
        ];
    }

    /**
     * Calculate 1-day and 5-day changes in RS ratio and momentum
     */
    public function calculateRSDeltas(float $rsRatioCurrent, ?float $rsRatio1Day, ?float $rsRatio5Day, float $rsMomentumCurrent, ?float $rsMomentum1Day, ?float $rsMomentum5Day): array
    {
        $rsRatioDelta1 = null;
        $rsRatioDelta5 = null;
        $rsMomentumDelta1 = null;
        $rsMomentumDelta5 = null;

        if ($rsRatio1Day !== null && $rsRatio1Day > 0) {
            $rsRatioDelta1 = $rsRatioCurrent - $rsRatio1Day;
        }

        if ($rsRatio5Day !== null && $rsRatio5Day > 0) {
            $rsRatioDelta5 = $rsRatioCurrent - $rsRatio5Day;
        }

        if ($rsMomentum1Day !== null && $rsMomentum1Day > 0) {
            $rsMomentumDelta1 = $rsMomentumCurrent - $rsMomentum1Day;
        }

        if ($rsMomentum5Day !== null && $rsMomentum5Day > 0) {
            $rsMomentumDelta5 = $rsMomentumCurrent - $rsMomentum5Day;
        }

        return [
            'rs_ratio_delta_1' => $rsRatioDelta1 !== null ? round($rsRatioDelta1, 4) : null,
            'rs_ratio_delta_5' => $rsRatioDelta5 !== null ? round($rsRatioDelta5, 4) : null,
            'rs_momentum_delta_1' => $rsMomentumDelta1 !== null ? round($rsMomentumDelta1, 4) : null,
            'rs_momentum_delta_5' => $rsMomentumDelta5 !== null ? round($rsMomentumDelta5, 4) : null,
        ];
    }

    /**
     * Calculate RS ratio from price series
     *
     * Computes raw RS (sector/benchmark * 100), then applies EMA smoothing
     */
    protected function calculateRSRatioInternal(array $sectorPrices, array $benchmarkPrices): ?float
    {
        if (count($sectorPrices) < self::$EMA_SLOW_PERIOD || count($benchmarkPrices) < self::$EMA_SLOW_PERIOD) {
            return null;
        }

        // Calculate raw RS series
        $rsValues = [];
        $count = min(count($sectorPrices), count($benchmarkPrices));

        for ($i = 0; $i < $count; $i++) {
            if ($benchmarkPrices[$i] > 0) {
                $rsValues[] = ($sectorPrices[$i] / $benchmarkPrices[$i]) * 100;
            }
        }

        if (count($rsValues) < self::$EMA_SLOW_PERIOD) {
            return null;
        }

        // Calculate fast and slow EMAs
        $fastEMA = $this->calculateEMA($rsValues, self::$EMA_FAST_PERIOD);
        $slowEMA = $this->calculateEMA($rsValues, self::$EMA_SLOW_PERIOD);

        if ($fastEMA === null || $slowEMA === null || $slowEMA == 0) {
            return null;
        }

        return ($fastEMA / $slowEMA) * 100;
    }

    /**
     * Calculate RS momentum from price series
     *
     * Momentum = current RS ratio / RS ratio from N periods ago
     */
    protected function calculateRSMomentumInternal(float $currentRSRatio, array $sectorPrices, array $benchmarkPrices): ?float
    {
        $lookback = self::$RS_MOMENTUM_LOOKBACK;

        if (count($sectorPrices) < $lookback || count($benchmarkPrices) < $lookback) {
            return null;
        }

        // Get prices from N days back
        $oldSectorPrice = $sectorPrices[count($sectorPrices) - $lookback - 1] ?? null;
        $oldBenchmarkPrice = $benchmarkPrices[count($benchmarkPrices) - $lookback - 1] ?? null;

        if ($oldSectorPrice === null || $oldBenchmarkPrice === null || $oldBenchmarkPrice <= 0) {
            return null;
        }

        $oldRSRatio = ($oldSectorPrice / $oldBenchmarkPrice) * 100;

        if ($oldRSRatio <= 0) {
            return null;
        }

        return ($currentRSRatio / $oldRSRatio) * 100;
    }

    /**
     * Calculate Exponential Moving Average
     */
    protected function calculateEMA(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        $k = 2 / ($period + 1);

        // Start with simple moving average as initial EMA
        $sum = 0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $values[$i];
        }
        $ema = $sum / $period;

        // Apply exponential smoothing
        for ($i = $period; $i < count($values); $i++) {
            $ema = $values[$i] * $k + $ema * (1 - $k);
        }

        return $ema;
    }
}
