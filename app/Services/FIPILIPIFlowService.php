<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FIPILIPIFlowService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const INSTITUTIONAL_INVESTOR_TYPES = [
        'FOREIGN CORPORATES',
        'MUTUAL FUNDS',
        'INSURANCE COMPANIES'
    ];

    /**
     * Get institutional flow data for a sector
     * Uses fipi_lipi_sector_mappings to find mapped external sector names
     *
     * Expected table schema:
     * CREATE TABLE fipi_lipi_sector_mappings (
     *     id UUID PRIMARY KEY,
     *     sector_id UUID REFERENCES sectors(id),
     *     external_sector_name VARCHAR(150) UNIQUE,
     *     is_aggregate BOOLEAN DEFAULT false,
     *     notes TEXT,
     *     created_at TIMESTAMP,
     *     updated_at TIMESTAMP
     * );
     *
     * @param string $sectorId Sector UUID
     * @param string $date Trading date (Y-m-d)
     * @return array Flow data with 1d, 5d, 20d net values in USD
     */
    public function getFlowForSector(string $sectorId, string $date): array
    {
        if (!$this->flowAvailable($sectorId)) {
            return [
                'sector_id' => $sectorId,
                'date' => $date,
                'available' => false,
                'message' => 'No FIPI/LIPI mapping exists for this sector',
                'flows' => null,
            ];
        }

        $cacheKey = "fipi_lipi:flow:{$sectorId}:{$date}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sectorId, $date) {
            Log::debug('Calculating FIPI/LIPI flow for sector', ['sector_id' => $sectorId, 'date' => $date]);

            $mapping = $this->getMappingBySectorId($sectorId);
            if (!$mapping || $mapping->is_aggregate) {
                return [
                    'sector_id' => $sectorId,
                    'date' => $date,
                    'available' => false,
                    'message' => 'Sector has aggregate mapping; flow cannot be assigned',
                    'flows' => null,
                ];
            }

            $externalName = $mapping->external_sector_name;
            $refDate = Carbon::parse($date);

            $flows = [
                '1d' => $this->calculateNetFlow($externalName, $date, 1),
                '5d' => $this->calculateNetFlow($externalName, $date, 5),
                '20d' => $this->calculateNetFlow($externalName, $date, 20),
            ];

            return [
                'sector_id' => $sectorId,
                'date' => $date,
                'external_sector_name' => $externalName,
                'available' => true,
                'flows' => $flows,
                'currency' => 'USD',
            ];
        });
    }

    /**
     * Check if FIPI/LIPI flow data is available for a sector
     * Returns false for aggregate sector mappings
     *
     * @param string $sectorId Sector UUID
     * @return bool True if non-aggregate mapping exists
     */
    public function flowAvailable(string $sectorId): bool
    {
        $cacheKey = "fipi_lipi:available:{$sectorId}";

        return Cache::remember($cacheKey, 24 * 3600, function () use ($sectorId) {
            $mapping = $this->getMappingBySectorId($sectorId);

            if (!$mapping) {
                return false;
            }

            // Don't assign flow to aggregate sector mappings
            return !$mapping->is_aggregate;
        });
    }

    /**
     * Classify flow direction based on net flow value
     *
     * @param float $netFlow Net flow in USD
     * @return array Direction classification with confidence
     */
    public function classifyFlowDirection(float $netFlow): array
    {
        $thresholds = config('market_pulse.fipi_lipi_flow.flow_direction_thresholds', [
            'accumulation' => 100000,
            'neutral' => [-100000, 100000],
            'distribution' => -100000,
        ]);

        if ($netFlow >= $thresholds['accumulation']) {
            return [
                'direction' => 'accumulation',
                'confidence' => 'high',
                'net_flow' => $netFlow,
            ];
        } elseif ($netFlow <= $thresholds['distribution']) {
            return [
                'direction' => 'distribution',
                'confidence' => 'high',
                'net_flow' => $netFlow,
            ];
        } else {
            return [
                'direction' => 'neutral',
                'confidence' => 'moderate',
                'net_flow' => $netFlow,
            ];
        }
    }

    /**
     * Calculate flow score (0-100) based on net flow magnitude and consistency
     *
     * @param array $flows Array of [1d, 5d, 20d] flow values from getFlowForSector()
     * @return float Score 0-100
     */
    public function calculateFlowScore(array $flows): float
    {
        if (empty($flows) || !isset($flows['1d'], $flows['5d'], $flows['20d'])) {
            return 50.0; // Neutral when insufficient data
        }

        $flow1d = (float)$flows['1d'];
        $flow5d = (float)$flows['5d'];
        $flow20d = (float)$flows['20d'];

        // Check direction consistency
        $direction1d = $flow1d >= 0 ? 1 : -1;
        $direction5d = $flow5d >= 0 ? 1 : -1;
        $direction20d = $flow20d >= 0 ? 1 : -1;

        $consistency = 0;
        if ($direction1d === $direction5d && $direction5d === $direction20d) {
            $consistency = 2; // Strong consistency
        } elseif ($direction5d === $direction20d || $direction1d === $direction5d) {
            $consistency = 1; // Moderate consistency
        } else {
            $consistency = 0; // No consistency
        }

        // Calculate magnitude score (normalize to 0-50)
        $thresholds = config('market_pulse.fipi_lipi_flow.flow_direction_thresholds');
        $accumulation_threshold = $thresholds['accumulation'] ?? 100000;

        $magnitude = min(abs($flow20d) / $accumulation_threshold, 1.0) * 50;

        // Direction score (0-50)
        $direction = $flow20d > 0 ? 50 : 0;

        // Consistency bonus (0-20)
        $consistency_bonus = $consistency * 10;

        $score = $magnitude + $direction * 0.5 + $consistency_bonus;

        return round(min(100, max(0, $score)), 2);
    }

    /**
     * Get the PSX sector display name for mapped external FIPI/LIPI sector
     *
     * @param string $sectorId Sector UUID
     * @return string|null Mapped external sector name, or null if not mapped
     */
    public function getMappedExternalName(string $sectorId): ?string
    {
        $mapping = $this->getMappingBySectorId($sectorId);
        return $mapping?->external_sector_name;
    }

    /**
     * Get all sectors with available FIPI/LIPI flow data
     * Excludes aggregate mappings
     *
     * @return array Array of [sector_id, sector_name, external_sector_name]
     */
    public function getMappedSectors(): array
    {
        $cacheKey = 'fipi_lipi:mapped_sectors_list';

        return Cache::remember($cacheKey, 24 * 3600, function () {
            try {
                $mappings = DB::table('fipi_lipi_sector_mappings')
                    ->join('sectors', 'fipi_lipi_sector_mappings.sector_id', '=', 'sectors.id')
                    ->where('fipi_lipi_sector_mappings.is_aggregate', false)
                    ->select('fipi_lipi_sector_mappings.sector_id', 'sectors.name as sector_name', 'fipi_lipi_sector_mappings.external_sector_name')
                    ->get()
                    ->toArray();

                return $mappings;
            } catch (\Exception $e) {
                Log::warning('Error fetching FIPI/LIPI sector mappings', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Recalculate flows for all mapped sectors (for batch operations)
     *
     * @param string $date Trading date (Y-m-d)
     * @return array Results summary
     */
    public function recalculateAllMappedSectors(string $date): array
    {
        $mapped = $this->getMappedSectors();
        $results = [];

        foreach ($mapped as $mapping) {
            $sectorId = $mapping['sector_id'];
            $flowData = $this->getFlowForSector($sectorId, $date);

            if ($flowData['available']) {
                $results[$sectorId] = [
                    'status' => 'success',
                    'flows' => $flowData['flows'],
                ];
            } else {
                $results[$sectorId] = [
                    'status' => 'unavailable',
                    'reason' => $flowData['message'],
                ];
            }
        }

        Log::info('FIPI/LIPI flows recalculated for all mapped sectors', [
            'date' => $date,
            'total_sectors' => count($mapped),
            'successful' => count(array_filter($results, fn($r) => $r['status'] === 'success')),
        ]);

        return $results;
    }

    /**
     * Get the sector mapping for a given sector ID
     *
     * @param string $sectorId Sector UUID
     * @return object|null Mapping record with external_sector_name and is_aggregate
     */
    private function getMappingBySectorId(string $sectorId): ?object
    {
        try {
            return DB::table('fipi_lipi_sector_mappings')
                ->where('sector_id', $sectorId)
                ->first();
        } catch (\Exception $e) {
            Log::warning('Error fetching FIPI/LIPI sector mapping', [
                'sector_id' => $sectorId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Calculate net flow for a sector over N days
     *
     * @param string $externalSectorName External sector name from FIPI/LIPI data
     * @param string $endDate End date (Y-m-d)
     * @param int $days Number of days to look back
     * @return float Net flow in USD
     */
    private function calculateNetFlow(string $externalSectorName, string $endDate, int $days): float
    {
        $refDate = Carbon::parse($endDate);
        $startDate = $refDate->copy()->subDays($days - 1)->format('Y-m-d');
        $endStr = $refDate->format('Y-m-d');

        $result = DB::table('fipi_lipi_trading_data')
            ->select(DB::raw('SUM(buy_value - sell_value) as net_flow'))
            ->where('sector_name', $externalSectorName)
            ->whereIn('investor_type', self::INSTITUTIONAL_INVESTOR_TYPES)
            ->whereBetween('trade_date', [$startDate, $endStr])
            ->first();

        return (float)($result?->net_flow ?? 0);
    }
}
