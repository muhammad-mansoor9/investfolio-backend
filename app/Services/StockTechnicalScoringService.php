<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockIndicator;

class StockTechnicalScoringService
{
    /**
     * Calculate trend score from EMA structure
     * Checks if price > EMA20 > EMA50 > EMA100 > EMA200
     */
    public function calculateTrendScore(array $indicators): float
    {
        $score = 0;
        $maxScore = 100;

        // Extract relevant values from indicators JSON
        $price = $indicators['price'] ?? null;
        $ema20 = $indicators['EMA_20'] ?? null;
        $ema50 = $indicators['EMA_50'] ?? null;
        $ema100 = $indicators['EMA_100'] ?? null;
        $ema200 = $indicators['EMA_200'] ?? null;

        if (!$price) {
            return 0;
        }

        // Check price position relative to EMAs (40 points)
        if ($ema20 && $price > $ema20) {
            $score += 10;
        }
        if ($ema50 && $price > $ema50) {
            $score += 10;
        }
        if ($ema100 && $price > $ema100) {
            $score += 10;
        }
        if ($ema200 && $price > $ema200) {
            $score += 10;
        }

        // Check EMA alignment (40 points)
        if ($ema20 && $ema50 && $ema20 > $ema50) {
            $score += 10;
        }
        if ($ema50 && $ema100 && $ema50 > $ema100) {
            $score += 10;
        }
        if ($ema100 && $ema200 && $ema100 > $ema200) {
            $score += 10;
        }

        // EMA slopes (20 points) — would require historical data
        // For now, allocate based on overall structure
        if ($score >= 60) {
            $score += 10;
        }

        return min($score, 100);
    }

    /**
     * Calculate momentum score from RSI and MACD
     */
    public function calculateMomentumScore(array $indicators): float
    {
        $score = 0;

        $rsi = $indicators['RSI'] ?? null;
        $macdLine = $indicators['MACD_line'] ?? null;
        $macdSignal = $indicators['MACD_signal'] ?? null;
        $macdHistogram = $indicators['MACD_histogram'] ?? null;

        // RSI evaluation (50 points)
        if ($rsi) {
            if ($rsi > 50 && $rsi < 70) {
                $score += 35; // Healthy bullish
            } elseif ($rsi >= 70 && $rsi < 80) {
                $score += 30; // Strong but extended
            } elseif ($rsi >= 80) {
                $score += 20; // Overbought
            } elseif ($rsi >= 40 && $rsi <= 50) {
                $score += 15; // Neutral recovering
            }
        }

        // MACD evaluation (50 points)
        if ($macdLine && $macdSignal) {
            if ($macdLine > $macdSignal && $macdHistogram > 0) {
                $score += 35; // Bullish accelerating
            } elseif ($macdLine > $macdSignal) {
                $score += 25; // Bullish cooling
            }
        }

        return min($score, 100);
    }

    /**
     * Calculate trend strength from ADX, DI+, DI-
     */
    public function calculateTrendStrengthScore(array $indicators): float
    {
        $score = 0;

        $adx = $indicators['ADX'] ?? null;
        $diPlus = $indicators['DI+'] ?? null;
        $diMinus = $indicators['DI-'] ?? null;

        // ADX evaluation (60 points)
        if ($adx) {
            if ($adx >= 30) {
                $score += 60; // Strong trend
            } elseif ($adx >= 25) {
                $score += 50; // Meaningful trend
            } elseif ($adx >= 20) {
                $score += 40; // Developing trend
            }
        }

        // DI+ > DI- confirmation (40 points)
        if ($diPlus && $diMinus && $diPlus > $diMinus) {
            $score += 40;
        }

        return min($score, 100);
    }

    /**
     * Calculate volume score from relative volume
     */
    public function calculateVolumeScore(array $indicators): float
    {
        $score = 0;

        $volume = $indicators['volume'] ?? null;
        $volumeSMA20 = $indicators['volume_SMA20'] ?? null;

        if (!$volume || !$volumeSMA20) {
            return 50; // Neutral if insufficient data
        }

        $relativeVolume = $volume / $volumeSMA20;

        // Relative volume evaluation
        if ($relativeVolume >= 1.5) {
            $score = 90; // Very high volume
        } elseif ($relativeVolume >= 1.3) {
            $score = 80; // High volume
        } elseif ($relativeVolume >= 1.0) {
            $score = 65; // Above average
        } elseif ($relativeVolume >= 0.8) {
            $score = 50; // Average
        } else {
            $score = 30; // Low volume
        }

        return $score;
    }

    /**
     * Get indicators for a stock on a given date
     */
    public function getIndicators(string $stockId, string $date, string $timeframe = 'daily'): ?array
    {
        $indicator = StockIndicator::where('stock_id', $stockId)
            ->where('date', $date)
            ->where('timeframe', $timeframe)
            ->first();

        return $indicator?->data;
    }
}
