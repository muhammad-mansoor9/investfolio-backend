<?php

namespace App\Http\Controllers\API;

use App\Models\BoardMeeting;
use App\Services\InvestfolioLiquidShariahFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BoardMeetingController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'shariah_only' => 'sometimes|in:0,1,true,false',
                'investfolio_liquid_shariah' => 'sometimes|in:0,1,true,false',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $shariahOnly = $request->boolean('shariah_only', false);
            $investfolioLiquidShariah = $request->boolean('investfolio_liquid_shariah', false);

            $query = BoardMeeting::with([
                'stock:id,symbol,description,sector_id,is_shariah,free_float,total_shares_outstanding,market_cap',
                'stock.sector:id,name',
            ]);

            if ($shariahOnly || $investfolioLiquidShariah) {
                $query->whereHas('stock', fn ($q) => $q->where('is_shariah', true));
            }

            $meetings = $query->orderBy('created_at', 'desc')->get();

            if ($investfolioLiquidShariah) {
                $filterService = new InvestfolioLiquidShariahFilterService();
                $meetings = $meetings
                    ->filter(fn ($meeting) => $meeting->stock && $filterService->passesFilter($meeting->stock))
                    ->values();
            }

            $meetings->each(fn ($meeting) => $meeting->stock?->makeHidden(['free_float', 'total_shares_outstanding', 'market_cap']));

            return $this->sendResponse(['meetings' => $meetings], 'Board meetings retrieved successfully');
        } catch (\Exception $e) {
            return $this->sendError('Failed to retrieve board meetings', [], 500);
        }
    }
}
