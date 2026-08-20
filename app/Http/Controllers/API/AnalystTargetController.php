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
                ->orderByDesc('updated_at')
                ->get();

            return $this->sendResponse(
                ['analyst_targets' => $targets],
                'Analyst targets retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->sendError('Retrieval failed', ['error' => $e->getMessage()], 500);
        }
    }

    public function active(): JsonResponse
    {
        try {
            $user = Auth::user();
            $targets = AnalystTarget::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->whereNull('expiry_date')
                ->with('stock')
                ->orderByDesc('updated_at')
                ->get();

            return $this->sendResponse(
                ['analyst_targets' => $targets],
                'Active analyst targets retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->sendError('Retrieval failed', ['error' => $e->getMessage()], 500);
        }
    }

    public function performance(): JsonResponse
    {
        try {
            $user = Auth::user();
            $targets = AnalystTarget::where('user_id', $user->id)
                ->whereNotNull('expiry_date')
                ->whereIn('status', ['TP1_HIT', 'TP2_HIT', 'SL_HIT'])
                ->with('stock')
                ->orderByDesc('updated_at')
                ->get();

            return $this->sendResponse(
                ['analyst_targets' => $targets],
                'Performance targets retrieved successfully'
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
                'buy_1' => 'nullable|numeric',
                'buy_2' => 'nullable|numeric',
                'stop_loss' => 'nullable|numeric',
                'target_1' => 'required|numeric',
                'target_2' => 'nullable|numeric',
                'publish_date' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation failed', $validator->errors(), 422);
            }

            $user = Auth::user();

            $buy1 = $request->buy_1;
            $buy2 = $request->buy_2;

            if (!$buy1 && !$buy2) {
                $stock = \App\Models\Stock::find($request->stock_id);
                if ($stock && $stock->latest_price) {
                    $buy1 = $stock->latest_price;
                }
            }

            $target = AnalystTarget::create([
                'user_id' => $user->id,
                'stock_id' => $request->stock_id,
                'analyst_name' => $request->analyst_name,
                'buy_1' => $buy1,
                'buy_2' => $buy2,
                'stop_loss' => $request->stop_loss,
                'target_1' => $request->target_1,
                'target_2' => $request->target_2,
                'publish_date' => $request->publish_date ?? now()->toDateString(),
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
                'status' => 'required|in:ACTIVE,SL_HIT,TP1_HIT,TP2_HIT,BUY',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation failed', $validator->errors(), 422);
            }

            $user = Auth::user();
            $target = AnalystTarget::where('id', $id)
                ->where('user_id', $user->id)
                ->with('stock')
                ->firstOrFail();

            $updateData = ['status' => $request->status];

            if (in_array($request->status, ['SL_HIT', 'TP1_HIT', 'TP2_HIT']) && !$target->expiry_date) {
                $updateData['expiry_date'] = now()->toDateString();
            }

            $target->update($updateData);
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

