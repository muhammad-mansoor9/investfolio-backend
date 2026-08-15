<?php

use App\Http\Controllers\API\AnalystTargetController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BrokerResearchController;
use App\Http\Controllers\API\CacheUpdateController;
use App\Http\Controllers\API\DividendHistoryController;
use App\Http\Controllers\API\EPSRankingController;
use App\Http\Controllers\API\FairValueCalculationController;
use App\Http\Controllers\API\FinancialController;
use App\Http\Controllers\API\FinancialRatioController;
use App\Http\Controllers\API\FIPILIPIMarketController;
use App\Http\Controllers\API\FIPILIPITradingController;
use App\Http\Controllers\API\IndexController;
use App\Http\Controllers\API\InsiderTradingController;
use App\Http\Controllers\API\LandingPageController;
use App\Http\Controllers\API\LatestResultsController;
use App\Http\Controllers\API\PEAnalysisController;
use App\Http\Controllers\API\PerformanceAnalysisController;
use App\Http\Controllers\API\RevenueRankingController;
use App\Http\Controllers\API\SectorController;
use App\Http\Controllers\API\SectorVolumeHistoryController;
use App\Http\Controllers\API\StockController;
use App\Http\Controllers\API\StockRetracementController;
use App\Http\Controllers\API\StocksAnalysisController;
use App\Http\Controllers\API\StockScreenerController;
use App\Http\Controllers\API\StocksMomentumController;
use App\Http\Controllers\API\TechnicalAnalysisController;
use App\Http\Controllers\API\UinSettlementController;
use App\Http\Controllers\API\ValuationController;
use App\Http\Controllers\API\VolumeAnalysisController;
use App\Http\Controllers\API\VolumeController;
use App\Http\Controllers\API\VolumeHistoryController;
use App\Http\Controllers\API\MarketIntelligenceController;
use App\Http\Controllers\API\DataIntegrityController;
use App\Http\Controllers\API\MarketPulseController;
use Illuminate\Support\Facades\Route;

// Auth — public
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('auth/google/callback', [AuthController::class, 'googleCallback']);
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// Landing Page — public
Route::prefix('landing')->group(function () {
    Route::post('contact-us', [LandingPageController::class, 'contactUs'])->middleware('throttle:5,1');
    Route::post('newsletter-signup', [LandingPageController::class, 'newsletterSignup'])->middleware('throttle:5,1');
    Route::post('waitlist-signup', [LandingPageController::class, 'waitlistSignup'])->middleware('throttle:5,1');
    Route::get('stats', [LandingPageController::class, 'getStats']);
});

Route::prefix('financial')->group(function () {
    Route::get('stocks/{symbol}', [FinancialController::class, 'getFinancialData']);
    Route::post('by-identifier', [FinancialController::class, 'getFinancialDataByIdentifier']);
    Route::get('metrics', [FinancialController::class, 'getAllMetrics']);
    Route::get('stocks/{stockIdentifier}/metrics', [FinancialController::class, 'getStockMetrics']);
});

Route::prefix('insider-trading')->group(function () {
    Route::get('/data', [InsiderTradingController::class, 'getInsiderTradingData']);
});

Route::prefix('stocks')->group(function () {
    Route::get('/', [StockController::class, 'getStocks']);
    Route::get('/list', [StockController::class, 'getAllStocks']);
    Route::get('/last-trading-date', [StockController::class, 'getLastTradingDate']);
    Route::get('/prices', [StockController::class, 'getStockPricesByDate']);
    Route::get('/insider-analysis', [StockController::class, 'getInsiderAnalysis']);
    Route::get('/weekly-insider-report', [StockController::class, 'getWeeklyInsiderReport']);
    Route::get('/{stockId}/details', [StockController::class, 'getStockDetails']);
    Route::prefix('symbol')->group(function () {
        Route::get('/{symbol}/details', [StockController::class, 'getStockDetailsBySymbol']);
    });
});

