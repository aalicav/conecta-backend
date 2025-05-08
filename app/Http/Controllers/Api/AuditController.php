<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OwenIt\Auditing\Models\Audit;
use Illuminate\Support\Facades\Log;

class AuditController extends Controller
{
    /**
     * Get a paginated list of audit logs
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer',
            'event' => 'nullable|string|in:created,updated,deleted,restored',
            'auditable_type' => 'nullable|string',
            'auditable_id' => 'nullable|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'sort_field' => 'nullable|string|in:id,event,created_at,user_id',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $query = Audit::query();

            // Apply filters
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->has('event')) {
                $query->where('event', $request->event);
            }

            if ($request->has('auditable_type')) {
                $query->where('auditable_type', $request->auditable_type);
            }

            if ($request->has('auditable_id')) {
                $query->where('auditable_id', $request->auditable_id);
            }

            if ($request->has('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            // Apply sorting
            $sortField = $request->input('sort_field', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortField, $sortOrder);

            // Paginate results
            $perPage = $request->input('per_page', 15);
            $audits = $query->paginate($perPage);

            return AuditResource::collection($audits);
        } catch (\Exception $e) {
            Log::error('Error fetching audit logs: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch audit logs'], 500);
        }
    }

    /**
     * Get a specific audit log by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $audit = Audit::findOrFail($id);
            return new AuditResource($audit);
        } catch (\Exception $e) {
            Log::error('Error fetching audit log: ' . $e->getMessage());
            return response()->json(['error' => 'Audit log not found'], 404);
        }
    }

    /**
     * Get statistics for audit logs
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        try {
            $stats = [
                'total' => Audit::count(),
                'created' => Audit::where('event', 'created')->count(),
                'updated' => Audit::where('event', 'updated')->count(),
                'deleted' => Audit::where('event', 'deleted')->count(),
                'restored' => Audit::where('event', 'restored')->count(),
                'recent' => AuditResource::collection(
                    Audit::orderBy('created_at', 'desc')->take(5)->get()
                ),
                'by_model' => Audit::selectRaw('auditable_type, count(*) as count')
                    ->groupBy('auditable_type')
                    ->orderBy('count', 'desc')
                    ->take(10)
                    ->get()
            ];

            return response()->json($stats);
        } catch (\Exception $e) {
            Log::error('Error fetching audit statistics: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch audit statistics'], 500);
        }
    }

    /**
     * Get audit logs for a specific model
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getModelAudit(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'model_type' => 'required|string',
            'model_id' => 'required|integer',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $query = Audit::where('auditable_type', $request->model_type)
                ->where('auditable_id', $request->model_id)
                ->orderBy('created_at', 'desc');

            // Paginate results
            $perPage = $request->input('per_page', 15);
            $audits = $query->paginate($perPage);

            return AuditResource::collection($audits);
        } catch (\Exception $e) {
            Log::error('Error fetching model audit logs: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch model audit logs'], 500);
        }
    }
} 