<?php

namespace App\Http\Controllers\API;

use App\Models\BoardMeeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardMeetingController extends BaseController
{
    /**
     * Get all board meetings paginated, sorted by created_at desc
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $search = $request->query('search');

            $query = BoardMeeting::with(['stock:id,symbol,description,sector_id', 'stock.sector:id,name'])
                ->orderBy('created_at', 'desc');

            if ($search) {
                $query->search($search);
            }

            $meetings = $query->paginate($perPage);

            return $this->sendResponse([
                'meetings' => $meetings->items(),
                'pagination' => [
                    'total' => $meetings->total(),
                    'per_page' => $meetings->perPage(),
                    'current_page' => $meetings->currentPage(),
                    'last_page' => $meetings->lastPage(),
                ],
            ], 'Board meetings retrieved successfully');
        } catch (\Exception $e) {
            return $this->sendError('Failed to retrieve board meetings', [], 500);
        }
    }

    /**
     * Get board meetings by stock
     */
    public function getByStock(string $stockId): JsonResponse
    {
        try {
            $meetings = BoardMeeting::with(['stock:id,symbol,description,sector_id', 'stock.sector:id,name'])
                ->where('stock_id', $stockId)
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->sendResponse([
                'meetings' => $meetings,
                'total_count' => $meetings->count(),
            ], 'Board meetings retrieved successfully');
        } catch (\Exception $e) {
            return $this->sendError('Failed to retrieve board meetings', [], 500);
        }
    }


    /**
     * Get single board meeting
     */
    public function show(string $id): JsonResponse
    {
        try {
            $meeting = BoardMeeting::with(['stock:id,symbol,description,sector_id', 'stock.sector:id,name'])
                ->find($id);

            if (!$meeting) {
                return $this->sendError('Board meeting not found', [], 404);
            }

            return $this->sendResponse(['meeting' => $meeting], 'Board meeting retrieved successfully');
        } catch (\Exception $e) {
            return $this->sendError('Failed to retrieve board meeting', [], 500);
        }
    }
}
