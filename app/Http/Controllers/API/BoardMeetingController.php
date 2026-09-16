<?php

namespace App\Http\Controllers\API;

use App\Models\BoardMeeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class BoardMeetingController extends BaseController
{
    public function index(): JsonResponse
    {
        try {
            $meetings = BoardMeeting::with(['stock:id,symbol,description,sector_id', 'stock.sector:id,name'])
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->sendResponse(['meetings' => $meetings], 'Board meetings retrieved successfully');
        } catch (\Exception $e) {
            return $this->sendError('Failed to retrieve board meetings', [], 500);
        }
    }
}
