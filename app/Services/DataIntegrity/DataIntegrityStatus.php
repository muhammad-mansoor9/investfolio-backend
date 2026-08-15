<?php

namespace App\Services\DataIntegrity;

class DataIntegrityStatus
{
    // Status constants
    public const STATUS_COMPLETE = 'COMPLETE';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_WEEKEND = 'WEEKEND';
    public const STATUS_HOLIDAY = 'MARKET_HOLIDAY';
    public const STATUS_NO_DATA = 'NO_DATA';
    public const STATUS_EXPECTED_MISSING = 'EXPECTED_BUT_MISSING';

    // Dataset names
    public const DATASET_PRICES = 'prices';
    public const DATASET_INDICATORS = 'indicators';
    public const DATASET_SIGNALS = 'signals';
    public const DATASET_FIPI_TRADING = 'fipiTrading';
    public const DATASET_FIPI_MARKET = 'fipiMarket';
    public const DATASET_UIN_SETTLEMENT = 'uinSettlement';

    // Core vs supplementary dataset grouping
    public const CORE_DATASETS = [
        self::DATASET_PRICES,
        self::DATASET_INDICATORS,
    ];

    public const SUPPLEMENTARY_DATASETS = [
        self::DATASET_SIGNALS,
        self::DATASET_FIPI_TRADING,
        self::DATASET_FIPI_MARKET,
        self::DATASET_UIN_SETTLEMENT,
    ];

    public const ALL_DATASETS = [
        self::DATASET_PRICES,
        self::DATASET_INDICATORS,
        self::DATASET_SIGNALS,
        self::DATASET_FIPI_TRADING,
        self::DATASET_FIPI_MARKET,
        self::DATASET_UIN_SETTLEMENT,
    ];

    /**
     * Determine status based on dataset availability
     */
    public static function determineDateStatus(
        array $datasetAvailability,
        string $date,
        bool $isWeekend,
        ?bool $isHoliday = null
    ): string {
        // Weekend takes precedence
        if ($isWeekend) {
            return self::STATUS_WEEKEND;
        }

        // Market holiday takes precedence
        if ($isHoliday) {
            return self::STATUS_HOLIDAY;
        }

        // Check which core datasets are available
        $coreAvailable = array_filter(
            self::CORE_DATASETS,
            fn($dataset) => $datasetAvailability[$dataset]['available'] ?? false
        );

        $allAvailable = array_filter(
            self::ALL_DATASETS,
            fn($dataset) => $datasetAvailability[$dataset]['available'] ?? false
        );

        // If no data at all on a weekday, mark as suspicious
        if (empty($allAvailable)) {
            return self::STATUS_NO_DATA;
        }

        // If all core datasets present, it's complete
        if (count($coreAvailable) === count(self::CORE_DATASETS)) {
            return self::STATUS_COMPLETE;
        }

        // If some but not all core datasets, it's partial (problematic)
        if (!empty($coreAvailable)) {
            return self::STATUS_PARTIAL;
        }

        // Only supplementary datasets, still partial (missing core)
        return self::STATUS_PARTIAL;
    }

    /**
     * Get empty dataset availability structure
     */
    public static function getEmptyDatasetStructure(): array
    {
        return array_fill_keys(
            self::ALL_DATASETS,
            [
                'available' => false,
                'count' => 0,
            ]
        );
    }

    /**
     * Get human-readable status label
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_COMPLETE => 'Healthy',
            self::STATUS_PARTIAL => 'Incomplete',
            self::STATUS_WEEKEND => 'Weekend',
            self::STATUS_HOLIDAY => 'Market Closed',
            self::STATUS_NO_DATA => 'No Data',
            self::STATUS_EXPECTED_MISSING => 'Missing',
            default => 'Unknown',
        };
    }

    /**
     * Get CSS class for status styling
     */
    public static function getStatusClass(string $status): string
    {
        return match ($status) {
            self::STATUS_COMPLETE => 'bg-green-50 text-green-800 border-green-200',
            self::STATUS_PARTIAL => 'bg-red-50 text-red-800 border-red-200',
            self::STATUS_WEEKEND, self::STATUS_HOLIDAY => 'bg-gray-50 text-gray-700 border-gray-200',
            self::STATUS_NO_DATA, self::STATUS_EXPECTED_MISSING => 'bg-orange-50 text-orange-800 border-orange-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }
}
