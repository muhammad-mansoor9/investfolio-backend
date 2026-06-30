<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BrokerResearchController extends BaseController
{
    public function getBrokerResearch(Request $request): JsonResponse
    {
        $validator = \Validator::make($request->all(), [
            'shariah_only' => 'sometimes|boolean',
            'sector_id' => 'sometimes|uuid',
            'search' => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed', $validator->errors(), 400);
        }

        $shariahOnly = $request->boolean('shariah_only', false);
        $sectorId = $request->input('sector_id');
        $searchTerm = $request->input('search');

        $now = now();
        $quarter = $this->getCurrentQuarter($now);
        [$quarterStart, $quarterEnd] = $this->getQuarterDateRange($now->year, $quarter);

        $query = DB::table('broker_research as br')
            ->select(
                'br.id',
                'br.stock_id',
                'br.broker_name',
                'br.date',
                'br.data',
                's.symbol',
                's.description',
                's.is_shariah',
                'sec.id as sector_id',
                'sec.name as sector_name'
            )
            ->join('stocks as s', 'br.stock_id', '=', 's.id')
            ->leftJoin('sectors as sec', 's.sector_id', '=', 'sec.id')
            ->whereBetween('br.date', [$quarterStart, $quarterEnd])
            ->where('s.is_active', true)
            ->where('s.market_cap', '>', 0);

        if ($shariahOnly) {
            $query->where('s.is_shariah', true);
        }

        if ($sectorId) {
            $query->where('s.sector_id', $sectorId);
        }

        if ($searchTerm) {
            $term = '%' . $searchTerm . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('s.symbol ILIKE ?', [$term])
                  ->orWhereRaw('s.description ILIKE ?', [$term])
                  ->orWhereRaw('br.broker_name ILIKE ?', [$term]);
            });
        }

        $brokerEntries = $query
            ->orderBy('br.date', 'desc')
            ->orderBy('br.stock_id', 'asc')
            ->orderBy('br.broker_name', 'asc')
            ->get();

        $deduplicatedData = $this->deduplicateByBroker($brokerEntries);

        $period = [
            'quarter' => "Q{$quarter} " . $now->year,
            'start_date' => $quarterStart->format('Y-m-d'),
            'end_date' => $quarterEnd->format('Y-m-d'),
        ];

        $response = [
            'period' => $period,
            'data' => $deduplicatedData,
            'total_results' => count($deduplicatedData),
        ];

        return $this->sendResponse($response, 'Broker research data retrieved successfully');
    }

    private function getCurrentQuarter(Carbon $date): int
    {
        $month = $date->month;
        if ($month <= 3) {
            return 1;
        } elseif ($month <= 6) {
            return 2;
        } elseif ($month <= 9) {
            return 3;
        } else {
            return 4;
        }
    }

    private function getQuarterDateRange(int $year, int $quarter): array
    {
        $ranges = [
            1 => ['01-01', '03-31'],
            2 => ['04-01', '06-30'],
            3 => ['07-01', '09-30'],
            4 => ['10-01', '12-31'],
        ];

        [$startStr, $endStr] = $ranges[$quarter];
        $start = Carbon::createFromFormat('Y-m-d', "{$year}-{$startStr}");
        $end = Carbon::createFromFormat('Y-m-d', "{$year}-{$endStr}");

        return [$start, $end];
    }

    private function deduplicateByBroker($entries): array
    {
        $seen = [];
        $result = [];

        foreach ($entries as $entry) {
            $key = $entry->stock_id . '|' . $entry->broker_name;

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = [
                    'stock_id' => $entry->stock_id,
                    'symbol' => $entry->symbol,
                    'description' => $entry->description,
                    'sector_id' => $entry->sector_id,
                    'sector_name' => $entry->sector_name,
                    'is_shariah' => (bool) $entry->is_shariah,
                    'broker_name' => $entry->broker_name,
                    'date' => $entry->date,
                    'eps' => $entry->data['eps'] ?? null,
                    'pe_ratio' => $entry->data['pe'] ?? null,
                    'gross_profit' => $entry->data['gross_profit'] ?? null,
                    'additional_fields' => array_diff_key($entry->data, ['eps' => null, 'pe' => null, 'gross_profit' => null]),
                ];
            }
        }

        return $result;
    }
}
