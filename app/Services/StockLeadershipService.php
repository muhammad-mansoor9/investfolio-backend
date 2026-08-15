<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\SectorStockScore;
use Illuminate\Support\Collection;

class StockLeadershipService
{
    public function __construct(
        private StockTechnicalScoringService $technicalService,
        private StockSettlementScoringService $settlementService,
    ) {}

    /**
     * Classify stock leadership state based on metrics
     */
    public function classifyLeadershipState(
        float $stockRSMarket,
        float $stockRSSector,
        array $technicalScores,
        array $settlementScores,
    ): string {
        $rsMarketStrong = $stockRSMarket >= 100.5;
        $rsSectorStrong = $stockRSSector >= 100.5;
        $trendStrong = ($technicalScores['trend_score'] ?? 0) >= 70;
        $momentumStrong = ($technicalScores['momentum_score'] ?? 0) >= 65;
        $settlementConfirming = ($settlementScores['settlement_score'] ?? 0) >= 65;

        // Sector Leader: strong on both market and sector RS, constructive technicals
        if ($rsMarketStrong && $rsSectorStrong && $trendStrong && $momentumStrong) {
            return 'Sector Leader';
        }

        // Emerging Leader: sector RS improving rapidly, market RS positive
        if ($rsSectorStrong && $rsMarketStrong && $momentumStrong && !$trendStrong) {
            return 'Emerging Leader';
        }

        // Confirmed: sector RS positive, trend constructive, momentum healthy
        if ($rsSectorStrong && $trendStrong && $momentumStrong) {
            return 'Confirmed';
        }

        // Sector Follower: sector RS weak but market RS decent
        if (!$rsSectorStrong && $rsMarketStrong) {
            return 'Sector Follower';
        }

        // Extended: RS strong but momentum excessively stretched
        if ($rsMarketStrong && ($technicalScores['momentum_score'] ?? 0) >= 80) {
            return 'Extended';
        }

        // Cooling: structurally strong but momentum deteriorating
        if ($trendStrong && !$momentumStrong) {
            return 'Cooling';
        }

        // Neutral: mixed signals
        if (
            ($rsMarketStrong || $rsSectorStrong) ||
            ($trendStrong || $momentumStrong)
        ) {
            return 'Neutral';
        }

        // Weak: not meeting any thresholds
        return 'Weak';
    }

    /**
     * Determine market support context for the stock
     */
    public function determineMarketSupport(string $marketRegime): string
    {
        return match ($marketRegime) {
            'Bullish' => 'Supportive',
            'Neutral' => 'Mixed',
            'Bearish' => 'Unsupportive',
            default => 'Unknown',
        };
    }

    /**
     * Determine sector support context for the stock
     */
    public function determineSectorSupport(
        string $sectorStatus,
        float $sectorStrengthScore,
        float $sectorRSMomentum,
    ): string {
        if ($sectorStatus === 'Leading' && $sectorStrengthScore >= 70 && $sectorRSMomentum >= 101) {
            return 'Strong';
        }

        if ($sectorStatus === 'Improving' && $sectorRSMomentum >= 101) {
            return 'Supportive';
        }

        if ($sectorStatus === 'Weakening' || $sectorStatus === 'Lagging') {
            return 'Weak';
        }

        return 'Neutral';
    }

    /**
     * Get top stocks to watch across all sectors
     */
    public function getTopStocksToWatch(int $limit = 20): Collection
    {
        return SectorStockScore::daily()
            ->where('date', now()->toDateString())
            ->with(['stock', 'sector'])
            ->orderBy('watch_score', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get top stocks within a specific sector
     */
    public function getTopStocksInSector(string $sectorId, int $limit = 10): Collection
    {
        return SectorStockScore::forSector($sectorId)
            ->daily()
            ->where('date', now()->toDateString())
            ->with(['stock'])
            ->orderBy('watch_score', 'desc')
            ->limit($limit)
            ->get();
    }
}
