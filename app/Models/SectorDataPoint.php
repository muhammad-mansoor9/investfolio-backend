<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectorDataPoint extends Model
{
    use HasFactory;

    protected $table = 'sector_data_points';

    public $timestamps = false;

    protected $fillable = [
        'sector_id',
        'dimensions',
        'year',
        'value',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'value' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the sector that owns this data point
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * Scope to get by sector ID
     */
    public function scopeForSector($query, string $sectorId)
    {
        return $query->where('sector_id', $sectorId);
    }

    /**
     * Scope to filter by dimension key-value pair
     * - Skips implicit filters (frequency, growth)
     * - Skips "All *" values (All Companies, All Products, All Categories)
     * - Handles mixed case JSONB keys (company/COMPANY, category/CATEGORY)
     * - Case-insensitive value matching
     */
    public function scopeWhereDimension($query, string $key, string $value)
    {
        // Skip implicit filters - frequency and growth are implicit in all data
        $implicitFilters = ['frequency', 'growth'];
        if (in_array(strtolower($key), $implicitFilters)) {
            \Log::info("Skipping filter for '{$key}' - implicit in all data");
            return $query;
        }

        // Skip if value indicates "all" (case-insensitive check)
        $allPatterns = ['all', 'all companies', 'all products', 'all categories'];
        if (in_array(strtolower($value), $allPatterns)) {
            \Log::info("Skipping filter for '{$key}' because value is '{$value}' (All)");
            return $query;
        }

        \Log::info("Applying filter: {$key} = {$value} (case-insensitive for both key and value)");

        // Try both uppercase and lowercase keys since database has mixed case
        // Use COALESCE to check both variations
        return $query->whereRaw(
            "LOWER(COALESCE(dimensions->>?, dimensions->>?)) = LOWER(?)",
            [$key, strtoupper($key), $value]
        );
    }

    /**
     * Scope to filter by multiple dimensions
     */
    public function scopeWhereDimensions($query, array $dimensions)
    {
        foreach ($dimensions as $key => $value) {
            $query->whereRaw("dimensions->>'$key' = ?", [$value]);
        }
        return $query;
    }

    /**
     * Scope to get by year
     */
    public function scopeForYear($query, string $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Scope to get data within year range (handles MMM-YY format)
     */
    public function scopeYearRange($query, string $startYear, string $endYear)
    {
        \Log::info("Year Range Filter: {$startYear} to {$endYear}");

        // Convert MMM-YY to a timestamp for proper comparison
        // MMM-YY format: "Oct-25" = October 2025
        $startTimestamp = $this->convertMonthYearToTimestamp($startYear);
        $endTimestamp = $this->convertMonthYearToTimestamp($endYear);

        \Log::info("Converted timestamps: {$startTimestamp} to {$endTimestamp}");

        // Use raw SQL to compare dates properly
        return $query->whereRaw(
            "TO_DATE(year, 'Mon-YY') BETWEEN TO_DATE(?, 'Mon-YY') AND TO_DATE(?, 'Mon-YY')",
            [$startYear, $endYear]
        );
    }

    /**
     * Helper to convert MMM-YY to timestamp
     */
    private function convertMonthYearToTimestamp($monthYear)
    {
        // Convert "Oct-25" to "2025-10-01"
        try {
            $date = \DateTime::createFromFormat('M-y', $monthYear);
            return $date ? $date->getTimestamp() : null;
        } catch (\Exception $e) {
            \Log::error("Failed to parse date: {$monthYear}");
            return null;
        }
    }

    /**
     * Scope to order by year
     */
    public function scopeOrderedByYear($query, string $direction = 'asc')
    {
        return $query->orderBy('year', $direction);
    }

    /**
     * Get dimension value by key
     */
    public function getDimensionValue(string $key): ?string
    {
        return $this->dimensions[$key] ?? null;
    }

    /**
     * Check if dimension exists
     */
    public function hasDimension(string $key): bool
    {
        return isset($this->dimensions[$key]);
    }
}
