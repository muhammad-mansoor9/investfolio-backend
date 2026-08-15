<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\UinSettlementData;
use App\Models\Sector;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class StockUnifiedAnalysisService
{
    public function __construct(
        private StockRelativeStrengthService $rsService,
        private StockIndicatorService $indicatorService,
        private StockTechnicalScoringService $technicalService,
        private StockSettlementScoringService $settlementService,
        private FIPILIPIFlowService $fipiLipiService,
        private StockLeadershipService $leadershipService,
    ) {}

    /**
     * Execute complete unified analysis for a stock on a given date
     * Returns all four dimensions + stock strength + watch score + simple state
     */
    public function analyzeStock(string $stockId, string $date, ?string $sectorId = null): array
    {
        $stock = Stock::findOrFail($stockId);
        if (!$sectorId) {
            $sectorId = $stock->sector_id;
        }

        // Fetch indicators (single source of truth for technical data)
        $indicators = $this->indicatorService->getIndicators($stockId, '1D', $date);
        if (!$indicators) {
            return $this->emptyAnalysisResult($stockId);
        }

        // Calculate four dimensions
        $relativeLeadershipScore = $this->calculateRelativeLeadershipScore($stockId, $date, $sectorId);
        $trendStructureScore = $this->calculateTrendStructureScore($indicators);
        $momentumScore = $this->calculateMomentumScore($indicators);
        $participationScore = $this->calculateParticipationScore($stockId, $date, $sectorId);

        // Calculate Stock Strength (weighted composite)
        $stockStrengthScore = $this->calculateStockStrengthScore(
            $relativeLeadershipScore,
            $trendStructureScore,
            $momentumScore,
            $participationScore
        );

        // Calculate simple state
        $simpleState = $this->calculateSimpleState($trendStructureScore, $momentumScore, $relativeLeadershipScore);

        // Leadership state and market phase (conditional on strength + technicals) - for metadata only
        $leadershipState = $this->classifyLeadershipState($stockId, $date, $sectorId, $indicators);
        $marketPhase = $this->classifyMarketPhase($indicators, $relativeLeadershipScore, $momentumScore);

        // Watch score is independent
        $watchScore = $this->calculateWatchScore($stockStrengthScore, $marketPhase, $momentumScore);

        // RS values for context
        $rsMarket = $this->getRSMarket($stockId, $date);
        $rsSector = $this->getRSSector($stockId, $sectorId, $date);

        // Build comprehensive metadata
        $metadata = $this->buildExplainabilityMetadata(
            $stockId,
            $date,
            $sectorId,
            $indicators,
            $relativeLeadershipScore,
            $trendStructureScore,
            $momentumScore,
            $participationScore,
            $rsMarket,
            $rsSector
        );

        return [
            'stock_id' => $stockId,
            'symbol' => $stock->symbol,
            'date' => $date,
            'relative_leadership_score' => round($relativeLeadershipScore, 2),
            'trend_structure_score' => round($trendStructureScore, 2),
            'momentum_score' => round($momentumScore, 2),
            'participation_score' => round($participationScore, 2),
            'stock_strength_score' => round($stockStrengthScore, 2),
            'watch_score' => round($watchScore, 2),
            'simple_state' => $simpleState,
            'stock_rs_market' => round($rsMarket, 3),
            'stock_rs_sector' => round($rsSector, 3),
            'metadata' => $metadata,
        ];
    }

    /**
     * DIMENSION 1: Relative Leadership Score (35% of Stock Strength)
     * Stock vs KSE100 RS + Stock vs Sector RS + RS Momentum + RS Acceleration
     */
    public function calculateRelativeLeadershipScore(string $stockId, string $date, string $sectorId): float
    {
        $rsMarket = $this->getRSMarket($stockId, $date);
        $rsSector = $this->getRSSector($stockId, $sectorId, $date);

        $score = 0;

        // RS vs Market (50% of this dimension)
        if ($rsMarket !== null) {
            $marketScore = $this->scoreRS($rsMarket);
            $score += $marketScore * 0.5;
        }

        // RS vs Sector (50% of this dimension)
        if ($rsSector !== null) {
            $sectorScore = $this->scoreRS($rsSector);
            $score += $sectorScore * 0.5;
        }

        return min($score, 100);
    }

    /**
     * DIMENSION 2: Trend Structure Score (30% of Stock Strength)
     * Price position (EMA20/50/100/200) + EMA alignment + ADX + DI+/DI-
     */
    public function calculateTrendStructureScore(array $indicators): float
    {
        $score = 0;

        $price = $indicators['price'] ?? null;
        $ema20 = $indicators['EMA_20'] ?? null;
        $ema50 = $indicators['EMA_50'] ?? null;
        $ema100 = $indicators['EMA_100'] ?? null;
        $ema200 = $indicators['EMA_200'] ?? null;
        $adx = $indicators['ADX'] ?? null;
        $diPlus = $indicators['DI+'] ?? null;
        $diMinus = $indicators['DI-'] ?? null;

        if (!$price) {
            return 0;
        }

        // Price position weight = 50% of trend structure
        $pricePositionScore = 0;
        $positionCount = 0;

        if ($ema20 && $price > $ema20) {
            $pricePositionScore += 25;
            $positionCount++;
        }
        if ($ema50 && $price > $ema50) {
            $pricePositionScore += 25;
            $positionCount++;
        }
        if ($ema100 && $price > $ema100) {
            $pricePositionScore += 25;
            $positionCount++;
        }
        if ($ema200 && $price > $ema200) {
            $pricePositionScore += 25;
            $positionCount++;
        }

        $score += $pricePositionScore * 0.5;

        // EMA alignment weight = 30% of trend structure
        $alignmentScore = 0;
        $alignmentCount = 0;

        if ($ema20 && $ema50 && $ema20 > $ema50) {
            $alignmentScore += 33.33;
            $alignmentCount++;
        }
        if ($ema50 && $ema100 && $ema50 > $ema100) {
            $alignmentScore += 33.33;
            $alignmentCount++;
        }
        if ($ema100 && $ema200 && $ema100 > $ema200) {
            $alignmentScore += 33.34;
            $alignmentCount++;
        }

        $score += $alignmentScore * 0.3;

        // ADX + DI weight = 20% of trend structure
        $trendStrengthScore = 0;

        if ($adx) {
            if ($adx >= 30) {
                $trendStrengthScore += 60;
            } elseif ($adx >= 25) {
                $trendStrengthScore += 45;
            } elseif ($adx >= 20) {
                $trendStrengthScore += 30;
            }
        }

        if ($diPlus && $diMinus && $diPlus > $diMinus) {
            $trendStrengthScore += 40;
        }

        $score += min($trendStrengthScore, 100) * 0.2;

        return min($score, 100);
    }

    /**
     * DIMENSION 3: Momentum Score (20% of Stock Strength)
     * RSI level/direction + MACD state/direction + Acceleration vs cooling
     */
    public function calculateMomentumScore(array $indicators): float
    {
        $score = 0;

        $rsi = $indicators['RSI'] ?? null;
        $macdLine = $indicators['MACD_line'] ?? null;
        $macdSignal = $indicators['MACD_signal'] ?? null;
        $macdHistogram = $indicators['MACD_histogram'] ?? null;

        // RSI evaluation (50% of momentum)
        if ($rsi) {
            if ($rsi > 65 && $rsi < 75) {
                // Healthy bullish, not overbought
                $score += 50;
            } elseif ($rsi >= 50 && $rsi <= 65) {
                // Building momentum
                $score += 40;
            } elseif ($rsi >= 75 && $rsi < 85) {
                // Strong but extended
                $score += 35;
            } elseif ($rsi >= 85) {
                // Overbought
                $score += 20;
            } elseif ($rsi >= 40 && $rsi <= 50) {
                // Neutral recovering
                $score += 25;
            } elseif ($rsi < 40) {
                // Weak
                $score += 5;
            }
        }

        // MACD evaluation (50% of momentum)
        if ($macdLine && $macdSignal) {
            if ($macdLine > $macdSignal) {
                // Bullish signal
                if ($macdHistogram && $macdHistogram > 0) {
                    // Accelerating
                    $score += 50;
                } else {
                    // Positive but cooling
                    $score += 35;
                }
            } elseif ($macdLine < $macdSignal) {
                // Bearish signal
                if ($macdHistogram && $macdHistogram < 0) {
                    // Deteriorating
                    $score += 10;
                } else {
                    // Transitioning
                    $score += 5;
                }
            }
        }

        return min($score, 100);
    }

    /**
     * DIMENSION 4: Participation Score (15% of Stock Strength)
     * Relative Volume + UIN Participation + Sector FIPI Flow Context + Price/RS Confirmation
     */
    public function calculateParticipationScore(string $stockId, string $date, string $sectorId): float
    {
        $score = 0;

        // Relative volume (40% of participation)
        $volumeScore = $this->calculateRelativeVolumeScore($stockId, $date);
        $score += $volumeScore * 0.4;

        // UIN participation relative to own 20D baseline (40% of participation)
        $uinScore = $this->calculateUINParticipationScore($stockId, $date);
        $score += $uinScore * 0.4;

        // Sector FIPI flow context (20% of participation, context only)
        $fipiScore = $this->calculateSectorFIPIContextScore($sectorId, $date);
        $score += $fipiScore * 0.2;

        return min($score, 100);
    }

    /**
     * Calculate Stock Strength Score: weighted average of four dimensions
     * 35% Relative Leadership + 30% Trend Structure + 20% Momentum + 15% Participation
     */
    public function calculateStockStrengthScore(float $relL, float $tS, float $mS, float $pS): float
    {
        $weights = Config::get('market_pulse.stock_analysis.stock_strength_score.weights', [
            'relative_leadership' => 35,
            'trend_structure' => 30,
            'momentum' => 20,
            'participation' => 15,
        ]);

        $total = $weights['relative_leadership'] + $weights['trend_structure'] + $weights['momentum'] + $weights['participation'];

        $score = (
            ($relL * $weights['relative_leadership']) +
            ($tS * $weights['trend_structure']) +
            ($mS * $weights['momentum']) +
            ($pS * $weights['participation'])
        ) / $total;

        return min($score, 100);
    }

    /**
     * Calculate Watch Score: Independent of Stock Strength
     * Answers: "How interesting is this for investigation NOW?"
     * Emphasizes emerging setups and phase transitions
     */
    public function calculateWatchScore(float $stockStrength, string $marketPhase, float $momentumScore): float
    {
        $weights = Config::get('market_pulse.stock_analysis.watch_score', [
            'stock_strength_base_weight' => 60,
            'market_phase_adjustment' => 20,
            'momentum_acceleration_weight' => 20,
        ]);

        $score = $stockStrength * ($weights['stock_strength_base_weight'] / 100);

        // Phase transition boost
        $phaseBoost = match ($marketPhase) {
            'Early Uptrend' => 25,
            'Accumulation' => 20,
            'Advancing' => 15,
            'Extended' => 5,
            'Distribution' => 10,
            'Declining' => 0,
            default => 5,
        };

        $score += $phaseBoost * ($weights['market_phase_adjustment'] / 100);

        // Momentum acceleration boost
        if ($momentumScore > 65) {
            $accelerationBoost = ($momentumScore - 50) / 50 * 25;
            $score += $accelerationBoost * ($weights['momentum_acceleration_weight'] / 100);
        }

        return min($score, 100);
    }

    /**
     * Calculate Simple State: Condensed stock state classification
     * Emerging: trend < 50 AND leadership < 60
     * Strong: trend >= 50 AND momentum >= 50 AND leadership >= 60
     * Extended: trend >= 70 AND momentum >= 70
     * Cooling: (trend >= 50 BUT momentum < 50) OR (leadership >= 60 BUT momentum < 40)
     * Weak: all components < 50
     */
    public function calculateSimpleState(float $trendScore, float $momentumScore, float $leadershipScore): string
    {
        // Check Emerging first
        if ($trendScore < 50 && $leadershipScore < 60) {
            return 'Emerging';
        }

        // Check Extended next
        if ($trendScore >= 70 && $momentumScore >= 70) {
            return 'Extended';
        }

        // Check Strong
        if ($trendScore >= 50 && $momentumScore >= 50 && $leadershipScore >= 60) {
            return 'Strong';
        }

        // Check Cooling
        if (($trendScore >= 50 && $momentumScore < 50) || ($leadershipScore >= 60 && $momentumScore < 40)) {
            return 'Cooling';
        }

        // Check Weak (all components < 50)
        if ($trendScore < 50 && $momentumScore < 50 && $leadershipScore < 50) {
            return 'Weak';
        }

        // Default fallback
        return 'Neutral';
    }

    /**
     * Classify stock leadership state based on RS, technicals, and settlement
     */
    public function classifyLeadershipState(string $stockId, string $date, string $sectorId, array $indicators): string
    {
        $rsMarket = $this->getRSMarket($stockId, $date);
        $rsSector = $this->getRSSector($stockId, $sectorId, $date);

        $rsMarketStrong = $rsMarket && $rsMarket >= 101.0;
        $rsSectorStrong = $rsSector && $rsSector >= 101.0;

        $trendScore = $this->calculateTrendStructureScore($indicators);
        $momentumScore = $this->calculateMomentumScore($indicators);
        $settlementScore = $this->settlementService->calculateSettlementScore($stockId, $date);

        $trendStrong = $trendScore >= 70;
        $momentumStrong = $momentumScore >= 65;
        $settlementConfirming = $settlementScore >= 65;

        // Strong Leader: strong on both market and sector RS, constructive technicals
        if ($rsMarketStrong && $rsSectorStrong && $trendStrong && $momentumStrong) {
            return 'Strong Leader';
        }

        // Emerging Leader: sector RS improving rapidly, market RS positive
        if ($rsSectorStrong && $rsMarketStrong && $momentumStrong && !$trendStrong) {
            return 'Emerging Leader';
        }

        // Confirmed Leader: sector RS positive, trend constructive, momentum healthy
        if ($rsSectorStrong && $trendStrong && $momentumStrong) {
            return 'Confirmed Leader';
        }

        // Sector Follower: sector RS weak but market RS decent
        if (!$rsSectorStrong && $rsMarketStrong) {
            return 'Sector Follower';
        }

        // Extended: RS strong but momentum excessively stretched
        if ($rsMarketStrong && $momentumScore >= 80) {
            return 'Extended';
        }

        // Cooling: structurally strong but momentum deteriorating
        if ($trendStrong && !$momentumStrong) {
            return 'Cooling';
        }

        // Weak: not meeting major thresholds
        return 'Weak';
    }

    /**
     * Classify market phase using Dow Theory-inspired logic
     * Answers: "Where is this stock in its cycle?"
     */
    public function classifyMarketPhase(array $indicators, float $rsLeadership, float $momentumScore): string
    {
        $price = $indicators['price'] ?? null;
        $ema20 = $indicators['EMA_20'] ?? null;
        $ema50 = $indicators['EMA_50'] ?? null;
        $ema100 = $indicators['EMA_100'] ?? null;
        $ema200 = $indicators['EMA_200'] ?? null;
        $rsi = $indicators['RSI'] ?? null;
        $adx = $indicators['ADX'] ?? null;

        if (!$price) {
            return 'Unknown';
        }

        // Extended: RSI > 75, price far above EMA20, momentum slowing
        if ($rsi && $rsi > 75 && $ema20 && $price > $ema20 * 1.05 && $momentumScore < 50) {
            return 'Extended';
        }

        // Declining: Price below EMA50, EMA structure bearish, RSI weak
        if ($ema50 && $price < $ema50 && $rsi && $rsi < 40) {
            return 'Declining';
        }

        // Distribution: Price relatively high, momentum deteriorating, breadth cooling
        if ($ema100 && $price > $ema100 && $momentumScore >= 60 && $momentumScore < 65) {
            return 'Distribution';
        }

        // Advancing: Price above EMA50, EMA structure bullish, ADX above 25, volume confirming
        if ($ema50 && $price > $ema50 && $ema20 && $ema20 > $ema50 && $adx && $adx >= 25) {
            return 'Advancing';
        }

        // Early Uptrend: Price above EMA20, EMA20 slope positive, momentum building
        if ($ema20 && $price > $ema20 && $momentumScore >= 50 && $momentumScore < 70) {
            return 'Early Uptrend';
        }

        // Accumulation: Price stabilizing, volume improving, momentum recovering
        if ($rsLeadership > 0 && $momentumScore >= 40 && $momentumScore < 55) {
            return 'Accumulation';
        }

        return 'Unknown';
    }

    /**
     * Build comprehensive explainability metadata with all disaggregated values
     */
    public function buildExplainabilityMetadata(
        string $stockId,
        string $date,
        string $sectorId,
        array $indicators,
        float $relL,
        float $tS,
        float $mS,
        float $pS,
        ?float $rsMarket,
        ?float $rsSector
    ): array
    {
        return [
            'relative_leadership' => [
                'stock_rs_market' => $rsMarket,
                'stock_rs_sector' => $rsSector,
                'rs_market_score_contribution' => $rsMarket ? $this->scoreRS($rsMarket) * 0.5 : 0,
                'rs_sector_score_contribution' => $rsSector ? $this->scoreRS($rsSector) * 0.5 : 0,
                'total_dimension_score' => round($relL, 2),
            ],
            'trend' => [
                'price' => $indicators['price'] ?? null,
                'ema_20' => $indicators['EMA_20'] ?? null,
                'ema_50' => $indicators['EMA_50'] ?? null,
                'ema_100' => $indicators['EMA_100'] ?? null,
                'ema_200' => $indicators['EMA_200'] ?? null,
                'adx' => $indicators['ADX'] ?? null,
                'di_plus' => $indicators['DI+'] ?? null,
                'di_minus' => $indicators['DI-'] ?? null,
                'price_above_ema20' => ($indicators['price'] ?? null) && ($indicators['EMA_20'] ?? null) ? ($indicators['price'] > $indicators['EMA_20']) : null,
                'price_above_ema50' => ($indicators['price'] ?? null) && ($indicators['EMA_50'] ?? null) ? ($indicators['price'] > $indicators['EMA_50']) : null,
                'ema_aligned' => $this->checkEMAAlignment($indicators),
                'total_dimension_score' => round($tS, 2),
            ],
            'momentum' => [
                'rsi' => $indicators['RSI'] ?? null,
                'rsi_level_interpretation' => $this->interpretRSILevel($indicators['RSI'] ?? null),
                'macd_line' => $indicators['MACD_line'] ?? null,
                'macd_signal' => $indicators['MACD_signal'] ?? null,
                'macd_histogram' => $indicators['MACD_histogram'] ?? null,
                'macd_bullish' => ($indicators['MACD_line'] ?? null) && ($indicators['MACD_signal'] ?? null) ? ($indicators['MACD_line'] > $indicators['MACD_signal']) : null,
                'macd_accelerating' => ($indicators['MACD_histogram'] ?? null) ? ($indicators['MACD_histogram'] > 0) : null,
                'total_dimension_score' => round($mS, 2),
            ],
            'participation' => [
                'relative_volume' => $this->calculateRelativeVolumeRatio($stockId, $date),
                'relative_volume_score' => round($this->calculateRelativeVolumeScore($stockId, $date), 2),
                'uin_participation' => $this->getUINParticipationDetails($stockId, $date),
                'uin_participation_score' => round($this->calculateUINParticipationScore($stockId, $date), 2),
                'sector_fipi_flow' => $this->getSectorFIPIFlowDetails($sectorId, $date),
                'sector_fipi_context_score' => round($this->calculateSectorFIPIContextScore($sectorId, $date), 2),
                'total_dimension_score' => round($pS, 2),
            ],
            'calculation_timestamp' => now()->toIso8601String(),
        ];
    }

    // ===== HELPER METHODS FOR CALCULATION =====

    private function getRSMarket(?string $stockId, string $date): ?float
    {
        if (!$stockId) {
            return null;
        }

        try {
            $result = $this->rsService->calculateStockRSMarket(
                $stockId,
                Config::get('market_pulse.sector_rotation.benchmark_id', 'kse100'),
                $date
            );
            return $result['stock_rs_market'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getRSSector(?string $stockId, ?string $sectorId, string $date): ?float
    {
        if (!$stockId || !$sectorId) {
            return null;
        }

        try {
            $result = $this->rsService->calculateStockRSSector($stockId, $sectorId, $date);
            return $result['stock_rs_sector'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function scoreRS(float $rs): float
    {
        // RS linear scoring: 100 = stock = benchmark
        // 102 = 2% stronger, 98 = 2% weaker
        // Map to 0-100 scale

        if ($rs >= 105) {
            return 100; // Excellent outperformance
        } elseif ($rs >= 102) {
            return 80; // Good outperformance
        } elseif ($rs >= 101) {
            return 70; // Slight outperformance
        } elseif ($rs >= 100) {
            return 60; // Neutral/at parity
        } elseif ($rs >= 98) {
            return 40; // Slight underperformance
        } elseif ($rs >= 95) {
            return 20; // Underperformance
        } else {
            return 5; // Weak underperformance
        }
    }

    private function calculateRelativeVolumeScore(string $stockId, string $date): float
    {
        $ratio = $this->calculateRelativeVolumeRatio($stockId, $date);
        if ($ratio === null) {
            return 50; // Neutral default
        }

        if ($ratio >= 1.5) {
            return 90; // Very high volume
        } elseif ($ratio >= 1.3) {
            return 80; // High volume
        } elseif ($ratio >= 1.0) {
            return 65; // Above average
        } elseif ($ratio >= 0.8) {
            return 50; // Average
        } else {
            return 30; // Low volume
        }
    }

    private function calculateRelativeVolumeRatio(?string $stockId, string $date): ?float
    {
        if (!$stockId) {
            return null;
        }

        try {
            $current = StockPrice::where('stock_id', $stockId)
                ->where('date', $date)
                ->first();

            if (!$current) {
                return null;
            }

            $startDate = Carbon::parse($date)->subDays(20)->toDateString();

            $sma20 = StockPrice::where('stock_id', $stockId)
                ->whereBetween('date', [$startDate, $date])
                ->where('date', '!=', $date)
                ->avg('volume');

            if (!$sma20 || $sma20 == 0) {
                return null;
            }

            return $current->volume / $sma20;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function calculateUINParticipationScore(string $stockId, string $date): float
    {
        $details = $this->getUINParticipationDetails($stockId, $date);
        if (!$details || !isset($details['current_percentage'])) {
            return 50; // Neutral default
        }

        $delta = $details['current_percentage'] - ($details['baseline_20d_percentage'] ?? 0);

        if ($delta >= 20) {
            return 90;
        } elseif ($delta >= 10) {
            return 75;
        } elseif ($delta >= 0) {
            return 60;
        } elseif ($delta >= -10) {
            return 40;
        } else {
            return 20;
        }
    }

    private function getUINParticipationDetails(string $stockId, string $date): ?array
    {
        try {
            $current = UinSettlementData::where('stock_id', $stockId)
                ->where('settlement_date', $date)
                ->first();

            if (!$current) {
                return null;
            }

            $baseline = $this->settlementService->getBaselineSettlement($stockId, $date, 20);

            return [
                'current_percentage' => (float) $current->uin_percentage_value,
                'current_settlement_value' => (float) $current->uin_settlement_value,
                'baseline_20d_percentage' => $baseline ? (float) $baseline['percentage'] : null,
                'baseline_20d_avg_value' => $baseline ? (float) $baseline['avg_settlement_value'] : null,
                'percentage_delta' => $baseline
                    ? (float) $current->uin_percentage_value - (float) $baseline['percentage']
                    : null,
                'value_ratio' => $baseline && $baseline['avg_settlement_value']
                    ? (float) $current->uin_settlement_value / (float) $baseline['avg_settlement_value']
                    : null,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function calculateSectorFIPIContextScore(string $sectorId, string $date): float
    {
        $flow = $this->getSectorFIPIFlowDetails($sectorId, $date);
        if (!$flow || !$flow['available']) {
            return 50; // Neutral if no data
        }

        // Sector flow is context only - never attribute to individual stock
        // Use only for confirming participation trends
        $flow_1d = $flow['flow_1d'] ?? 0;

        if ($flow_1d > 0) {
            if ($flow_1d > 50000) {
                return 70; // Accumulation context
            } elseif ($flow_1d > 10000) {
                return 60; // Slight accumulation
            } else {
                return 50; // Neutral
            }
        } else {
            if ($flow_1d < -50000) {
                return 30; // Distribution context
            } elseif ($flow_1d < -10000) {
                return 40; // Slight distribution
            } else {
                return 50; // Neutral
            }
        }
    }

    private function getSectorFIPIFlowDetails(string $sectorId, string $date): ?array
    {
        try {
            $flowData = $this->fipiLipiService->getFlowForSector($sectorId, $date);

            if (!$flowData['available']) {
                return [
                    'available' => false,
                    'flow_1d' => null,
                    'flow_5d' => null,
                    'flow_20d' => null,
                ];
            }

            return [
                'available' => true,
                'flow_1d' => $flowData['flows']['1d'] ?? null,
                'flow_5d' => $flowData['flows']['5d'] ?? null,
                'flow_20d' => $flowData['flows']['20d'] ?? null,
                'currency' => $flowData['currency'] ?? 'USD',
            ];
        } catch (\Exception $e) {
            return ['available' => false, 'flow_1d' => null, 'flow_5d' => null, 'flow_20d' => null];
        }
    }

    private function checkEMAAlignment(array $indicators): bool
    {
        $ema20 = $indicators['EMA_20'] ?? null;
        $ema50 = $indicators['EMA_50'] ?? null;
        $ema100 = $indicators['EMA_100'] ?? null;
        $ema200 = $indicators['EMA_200'] ?? null;

        if (!$ema20 || !$ema50 || !$ema100 || !$ema200) {
            return false;
        }

        return $ema20 > $ema50 && $ema50 > $ema100 && $ema100 > $ema200;
    }

    private function interpretRSILevel(?float $rsi): string
    {
        if ($rsi === null) {
            return 'Unknown';
        }

        if ($rsi > 80) {
            return 'Overbought';
        } elseif ($rsi > 70) {
            return 'Strong';
        } elseif ($rsi > 65) {
            return 'Healthy Bullish';
        } elseif ($rsi > 50) {
            return 'Building Momentum';
        } elseif ($rsi > 40) {
            return 'Neutral Recovering';
        } elseif ($rsi > 30) {
            return 'Weak';
        } else {
            return 'Oversold';
        }
    }

    private function emptyAnalysisResult(string $stockId): array
    {
        $stock = Stock::find($stockId);

        return [
            'stock_id' => $stockId,
            'symbol' => $stock?->symbol ?? 'UNKNOWN',
            'date' => now()->toDateString(),
            'relative_leadership_score' => 0,
            'trend_structure_score' => 0,
            'momentum_score' => 0,
            'participation_score' => 0,
            'stock_strength_score' => 0,
            'watch_score' => 0,
            'simple_state' => 'Weak',
            'stock_rs_market' => null,
            'stock_rs_sector' => null,
            'metadata' => [
                'error' => 'Insufficient indicator data',
                'calculation_timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
