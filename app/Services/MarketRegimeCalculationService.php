<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class MarketRegimeCalculationService
{
    /**
     * Calculate structural score (long-term trend via EMA200, EMA100, EMA50)
     * Range: 0-100 where 50=neutral
     */
    public function calculateStructuralScore(array $indexPrices, ?\DateTime $date = null): array
    {
        try {
            $config = Config::get('market_pulse.market_regime.structural');
            $periods = $config['ema_periods'] ?? [50, 100, 200];

            $closePrices = array_map(fn($p) => (float)$p['close'], $indexPrices);

            $ema50 = $this->calculateEMA($closePrices, 50);
            $ema100 = $this->calculateEMA($closePrices, 100);
            $ema200 = $this->calculateEMA($closePrices, 200);

            $currentPrice = $closePrices[0] ?? null;

            if (!$currentPrice || !$ema50 || !$ema100 || !$ema200) {
                return [
                    'score' => 50,
                    'state' => 'Neutral',
                    'details' => 'Insufficient data',
                ];
            }

            // Calculate deviation from EMA200 (primary reference)
            $deviation = (($currentPrice - $ema200) / $ema200) * 100;

            // Check EMA alignment (trend structure)
            $emasAligned = ($ema50 > $ema100) && ($ema100 > $ema200);
            $emasInverted = ($ema50 < $ema100) && ($ema100 < $ema200);

            // Score calculation: 0-100 range
            $score = 50; // Neutral baseline

            if ($emasAligned && $deviation > 0) {
                // Bullish: aligned EMAs and price above EMA200
                $score = 50 + min(50, abs($deviation) / 2);
            } elseif ($emasInverted && $deviation < 0) {
                // Bearish: inverted EMAs and price below EMA200
                $score = 50 - min(50, abs($deviation) / 2);
            } else {
                // Transition or conflicting signals: move toward extreme based on primary signal
                if ($deviation > 0) {
                    $score = 50 + (min(50, abs($deviation) / 4));
                } else {
                    $score = 50 - (min(50, abs($deviation) / 4));
                }
            }

            // Clamp to 0-100
            $score = max(0, min(100, $score));

            $state = $this->classifyComponentState($score);

            return [
                'score' => round($score, 2),
                'state' => $state,
                'details' => [
                    'current_price' => round($currentPrice, 2),
                    'ema_50' => round($ema50, 2),
                    'ema_100' => round($ema100, 2),
                    'ema_200' => round($ema200, 2),
                    'price_deviation_from_ema200_percent' => round($deviation, 2),
                    'emas_aligned_bullish' => $emasAligned,
                    'emas_inverted_bearish' => $emasInverted,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error calculating structural score', [
                'error' => $e->getMessage(),
                'date' => $date?->format('Y-m-d'),
            ]);

            return [
                'score' => 50,
                'state' => 'Neutral',
                'details' => 'Calculation error',
            ];
        }
    }

    /**
     * Calculate directional score (medium-term via EMA50, EMA20)
     * Range: 0-100 where 50=neutral
     */
    public function calculateDirectionalScore(array $indexPrices, ?\DateTime $date = null): array
    {
        try {
            $config = Config::get('market_pulse.market_regime.directional');

            $closePrices = array_map(fn($p) => (float)$p['close'], $indexPrices);

            $ema20 = $this->calculateEMA($closePrices, 20);
            $ema50 = $this->calculateEMA($closePrices, 50);
            $currentPrice = $closePrices[0] ?? null;

            if (!$currentPrice || !$ema20 || !$ema50) {
                return [
                    'score' => 50,
                    'state' => 'Neutral',
                    'details' => 'Insufficient data',
                ];
            }

            // Calculate EMA slopes (rate of change)
            $ema20Slope = $this->calculateSlope($closePrices, 20, 5);
            $ema50Slope = $this->calculateSlope($closePrices, 50, 5);

            // Check crossover: EMA20 > EMA50 is bullish
            $bullishCrossover = $ema20 > $ema50;
            $priceAboveEMA50 = $currentPrice > $ema50;

            // Score calculation
            $score = 50;

            if ($bullishCrossover && $priceAboveEMA50 && $ema20Slope > 0) {
                // Strong bullish: EMA20 > EMA50, price above both, both rising
                $score = 50 + min(50, (($ema20 - $ema50) / $ema50) * 100 + abs($ema20Slope) * 5);
            } elseif (!$bullishCrossover && !$priceAboveEMA50 && $ema20Slope < 0) {
                // Strong bearish: EMA20 < EMA50, price below both, both falling
                $score = 50 - min(50, (($ema50 - $ema20) / $ema50) * 100 + abs($ema20Slope) * 5);
            } else {
                // Transition: partial signals
                if ($bullishCrossover && $priceAboveEMA50) {
                    $score = 50 + (($ema20 - $ema50) / $ema50) * 100;
                } elseif (!$bullishCrossover && !$priceAboveEMA50) {
                    $score = 50 - (($ema50 - $ema20) / $ema50) * 100;
                }
            }

            $score = max(0, min(100, $score));
            $state = $this->classifyComponentState($score);

            return [
                'score' => round($score, 2),
                'state' => $state,
                'details' => [
                    'current_price' => round($currentPrice, 2),
                    'ema_20' => round($ema20, 2),
                    'ema_50' => round($ema50, 2),
                    'ema20_above_ema50_bullish' => $bullishCrossover,
                    'price_above_ema50' => $priceAboveEMA50,
                    'ema_20_slope' => round($ema20Slope, 4),
                    'ema_50_slope' => round($ema50Slope, 4),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error calculating directional score', [
                'error' => $e->getMessage(),
                'date' => $date?->format('Y-m-d'),
            ]);

            return [
                'score' => 50,
                'state' => 'Neutral',
                'details' => 'Calculation error',
            ];
        }
    }

    /**
     * Calculate tactical score (short-term via RSI, MACD, short EMAs)
     * Range: 0-100 where 50=neutral
     */
    public function calculateTacticalScore(array $indexPrices, ?\DateTime $date = null): array
    {
        try {
            $config = Config::get('market_pulse.market_regime.tactical');

            $closePrices = array_map(fn($p) => (float)$p['close'], $indexPrices);
            $highPrices = array_map(fn($p) => (float)$p['high'], $indexPrices);
            $lowPrices = array_map(fn($p) => (float)$p['low'], $indexPrices);

            // Calculate RSI
            $rsiPeriod = $config['rsi_period'] ?? 14;
            $rsi = $this->calculateRSI($closePrices, $rsiPeriod);

            // Calculate MACD
            $macdFast = $config['macd_fast'] ?? 12;
            $macdSlow = $config['macd_slow'] ?? 26;
            $macdSignal = $config['macd_signal'] ?? 9;
            $macdData = $this->calculateMACD($closePrices, $macdFast, $macdSlow, $macdSignal);

            // Calculate short EMAs
            $ema6 = $this->calculateEMA($closePrices, 6);
            $ema20 = $this->calculateEMA($closePrices, 20);

            $currentPrice = $closePrices[0] ?? null;

            if (!$currentPrice || $rsi === null) {
                return [
                    'score' => 50,
                    'state' => 'Neutral',
                    'details' => 'Insufficient data',
                ];
            }

            // RSI interpretation (50 is neutral, >50 bullish, <50 bearish)
            $rsiScore = $rsi;

            // MACD signal: MACD > Signal Line = bullish momentum
            $macdBullish = $macdData['macd'] > $macdData['signal'];
            $macdScore = $macdBullish ? 60 : 40;

            // EMA6 vs EMA20: alignment for momentum
            $ema6Above20 = $ema6 > $ema20;
            $emaScore = $ema6Above20 ? 60 : 40;

            // Combine signals with weights: RSI 50%, MACD 30%, EMA 20%
            $score = ($rsiScore * 0.50) + ($macdScore * 0.30) + ($emaScore * 0.20);
            $score = max(0, min(100, $score));

            $state = $this->classifyComponentState($score);

            return [
                'score' => round($score, 2),
                'state' => $state,
                'details' => [
                    'rsi' => round($rsi, 2),
                    'rsi_score' => round($rsiScore, 2),
                    'macd' => round($macdData['macd'], 2),
                    'macd_signal' => round($macdData['signal'], 2),
                    'macd_histogram' => round($macdData['histogram'], 2),
                    'macd_bullish' => $macdBullish,
                    'macd_score' => round($macdScore, 2),
                    'ema_6' => round($ema6, 2),
                    'ema_20' => round($ema20, 2),
                    'ema6_above_ema20' => $ema6Above20,
                    'ema_score' => round($emaScore, 2),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error calculating tactical score', [
                'error' => $e->getMessage(),
                'date' => $date?->format('Y-m-d'),
            ]);

            return [
                'score' => 50,
                'state' => 'Neutral',
                'details' => 'Calculation error',
            ];
        }
    }

    /**
     * Classify a component score into state (Bullish/Neutral/Bearish)
     */
    public function classifyComponentState(float $score): string
    {
        $scoreScale = Config::get('market_pulse.market_regime.score_scale', [
            'bullish' => [70, 100],
            'neutral' => [30, 69],
            'bearish' => [0, 29],
        ]);

        if ($score >= $scoreScale['bullish'][0] && $score <= $scoreScale['bullish'][1]) {
            return 'Bullish';
        } elseif ($score >= $scoreScale['bearish'][0] && $score <= $scoreScale['bearish'][1]) {
            return 'Bearish';
        } else {
            return 'Neutral';
        }
    }

    /**
     * Combine component scores into overall regime score
     * Weights: Structural 40%, Directional 35%, Tactical 25%
     */
    public function combineComponentsIntoRegimeScore(float $structural, float $directional, float $tactical): array
    {
        $config = Config::get('market_pulse.market_regime', []);

        $structuralWeight = $config['structural']['weight'] ?? 40;
        $directionalWeight = $config['directional']['weight'] ?? 35;
        $tacticalWeight = $config['tactical']['weight'] ?? 25;

        // Normalize weights to percentages
        $totalWeight = $structuralWeight + $directionalWeight + $tacticalWeight;
        $structuralPct = $structuralWeight / $totalWeight;
        $directionalPct = $directionalWeight / $totalWeight;
        $tacticalPct = $tacticalWeight / $totalWeight;

        // Calculate weighted regime score
        $regimeScore = ($structural * $structuralPct) +
                      ($directional * $directionalPct) +
                      ($tactical * $tacticalPct);

        $regimeScore = max(0, min(100, $regimeScore));
        $regime = $this->classifyComponentState($regimeScore);

        return [
            'regime_score' => round($regimeScore, 2),
            'regime' => $regime,
            'weights' => [
                'structural' => round($structuralPct * 100, 1),
                'directional' => round($directionalPct * 100, 1),
                'tactical' => round($tacticalPct * 100, 1),
            ],
        ];
    }

    /**
     * Extract metadata for explainability
     */
    public function extractMetadataForExplainability(
        array $structuralDetails,
        array $directionalDetails,
        array $tacticalDetails
    ): array {
        return [
            'structural_details' => $structuralDetails,
            'directional_details' => $directionalDetails,
            'tactical_details' => $tacticalDetails,
            'calculated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================================================
    // TECHNICAL INDICATOR CALCULATIONS
    // ========================================================================

    /**
     * Calculate Exponential Moving Average
     * Prices array should be in ascending time order (oldest first)
     * Returns EMA of the most recent (latest) price
     */
    protected function calculateEMA(array $prices, int $period): ?float
    {
        if (count($prices) < $period) {
            return null;
        }

        // Reverse for processing (most recent first)
        $prices = array_reverse($prices);

        // Calculate Simple Moving Average for initial EMA
        $sma = array_sum(array_slice($prices, 0, $period)) / $period;

        // Multiplier for smoothing
        $multiplier = 2 / ($period + 1);

        // Calculate EMA starting from the oldest data
        $ema = $sma;
        for ($i = $period; $i < count($prices); $i++) {
            $ema = $prices[$i] * $multiplier + $ema * (1 - $multiplier);
        }

        return $ema;
    }

    /**
     * Calculate Relative Strength Index (RSI)
     * Prices array should be in ascending time order (oldest first)
     */
    protected function calculateRSI(array $prices, int $period = 14): ?float
    {
        if (count($prices) < $period + 1) {
            return null;
        }

        // Reverse for processing (most recent first)
        $prices = array_reverse($prices);

        // Calculate price changes
        $changes = [];
        for ($i = 1; $i < count($prices); $i++) {
            $changes[] = $prices[$i] - $prices[$i - 1];
        }

        // Separate gains and losses
        $gains = [];
        $losses = [];
        foreach ($changes as $change) {
            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        // Calculate average gain and loss (first $period values)
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        // Calculate RSI using smoothed averages
        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = ($avgGain * ($period - 1) + $gains[$i]) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0) {
            return $avgGain > 0 ? 100 : 50;
        }

        $rs = $avgGain / $avgLoss;
        $rsi = 100 - (100 / (1 + $rs));

        return $rsi;
    }

    /**
     * Calculate MACD (Moving Average Convergence Divergence)
     * Returns array with 'macd', 'signal', 'histogram'
     */
    protected function calculateMACD(array $prices, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $emaFast = $this->calculateEMA($prices, $fast);
        $emaSlow = $this->calculateEMA($prices, $slow);

        if (!$emaFast || !$emaSlow) {
            return ['macd' => 0, 'signal' => 0, 'histogram' => 0];
        }

        $macd = $emaFast - $emaSlow;

        // For signal line, we need to calculate EMA of MACD values
        // This is a simplified approach: use the single MACD value as baseline
        $signalLine = $macd; // In production, this would require MACD history

        return [
            'macd' => $macd,
            'signal' => $signalLine,
            'histogram' => $macd - $signalLine,
        ];
    }

    /**
     * Calculate slope of an indicator over a lookback period
     * Returns the rate of change (positive = rising, negative = falling)
     */
    protected function calculateSlope(array $prices, int $emaPeriod, int $lookbackPeriod): float
    {
        if (count($prices) < max($emaPeriod + $lookbackPeriod, $lookbackPeriod + 1)) {
            return 0;
        }

        // Get EMA values for recent candles
        $recentPrices = array_slice($prices, 0, $emaPeriod + $lookbackPeriod);
        $emaRecent = $this->calculateEMA(array_slice($prices, 0, $emaPeriod), $emaPeriod);

        // Get EMA value from lookback periods ago
        $historicalPrices = array_slice($prices, $lookbackPeriod, $emaPeriod);
        $emaHistorical = $this->calculateEMA($historicalPrices, $emaPeriod);

        if (!$emaRecent || !$emaHistorical) {
            return 0;
        }

        // Calculate rate of change
        $slope = ($emaRecent - $emaHistorical) / $emaHistorical;

        return $slope;
    }
}