Route::prefix('stocks-analysis')->group(function () {
    Route::get('/latest-quarterly', [StocksAnalysisController::class, 'getLatestQuarterlyAnalysis']);
    Route::get('/sector-leadership', [StocksAnalysisController::class, 'getSectorLeadership']);
});

Route::get('/stocks-momentum', [StocksMomentumController::class, 'getStockSignals']);
Route::get('/stocks-momentum/debug', [StocksMomentumController::class, 'debugSignals']);

Route::prefix('sectors')->group(function () {
    Route::get('/', [SectorController::class, 'getAllSectors']);
    Route::get('{sectorId}/stocks', [SectorController::class, 'getSectorStocks']);
});

Route::prefix('indices')->group(function () {
    Route::get('/', [IndexController::class, 'getIndices']);
    Route::get('/{indexId}/stocks', [IndexController::class, 'getIndexStocks']);
    Route::get('/{indexId}/prices', [IndexController::class, 'getIndexPriceHistory']);
    Route::prefix('symbol')->group(function () {
        Route::get('/{symbol}/stocks', [IndexController::class, 'getIndexStocksBySymbol']);
    });
});

Route::prefix('dividends')->group(function () {
    Route::get('/', [DividendHistoryController::class, 'getDividends']);
    Route::get('/upcoming', [DividendHistoryController::class, 'getUpcomingDividends']);
    Route::get('/stock/{stockId}', [DividendHistoryController::class, 'getDividendsByStock']);
    Route::get('/stock/{stockId}/summary', [DividendHistoryController::class, 'getDividendSummaryByStock']);
});

Route::prefix('fipi-lipi')->group(function () {
    Route::get('/trading/sector-view', [FIPILIPITradingController::class, 'getSectorView']);
    Route::get('/trading/investor-view', [FIPILIPITradingController::class, 'getInvestorView']);
    Route::get('/market/market-type-view', [FIPILIPIMarketController::class, 'getMarketTypeView']);
    Route::get('/market/investor-view', [FIPILIPIMarketController::class, 'getInvestorView']);
});

Route::prefix('uin-settlement')->group(function () {
    Route::get('/stocks', [UinSettlementController::class, 'getStocksWithUinData']);
    Route::get('/analysis', [UinSettlementController::class, 'getUinSettlementAnalysis']);
    Route::post('/comparison', [UinSettlementController::class, 'getUinSettlementComparison']);
});

Route::prefix('market-intelligence')->group(function () {
    Route::get('/dashboard', [MarketIntelligenceController::class, 'getDashboard']);
    Route::get('/sector-detail', [MarketIntelligenceController::class, 'getSectorDetail']);
    Route::get('/accumulation-leaders', [MarketIntelligenceController::class, 'getAccumulationLeaders']);
    Route::get('/rotation-history', [MarketIntelligenceController::class, 'getRotationHistory']);
    Route::get('/stock-flow-history', [MarketIntelligenceController::class, 'getStockFlowHistory']);
    Route::get('/stock-detail', [MarketIntelligenceController::class, 'getStockDetail']);
});

// Market Pulse — public
Route::get('/market-pulse', [MarketPulseController::class, 'getMarketPulse']);
Route::prefix('sector-leadership')->group(function () {
    Route::get('/', [MarketPulseController::class, 'getSectorLeadership']);
    Route::get('/{sectorId}', [MarketPulseController::class, 'getSectorDetail']);
    Route::get('/{sectorId}/stocks', [MarketPulseController::class, 'getSectorStocks']);
});

// Data Integrity — public, throttled
Route::prefix('data-integrity')->middleware('throttle:60,1')->group(function () {
    Route::get('/daily', [DataIntegrityController::class, 'getDailyIntegrity']);
    Route::get('/daily/{date}', [DataIntegrityController::class, 'getDailyDetails']);
    Route::get('/financials', [DataIntegrityController::class, 'getFinancialIntegrity']);
    Route::get('/financials/{stockId}/periods', [DataIntegrityController::class, 'getStockPeriodHistory']);
    Route::get('/financial-results', [DataIntegrityController::class, 'getFinancialResultsIntegrity']);
    Route::get('/financial-results/{stockId}/periods', [DataIntegrityController::class, 'getFinancialResultsPeriodHistory']);
});

