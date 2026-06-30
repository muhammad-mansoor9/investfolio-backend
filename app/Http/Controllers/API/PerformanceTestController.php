<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PerformanceTestController extends Controller
{
    /**
     * Simple test endpoint to verify basic setup
     */
    public function test(): JsonResponse
    {
        try {
            $results = [
                'message' => 'Basic test passed',
                'timestamp' => now()->toDateTimeString(),
            ];

            // Test 1: Database connection
            try {
                DB::connection()->getPdo();
                $results['database_connected'] = true;
            } catch (\Exception $e) {
                $results['database_connected'] = false;
                $results['database_error'] = $e->getMessage();
            }

            // Test 2: Check if performance_criteria table exists
            try {
                $count = DB::table('performance_criteria')->count();
                $results['performance_criteria_table_exists'] = true;
                $results['performance_criteria_count'] = $count;
            } catch (\Exception $e) {
                $results['performance_criteria_table_exists'] = false;
                $results['table_error'] = $e->getMessage();
            }

            // Test 3: Check if PerformanceCriteria model works
            try {
                $modelCount = \App\Models\PerformanceCriteria::count();
                $results['model_works'] = true;
                $results['model_count'] = $modelCount;
            } catch (\Exception $e) {
                $results['model_works'] = false;
                $results['model_error'] = $e->getMessage();
            }

            // Test 4: Check if sectors table exists
            try {
                $sectorCount = DB::table('sectors')->count();
                $results['sectors_table_exists'] = true;
                $results['sectors_count'] = $sectorCount;
            } catch (\Exception $e) {
                $results['sectors_table_exists'] = false;
                $results['sectors_error'] = $e->getMessage();
            }

            // Test 5: Check if stocks table exists
            try {
                $stockCount = DB::table('stocks')->count();
                $results['stocks_table_exists'] = true;
                $results['stocks_count'] = $stockCount;
            } catch (\Exception $e) {
                $results['stocks_table_exists'] = false;
                $results['stocks_error'] = $e->getMessage();
            }

            return response()->json([
                'success' => true,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'trace' => explode("\n", $e->getTraceAsString())
            ], 500);
        }
    }

    /**
     * Test specific sector
     */
    public function testSector(string $sectorId): JsonResponse
    {
        try {
            $results = [
                'sector_id' => $sectorId,
            ];

            // Test 1: Check if sector exists
            try {
                $sector = DB::table('sectors')->where('id', $sectorId)->first();
                if ($sector) {
                    $results['sector_exists'] = true;
                    $results['sector_name'] = $sector->name ?? 'N/A';
                } else {
                    $results['sector_exists'] = false;
                    $results['message'] = 'Sector not found';
                }
            } catch (\Exception $e) {
                $results['sector_check_error'] = $e->getMessage();
            }

            // Test 2: Check stocks in sector
            try {
                $stockCount = DB::table('stocks')
                    ->where('sector_id', $sectorId)
                    ->where('is_active', true)
                    ->count();
                $results['stocks_in_sector'] = $stockCount;

                // Get sample stocks
                $sampleStocks = DB::table('stocks')
                    ->where('sector_id', $sectorId)
                    ->where('is_active', true)
                    ->limit(3)
                    ->get(['symbol', 'description']);
                $results['sample_stocks'] = $sampleStocks;
            } catch (\Exception $e) {
                $results['stocks_check_error'] = $e->getMessage();
            }

            // Test 3: Check criteria
            try {
                $criteriaCount = DB::table('performance_criteria')
                    ->where('is_active', true)
                    ->where(function($query) use ($sectorId) {
                        $query->whereNull('sector_id')
                            ->orWhere('sector_id', $sectorId);
                    })
                    ->count();
                $results['criteria_count'] = $criteriaCount;

                // Get sample criteria
                $sampleCriteria = DB::table('performance_criteria')
                    ->where('is_active', true)
                    ->whereNull('sector_id')
                    ->limit(3)
                    ->get(['criteria_name', 'criteria_category']);
                $results['sample_criteria'] = $sampleCriteria;
            } catch (\Exception $e) {
                $results['criteria_check_error'] = $e->getMessage();
            }

            // Test 4: Check if PerformanceCalculationService exists
            try {
                $serviceExists = class_exists(\App\Services\PerformanceCalculationService::class);
                $results['calculation_service_exists'] = $serviceExists;
            } catch (\Exception $e) {
                $results['calculation_service_exists'] = false;
                $results['service_error'] = $e->getMessage();
            }

            // Test 5: Try to instantiate PerformanceService
            try {
                $service = app(\App\Services\PerformanceService::class);
                $results['performance_service_instantiated'] = true;
            } catch (\Exception $e) {
                $results['performance_service_instantiated'] = false;
                $results['service_instantiation_error'] = $e->getMessage();
            }

            return response()->json([
                'success' => true,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'trace' => explode("\n", $e->getTraceAsString())
            ], 500);
        }
    }
}
