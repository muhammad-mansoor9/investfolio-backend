<?php

namespace App\Http\Controllers\API;

use App\Models\AnalystTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AnalystTargetController extends BaseController
{
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            $targets = AnalystTarget::where('user_id', $user->id)
                ->with('stock')
                ->orderByDesc('created_at')
                ->get();

            return $this->sendResponse(
                ['analyst_targets' => $targets],
                'Analyst targets retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->sendError('Retrieval failed', ['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_id' => 'required|uuid',
                'analyst_name' => 'required|string|max:255',
                'stop_loss' => 'nullable|numeric',
                'target_1' => 'required|numeric',
                'target_2' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation failed', $validator->errors(), 422);
            }

            $user = Auth::user();
            $target = AnalystTarget::create([
                'user_id' => $user->id,
                'stock_id' => $request->stock_id,
                'analyst_name' => $request->analyst_name,
                'stop_loss' => $request->stop_loss,
                'target_1' => $request->target_1,
                'target_2' => $request->target_2,
                'status' => 'ACTIVE',
            ]);

            return $this->sendResponse($target->load('stock'), 'Analyst target created successfully', 201);
        } catch (\Exception $e) {
            return $this->sendError('Creation failed', ['error' => $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:ACTIVE,STOPLOSS_HIT,TARGET_HIT',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation failed', $validator->errors(), 422);
            }

            $user = Auth::user();
            $target = AnalystTarget::where('id', $id)
                ->where('user_id', $user->id)
                ->with('stock')
                ->firstOrFail();

            $target->update(['status' => $request->status]);
            $target->refresh();

            return $this->sendResponse($target, 'Status updated successfully');
        } catch (\Exception $e) {
            return $this->sendError('Update failed', ['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $user = Auth::user();
            $target = AnalystTarget::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $target->delete();

            return $this->sendResponse([], 'Analyst target deleted successfully');
        } catch (\Exception $e) {
            return $this->sendError('Delete failed', ['error' => $e->getMessage()], 500);
        }
    }
}
