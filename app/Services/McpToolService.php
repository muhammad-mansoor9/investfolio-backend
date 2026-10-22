<?php

namespace App\Services;

use App\Exceptions\McpToolException;
use App\Http\Controllers\API\FinancialController;
use App\Http\Controllers\API\FIPILIPIMarketController;
use App\Http\Controllers\API\FIPILIPITradingController;
use App\Http\Controllers\API\IndexController;
use App\Http\Controllers\API\RecentEarningsController;
use App\Http\Controllers\API\SectorController;
use App\Http\Controllers\API\StockController;
use App\Http\Controllers\API\StocksMomentumController;
use App\Http\Controllers\API\UinSettlementController;
use App\Models\JournalEntry;
use App\Models\JournalInsight;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class McpToolService
{
    private const FLOW_METRICS = ['buying_volume', 'buying_value', 'selling_volume', 'selling_value', 'net_buy_sell'];

    private const EARNINGS_SORT_FIELDS = ['latest_period', 'revenue_qoq', 'eps_qoq', 'revenue_yoy', 'eps_yoy', 'revenue_ttm', 'eps_ttm'];

    private const JOURNAL_DATE = ['type' => 'string', 'description' => 'YYYY-MM-DD, calendar day in Pakistan time (PKT)'];

    private const JOURNAL_TAGS = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => JournalService::MAX_TAGS, 'description' => 'Tags, usually PSX stock or index symbols (e.g. OGDC, KSE100) plus free-form labels (e.g. FOMO). Stored uppercase.'];

    private const INDICATOR_TIMEFRAMES = ['1D' => ['1D', 'daily'], '1W' => ['1W', 'weekly'], '1M' => ['1M', 'monthly']];

    public function __construct(private JournalService $journal)
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->handlers());
    }

    public function call(string $name, array $args)
    {
        $handler = $this->handlers()[$name] ?? null;
        if ($handler === null) {
            throw new McpToolException("Unknown tool: {$name}");
        }

        return $this->{$handler}($args);
    }

    private function handlers(): array
    {
        return [
            'list_stocks' => 'listStocks',
            'get_stock' => 'getStock',
            'list_sectors' => 'listSectors',
            'get_sector_stocks' => 'getSectorStocks',
            'list_indices' => 'listIndices',
            'get_index_constituents' => 'getIndexConstituents',
            'get_financial_statements' => 'getFinancialStatements',
            'get_earnings_growth' => 'getEarningsGrowth',
            'get_price_history' => 'getPriceHistory',
            'get_indicators' => 'getIndicators',
            'get_signals' => 'getSignals',
            'get_fipi_lipi_sector_flows' => 'getFipiLipiSectorFlows',
            'get_fipi_lipi_market_flows' => 'getFipiLipiMarketFlows',
            'get_uin_settlement' => 'getUinSettlement',
            'list_journal_entries' => 'listJournalEntries',
            'create_journal_entry' => 'createJournalEntry',
            'update_journal_entry' => 'updateJournalEntry',
            'delete_journal_entry' => 'deleteJournalEntry',
            'list_journal_tags' => 'listJournalTags',
            'list_journal_insights' => 'listJournalInsights',
        ];
    }

    public function definitions(): array
    {
        return [
            [
                'name' => 'list_stocks',
                'description' => 'List PSX stocks with symbol, company name, sector, shariah flag and market cap. Use search to find a company by name or symbol substring.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Case-insensitive substring of symbol or company name'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 500],
                    ],
                ],
            ],
            [
                'name' => 'get_stock',
                'description' => 'Full profile for one stock by symbol: sector, exchange, market cap, shares outstanding, free float, latest OHLCV price, index memberships with weightages, and the last 30 days of price history.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'symbol' => ['type' => 'string', 'description' => 'PSX ticker symbol, e.g. OGDC'],
                    ],
                    'required' => ['symbol'],
                ],
            ],
            [
                'name' => 'list_sectors',
                'description' => 'List PSX sectors with stock counts. Optionally include aggregate sector market cap and sector P/E ratios.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'include_market_cap' => ['type' => 'boolean', 'default' => false],
                        'include_pe' => ['type' => 'boolean', 'default' => false],
                        'date' => ['type' => 'string', 'description' => 'P/E calculation date (YYYY-MM-DD), defaults to today; only used with include_pe'],
                    ],
                ],
            ],
            [
                'name' => 'get_sector_stocks',
                'description' => 'All stocks in one sector (by sector name or sector id), sorted by market cap descending, each with latest OHLCV price.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'sector' => ['type' => 'string', 'description' => 'Sector name (case-insensitive, substring ok) or sector UUID'],
                    ],
                    'required' => ['sector'],
                ],
            ],
            [
                'name' => 'list_indices',
                'description' => 'List PSX market indices (KSE100 etc.) with constituent counts and latest index level.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'get_index_constituents',
                'description' => 'Constituent stocks of an index by index symbol (e.g. KSE100), ordered by index weightage descending, each with sector and latest price.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'index_symbol' => ['type' => 'string', 'description' => 'Index symbol, e.g. KSE100'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    ],
                    'required' => ['index_symbol'],
                ],
            ],
            [
                'name' => 'get_financial_statements',
                'description' => 'As-reported financial statements (income statement, balance sheet, cash flow and ratio tables) for one stock, quarterly or annual, in PKR. Returns tables of rows (line items) by period columns.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'symbol' => ['type' => 'string'],
                        'period_type' => ['type' => 'string', 'enum' => ['quarterly', 'annual']],
                    ],
                    'required' => ['symbol', 'period_type'],
                ],
            ],
            [
                'name' => 'get_earnings_growth',
                'description' => 'Quarterly revenue and diluted EPS growth per stock: QoQ, YoY and TTM-over-TTM percentages, with latest reported period. Covers every active stock; filter by symbol or sector, or sort market-wide to rank growers. TTM values are null unless all four trailing quarters exist. Growth is quarterly — month-over-month does not exist for financials.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'symbol' => ['type' => 'string', 'description' => 'Return only this stock'],
                        'sector' => ['type' => 'string', 'description' => 'Case-insensitive sector name substring'],
                        'sort_by' => ['type' => 'string', 'enum' => self::EARNINGS_SORT_FIELDS, 'default' => 'latest_period', 'description' => 'Sorted descending; nulls last'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                    ],
                ],
            ],
            [
                'name' => 'get_price_history',
                'description' => 'Daily OHLCV price history for one stock by symbol, ascending by date. Amounts in PKR.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'symbol' => ['type' => 'string'],
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 250, 'description' => 'Most recent N rows within the range'],
                    ],
                    'required' => ['symbol'],
                ],
            ],
            [
                'name' => 'get_indicators',
                'description' => 'Latest computed technical indicators for one stock: EMA (periods 6/10/22/55/100/200), RSI(14) with signal line, MACD (line/signal/histogram) and Klinger, per timeframe (1D daily, 1W weekly, 1M monthly). Returns the most recent values on or before the given date.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'symbol' => ['type' => 'string'],
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD cutoff, defaults to latest available'],
                        'timeframes' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['1D', '1W', '1M']], 'description' => 'Defaults to all three'],
                    ],
                    'required' => ['symbol'],
                ],
            ],
            [
                'name' => 'get_signals',
                'description' => 'Momentum signal states per stock for three strategies (explosive, swing, positional). Each strategy has a 0-100 composite score (weights: EMA structure 0.40, price position 0.25, RSI momentum 0.20, MACD confirmation 0.15) and per-signal states: 1=Bearish, 2=Weak, 3=Neutral, 4=Bullish, 5=Very Bullish. Signals include EMA 6/10, EMA 10/21, weekly EMA 10/21 crossovers, RSI momentum, MACD confirmation and price-vs-EMA positions.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'symbol' => ['type' => 'string', 'description' => 'Return only this stock'],
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today'],
                        'strategy' => ['type' => 'string', 'enum' => ['explosive', 'swing', 'positional'], 'description' => 'Return only this strategy; enables min_score filtering and sorts by its score'],
                        'min_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'Requires strategy'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                        'include_metadata' => ['type' => 'boolean', 'default' => false, 'description' => 'Include full signal descriptions and per-state explanations'],
                    ],
                ],
            ],
            [
                'name' => 'get_fipi_lipi_sector_flows',
                'description' => 'Foreign (FIPI) and local (LIPI) investor buying/selling flows at sector level for a date range. ALL VALUES ARE IN USD. Grouped by sector with per-investor-type rows (view=by_sector) or by investor type with per-sector rows (view=by_investor). net_buy_sell positive means net buying.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'view' => ['type' => 'string', 'enum' => ['by_sector', 'by_investor'], 'default' => 'by_sector'],
                        'sector_name' => ['type' => 'string', 'description' => 'Case-insensitive substring filter'],
                        'investor_type' => ['type' => 'string', 'description' => 'Case-insensitive substring filter'],
                    ],
                    'required' => ['start_date', 'end_date'],
                ],
            ],
            [
                'name' => 'get_fipi_lipi_market_flows',
                'description' => 'Foreign (FIPI) and local (LIPI) investor buying/selling flows by market type (regular, futures etc.) for a date range. ALL VALUES ARE IN PKR — do not compare directly with get_fipi_lipi_sector_flows, which is in USD. Grouped by market type (view=by_market) or investor type (view=by_investor).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'view' => ['type' => 'string', 'enum' => ['by_market', 'by_investor'], 'default' => 'by_market'],
                        'market_type' => ['type' => 'string', 'description' => 'Case-insensitive substring filter'],
                        'investor_type' => ['type' => 'string', 'description' => 'Case-insensitive substring filter'],
                    ],
                    'required' => ['start_date', 'end_date'],
                ],
            ],
            [
                'name' => 'get_uin_settlement',
                'description' => 'UIN settlement concentration for one stock over a date range: daily trade volume/value vs UIN-settled volume/value and percentages, with period summary (high UIN percentage suggests concentrated accumulation or distribution). Call without symbol to list stocks that have UIN data.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'symbol' => ['type' => 'string'],
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to 90 days before end_date'],
                        'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today'],
                        'search' => ['type' => 'string', 'description' => 'Only without symbol: filter the stock list'],
                    ],
                ],
            ],
            [
                'name' => 'list_journal_entries',
                'description' => "Read the signed-in user's own trading journal entries, newest first by default. Filter by text, tags (stock/index symbols or labels) and date range. Returns total_matches plus up to limit entries with id, content, tags and created_at (UTC).",
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Case-insensitive text contained in the entry'],
                        'tags' => self::JOURNAL_TAGS,
                        'tag_match' => ['type' => 'string', 'enum' => ['any', 'all'], 'default' => 'any', 'description' => 'any = entry has at least one of the tags; all = entry has every tag'],
                        'start_date' => self::JOURNAL_DATE,
                        'end_date' => self::JOURNAL_DATE,
                        'order' => ['type' => 'string', 'enum' => ['desc', 'asc'], 'default' => 'desc'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => JournalService::MAX_LIMIT, 'default' => 50],
                    ],
                ],
            ],
            [
                'name' => 'create_journal_entry',
                'description' => "Add an entry to the user's trading journal (a trade, decision, or market observation). Only call this when the user asks you to record something. Tag it with the relevant PSX symbols.",
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Entry text, written as the user would'],
                        'tags' => self::JOURNAL_TAGS,
                    ],
                    'required' => ['content'],
                ],
            ],
            [
                'name' => 'update_journal_entry',
                'description' => "Edit one of the user's journal entries by id. Fields left out are unchanged; tags, when given, replace the existing tag list.",
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Entry id from list_journal_entries'],
                        'content' => ['type' => 'string'],
                        'tags' => self::JOURNAL_TAGS,
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'delete_journal_entry',
                'description' => "Permanently delete one of the user's journal entries by id. Confirm with the user before calling.",
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Entry id from list_journal_entries'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'list_journal_tags',
                'description' => "Distinct tags used in the user's journal with how many entries carry each, most used first. Use it to discover valid values for list_journal_entries tags.",
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'list_journal_insights',
                'description' => 'AI insights the user previously generated from their journal in the Investfolio app (question and answer), newest first.',
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    ],
                ],
            ],
        ];
    }

    private function listStocks(array $args): array
    {
        return $this->unwrap(app(StockController::class)->getAllStocks($this->request($this->only($args, ['search', 'limit']))));
    }

    private function getStock(array $args): array
    {
        $symbol = $this->requireString($args, 'symbol');

        return $this->unwrap(app(StockController::class)->getStockDetailsBySymbol($this->request([]), strtoupper($symbol)));
    }

    private function listSectors(array $args): array
    {
        $includes = [];
        if (!empty($args['include_pe'])) {
            $includes[] = 'pe';
        }
        if (!empty($args['include_market_cap'])) {
            $includes[] = 'market_cap';
        }
        $query = $includes ? ['includes' => implode(',', $includes)] : [];
        if (!empty($args['date'])) {
            $query['date'] = $args['date'];
        }

        return $this->unwrap(app(SectorController::class)->getAllSectors($this->request($query)));
    }

    private function getSectorStocks(array $args): array
    {
        $sectorId = $this->resolveSectorId($this->requireString($args, 'sector'));

        return $this->unwrap(app(SectorController::class)->getSectorStocks($this->request([]), $sectorId));
    }

    private function listIndices(array $args): array
    {
        return $this->unwrap(app(IndexController::class)->getIndices($this->request([])));
    }

    private function getIndexConstituents(array $args): array
    {
        $symbol = $this->requireString($args, 'index_symbol');

        return $this->unwrap(app(IndexController::class)->getIndexStocksBySymbol($this->request($this->only($args, ['limit'])), strtoupper($symbol)));
    }

    private function getFinancialStatements(array $args): array
    {
        $symbol = strtoupper($this->requireString($args, 'symbol'));
        $periodType = strtoupper($this->requireString($args, 'period_type'));
        if (!in_array($periodType, ['QUARTERLY', 'ANNUAL'], true)) {
            throw new McpToolException("period_type must be 'quarterly' or 'annual'");
        }

        return $this->unwrap(app(FinancialController::class)->getFinancialData($symbol, $this->request(['type' => $periodType])));
    }

    private function getEarningsGrowth(array $args): array
    {
        $data = $this->unwrap(app(RecentEarningsController::class)->getRecentEarnings($this->request([])));
        $rows = $data['data'] ?? [];

        if (!empty($args['symbol'])) {
            $symbol = strtoupper(trim($args['symbol']));
            $rows = array_values(array_filter($rows, fn ($r) => strtoupper($r['symbol']) === $symbol));
            if (!$rows) {
                throw new McpToolException("No earnings data for symbol: {$args['symbol']}");
            }
        }
        if (!empty($args['sector'])) {
            $rows = array_values(array_filter($rows, fn ($r) => stripos($r['sector_name'] ?? '', $args['sector']) !== false));
        }

        $sortBy = $args['sort_by'] ?? 'latest_period';
        if (!in_array($sortBy, self::EARNINGS_SORT_FIELDS, true)) {
            throw new McpToolException('sort_by must be one of: ' . implode(', ', self::EARNINGS_SORT_FIELDS));
        }
        usort($rows, function ($a, $b) use ($sortBy) {
            $av = $a[$sortBy] ?? null;
            $bv = $b[$sortBy] ?? null;
            if ($av === null || $bv === null) {
                return ($av === null) <=> ($bv === null);
            }

            return $bv <=> $av;
        });

        $limit = $this->limit($args, 50, 200);
        $total = count($rows);

        return [
            'total_matches' => $total,
            'sorted_by' => $sortBy,
            'data' => array_slice($rows, 0, $limit),
        ];
    }

    private function getPriceHistory(array $args): array
    {
        $stock = $this->resolveStock($this->requireString($args, 'symbol'));
        $limit = $this->limit($args, 250, 1000);

        $query = DB::table('stock_prices')
            ->where('stock_id', $stock->id)
            ->orderByDesc('date')
            ->limit($limit);
        if (!empty($args['start_date'])) {
            $query->where('date', '>=', $args['start_date']);
        }
        if (!empty($args['end_date'])) {
            $query->where('date', '<=', $args['end_date']);
        }

        $rows = $query->get(['date', 'open', 'high', 'low', 'close', 'volume', 'change'])
            ->reverse()
            ->values();

        return [
            'symbol' => $stock->symbol,
            'company_name' => $stock->description,
            'currency' => 'PKR',
            'last_trading_date' => DB::table('stock_prices')->max('date'),
            'total_records' => $rows->count(),
            'data' => $rows,
        ];
    }

    private function getIndicators(array $args): array
    {
        $stock = $this->resolveStock($this->requireString($args, 'symbol'));
        $timeframes = $args['timeframes'] ?? array_keys(self::INDICATOR_TIMEFRAMES);

        $result = [];
        foreach ($timeframes as $timeframe) {
            $aliases = self::INDICATOR_TIMEFRAMES[$timeframe] ?? null;
            if ($aliases === null) {
                throw new McpToolException("Invalid timeframe '{$timeframe}'; use 1D, 1W or 1M");
            }
            $row = DB::table('stock_indicators')
                ->where('stock_id', $stock->id)
                ->whereIn('timeframe', $aliases)
                ->when(!empty($args['date']), fn ($q) => $q->where('date', '<=', $args['date']))
                ->orderByDesc('date')
                ->first(['date', 'data']);
            $result[$timeframe] = $row
                ? ['date' => $row->date, 'values' => json_decode($row->data, true)]
                : null;
        }

        return [
            'symbol' => $stock->symbol,
            'company_name' => $stock->description,
            'indicators' => $result,
        ];
    }

    private function getSignals(array $args): array
    {
        if (isset($args['min_score']) && empty($args['strategy'])) {
            throw new McpToolException('min_score requires strategy');
        }

        $data = $this->unwrap(app(StocksMomentumController::class)->getStockSignals($this->request($this->only($args, ['date']))));
        $rows = $data['data'] ?? [];

        if (!empty($args['symbol'])) {
            $symbol = strtoupper(trim($args['symbol']));
            $rows = array_values(array_filter($rows, fn ($r) => strtoupper($r['symbol']) === $symbol));
        }

        $strategy = $args['strategy'] ?? null;
        if ($strategy !== null) {
            if (!in_array($strategy, ['explosive', 'swing', 'positional'], true)) {
                throw new McpToolException("strategy must be 'explosive', 'swing' or 'positional'");
            }
            $minScore = (float) ($args['min_score'] ?? 0);
            $filtered = [];
            foreach ($rows as $row) {
                $strategyData = $row['strategies'][$strategy] ?? null;
                if ($strategyData === null || ($strategyData['score'] ?? 0) < $minScore) {
                    continue;
                }
                $row['strategies'] = [$strategy => $strategyData];
                $filtered[] = $row;
            }
            usort($filtered, fn ($a, $b) => ($b['strategies'][$strategy]['score'] ?? 0) <=> ($a['strategies'][$strategy]['score'] ?? 0));
            $rows = $filtered;
        }

        $limit = $this->limit($args, 50, 200);
        $result = [
            'date' => $data['date'] ?? null,
            'resolved_dates' => $data['resolved_dates'] ?? null,
            'total_matches' => count($rows),
            'data' => array_slice($rows, 0, $limit),
        ];
        if (!empty($args['include_metadata'])) {
            $result['metadata'] = $data['metadata'] ?? null;
        }

        return $result;
    }

    private function getFipiLipiSectorFlows(array $args): array
    {
        $view = $args['view'] ?? 'by_sector';
        $query = $this->only($args, ['start_date', 'end_date', 'sector_name', 'investor_type']);

        if ($view === 'by_sector') {
            $data = $this->unwrap(app(FIPILIPITradingController::class)->getSectorView($this->request($query)));
            $data = $this->filterFlows($data, 'sector_tables', 'sector_name', $args['sector_name'] ?? null, 'investor_rows', 'investor_type', $args['investor_type'] ?? null, 'sector_total');
        } elseif ($view === 'by_investor') {
            $data = $this->unwrap(app(FIPILIPITradingController::class)->getInvestorView($this->request($query)));
            $data = $this->filterFlows($data, 'investor_tables', 'investor_type', $args['investor_type'] ?? null, 'sector_rows', 'sector_name', $args['sector_name'] ?? null, 'investor_total');
        } else {
            throw new McpToolException("view must be 'by_sector' or 'by_investor'");
        }

        return ['currency' => 'USD'] + $data;
    }

    private function getFipiLipiMarketFlows(array $args): array
    {
        $view = $args['view'] ?? 'by_market';
        $query = $this->only($args, ['start_date', 'end_date', 'market_type', 'investor_type']);

        if ($view === 'by_market') {
            $data = $this->unwrap(app(FIPILIPIMarketController::class)->getMarketTypeView($this->request($query)));
            $data = $this->filterFlows($data, 'market_tables', 'market_type', $args['market_type'] ?? null, 'investor_rows', 'investor_type', $args['investor_type'] ?? null, 'market_total');
        } elseif ($view === 'by_investor') {
            $data = $this->unwrap(app(FIPILIPIMarketController::class)->getInvestorView($this->request($query)));
            $data = $this->filterFlows($data, 'investor_tables', 'investor_type', $args['investor_type'] ?? null, 'market_rows', 'market_type', $args['market_type'] ?? null, 'investor_total');
        } else {
            throw new McpToolException("view must be 'by_market' or 'by_investor'");
        }

        return ['currency' => 'PKR'] + $data;
    }

    private function getUinSettlement(array $args): array
    {
        if (empty($args['symbol'])) {
            return $this->unwrap(app(UinSettlementController::class)->getStocksWithUinData($this->request($this->only($args, ['search']))));
        }

        $stock = $this->resolveStock($args['symbol']);
        $endDate = $args['end_date'] ?? now()->toDateString();
        $startDate = $args['start_date'] ?? \Carbon\Carbon::parse($endDate)->subDays(90)->toDateString();

        return $this->unwrap(app(UinSettlementController::class)->getUinSettlementAnalysis($this->request([
            'stock_id' => $stock->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ])));
    }

    private function listJournalEntries(array $args): array
    {
        $filters = $this->only($args, ['search', 'tags', 'tag_match', 'start_date', 'end_date', 'order']);
        $filters['limit'] = $this->limit($args, 50, JournalService::MAX_LIMIT);
        $result = $this->journal->search($this->user(), $filters);

        return [
            'total_matches' => $result['total'],
            'returned' => $result['entries']->count(),
            'entries' => $result['entries']->map(fn ($entry) => $this->journalEntry($entry))->all(),
        ];
    }

    private function createJournalEntry(array $args): array
    {
        $entry = $this->journal->create($this->user(), $this->only($args, ['content', 'tags']));

        return ['created' => $this->journalEntry($entry)];
    }

    private function updateJournalEntry(array $args): array
    {
        $id = $this->requireString($args, 'id');
        $changes = array_intersect_key($args, array_flip(['content', 'tags']));
        if ($changes === []) {
            throw new McpToolException('Provide content and/or tags to update');
        }

        $entry = $this->journal->update($this->user(), $id, $changes);
        if (!$entry) {
            throw new McpToolException("Journal entry not found: {$id}");
        }

        return ['updated' => $this->journalEntry($entry)];
    }

    private function deleteJournalEntry(array $args): array
    {
        $id = $this->requireString($args, 'id');
        if (!$this->journal->delete($this->user(), $id)) {
            throw new McpToolException("Journal entry not found: {$id}");
        }

        return ['deleted' => $id];
    }

    private function listJournalTags(array $args): array
    {
        return ['tags' => $this->journal->tags($this->user())->all()];
    }

    private function listJournalInsights(array $args): array
    {
        $insights = JournalInsight::where('user_id', $this->user()->id)
            ->orderByDesc('created_at')
            ->limit($this->limit($args, 20, 100))
            ->get(['id', 'prompt', 'response', 'created_at']);

        return ['insights' => $insights->all()];
    }

    private function journalEntry(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'content' => $entry->content,
            'tags' => $entry->tags ?? [],
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    private function user(): User
    {
        $user = request()->user();
        if (!$user instanceof User) {
            throw new McpToolException('Journal tools require a signed-in MCP connection');
        }

        return $user;
    }

    // Upstream FIPI/LIPI endpoints ignore name filters when served from cache,
    // so filtering is re-applied here and totals recomputed from surviving rows.
    private function filterFlows(array $data, string $tablesKey, string $tableField, ?string $tableFilter, string $rowsKey, string $rowField, ?string $rowFilter, string $totalKey): array
    {
        if ($tableFilter === null && $rowFilter === null) {
            return $data;
        }

        $tables = [];
        $grand = array_fill_keys(self::FLOW_METRICS, 0.0);
        foreach ($data[$tablesKey] ?? [] as $table) {
            if ($tableFilter !== null && stripos((string) ($table[$tableField] ?? ''), $tableFilter) === false) {
                continue;
            }
            $rows = array_values(array_filter(
                $table[$rowsKey] ?? [],
                fn ($r) => $rowFilter === null || stripos((string) ($r[$rowField] ?? ''), $rowFilter) !== false
            ));
            if (!$rows) {
                continue;
            }
            $total = array_fill_keys(self::FLOW_METRICS, 0.0);
            foreach ($rows as $row) {
                foreach (self::FLOW_METRICS as $metric) {
                    $total[$metric] += (float) ($row[$metric] ?? 0);
                }
            }
            $table[$rowsKey] = $rows;
            $table[$totalKey] = $total;
            $tables[] = $table;
            foreach (self::FLOW_METRICS as $metric) {
                $grand[$metric] += $total[$metric];
            }
        }

        $data[$tablesKey] = $tables;
        $data['grand_total'] = $grand;

        return $data;
    }

    private function resolveStock(string $symbol): object
    {
        $stock = DB::table('stocks')
            ->whereRaw('UPPER(symbol) = ?', [strtoupper(trim($symbol))])
            ->first(['id', 'symbol', 'description']);
        if (!$stock) {
            throw new McpToolException("Unknown symbol: {$symbol}");
        }

        return $stock;
    }

    private function resolveSectorId(string $sector): string
    {
        $sector = trim($sector);
        if (Str::isUuid($sector)) {
            return $sector;
        }

        $matches = DB::table('sectors')->whereRaw('name ILIKE ?', ['%' . $sector . '%'])->get(['id', 'name']);
        $exact = $matches->first(fn ($m) => strcasecmp($m->name, $sector) === 0);
        if ($exact) {
            return $exact->id;
        }
        if ($matches->count() === 1) {
            return $matches->first()->id;
        }
        if ($matches->isEmpty()) {
            throw new McpToolException("Unknown sector: {$sector}. Use list_sectors to see available sectors.");
        }
        throw new McpToolException("Ambiguous sector '{$sector}'; matches: " . $matches->pluck('name')->implode(', '));
    }

    private function request(array $params): Request
    {
        return Request::create('/', 'GET', array_filter($params, fn ($v) => $v !== null));
    }

    private function unwrap(JsonResponse $response): array
    {
        $json = $response->getData(true);
        if (!($json['success'] ?? false)) {
            $detail = isset($json['errors']) ? ' (' . json_encode($json['errors']) . ')' : '';

            throw new McpToolException(($json['message'] ?? 'Request failed') . $detail);
        }

        return $json['data'] ?? [];
    }

    private function only(array $args, array $keys): array
    {
        return array_filter(
            array_intersect_key($args, array_flip($keys)),
            fn ($v) => $v !== null && $v !== ''
        );
    }

    private function requireString(array $args, string $key): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new McpToolException("Missing required argument: {$key}");
        }

        return trim($value);
    }

    private function limit(array $args, int $default, int $max): int
    {
        return min(max((int) ($args['limit'] ?? $default), 1), $max);
    }
}