Route::prefix('performance')->group(function () {
    Route::get('/data', [PerformanceAnalysisController::class, 'getPerformanceData']);
});

Route::get('/volume-analysis', [VolumeAnalysisController::class, 'getVolumeAnalysis']);

Route::prefix('volume-history')->group(function () {
    Route::get('/data', [VolumeHistoryController::class, 'getVolumeAnalysis']);
});

Route::prefix('sector-volume-history')->group(function () {
    Route::get('/data', [SectorVolumeHistoryController::class, 'getSectorVolumeAnalysis']);
});

Route::get('/pe-analysis', [PEAnalysisController::class, 'getPEAnalysis']);
Route::post('/pe/cache', [PEAnalysisController::class, 'cacheSectorAndStockPE']);
Route::get('/fair-value-calculation', [FairValueCalculationController::class, 'getFairValueCalculation']);
Route::get('/stock-retracement', [StockRetracementController::class, 'getStockRetracement']);
Route::post('/technical-analysis/data', [TechnicalAnalysisController::class, 'getData']);
Route::get('/eps-ranking', [EPSRankingController::class, 'getEPSRanking']);
Route::get('/revenue-ranking', [RevenueRankingController::class, 'getRevenueRanking']);
Route::get('/latest-results', [LatestResultsController::class, 'getLatestResults']);

Route::get('/test', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        return 'Database connected successfully';
    } catch (\Exception $e) {
        return 'Connection failed: ' . $e->getMessage();
    }
});

Route::prefix('ratios')->group(function () {
    Route::get('/metadata', [FinancialRatioController::class, 'getMetadata']);
    Route::get('/category/{categoryName}', [FinancialRatioController::class, 'getCategoryRatios']);
    Route::get('/sector/{sectorId}', [FinancialRatioController::class, 'getSectorRatios']);
    Route::post('/clear-cache', [FinancialRatioController::class, 'clearCache']);
});

Route::prefix('screener')->group(function () {
    Route::get('/data', [StockScreenerController::class, 'getData']);
});

Route::get('/broker-research', [BrokerResearchController::class, 'getBrokerResearch']);

// Valuation calculator — public calculate, authenticated save/retrieve/delete
Route::prefix('valuations')->group(function () {
    Route::post('/calculate', [ValuationController::class, 'calculate']);
});

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('auth/sessions', [AuthController::class, 'sessions']);
    Route::delete('auth/sessions/{id}', [AuthController::class, 'revokeSession']);
    Route::get('user', [AuthController::class, 'user']);
    Route::get('user/package-limits', function () {
        return response()->json(['success' => true, 'data' => []]);
    });

    Route::prefix('volume')->group(function () {
        Route::get('/analysis', [VolumeController::class, 'getVolumeAnalysis']);
        Route::get('/sector-summary', [VolumeController::class, 'getSectorVolumeSummary']);
        Route::get('/report', [VolumeController::class, 'getVolumeReport']);
    });

    Route::prefix('valuations')->group(function () {
        Route::post('/save', [ValuationController::class, 'save']);
        Route::get('/saved', [ValuationController::class, 'getSaved']);
        Route::delete('/saved/{id}', [ValuationController::class, 'delete']);
    });

    Route::prefix('analyst-targets')->group(function () {
        Route::get('/', [AnalystTargetController::class, 'index']);
        Route::post('/', [AnalystTargetController::class, 'store']);
        Route::patch('/{id}/status', [AnalystTargetController::class, 'updateStatus']);
        Route::delete('/{id}', [AnalystTargetController::class, 'destroy']);
    });
});

// Admin/Webhook routes — cache updates from daily job
Route::prefix('admin')->group(function () {
    Route::post('/cache-update', [CacheUpdateController::class, 'updateDailyCache']);
    Route::post('/cache-clear', [CacheUpdateController::class, 'clearDailyCache']);
    Route::get('/cache-stats', [CacheUpdateController::class, 'getCacheStats']);
});
