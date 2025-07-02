<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TussResource;
use App\Models\Tuss;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TussController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display a listing of the TUSS procedures.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Tuss::query();
        
        // Filter by active status if provided
        if ($request->has('is_active')) {
            $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        } else {
            // By default, only show active procedures
            $query->where('is_active', true);
        }
        
        // Filter by search term if provided
        if ($request->has('search')) {
            $query->search($request->search);
        }
        
        // Filter by specific codes if provided (for CSV import)
        if ($request->has('codes')) {
            $codes = explode(',', $request->codes);
            $codes = array_map('trim', $codes);
            $query->whereIn('code', $codes);
        }
        
        // Filter by chapter if provided
        if ($request->has('chapter')) {
            $query->where('chapter', $request->chapter);
        }
        
        // Filter by group if provided
        if ($request->has('group')) {
            $query->where('group', $request->group);
        }
        
        // Filter by subgroup if provided
        if ($request->has('subgroup')) {
            $query->where('subgroup', $request->subgroup);
        }
        
        // Order by code by default
        $procedures = $query->orderBy('code')
            ->paginate($request->per_page ?? 15);
        
        return TussResource::collection($procedures);
    }

    /**
     * Store a newly created TUSS procedure in storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Check if user has permission to create TUSS procedures
            if (!$request->user()->can('manage_tuss')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to create TUSS procedures'
                ], 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|unique:tuss_procedures,code',
                'description' => 'required|string',
                'chapter' => 'nullable|string',
                'group' => 'nullable|string',
                'subgroup' => 'nullable|string',
                'category' => 'nullable|string',
                'is_active' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Create the TUSS procedure
            $tuss = Tuss::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'TUSS procedure created successfully',
                'data' => new TussResource($tuss)
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating TUSS procedure: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create TUSS procedure',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified TUSS procedure.
     *
     * @param Tuss $tuss
     * @return TussResource
     */
    public function show(Tuss $tuss): TussResource
    {
        return new TussResource($tuss);
    }

    /**
     * Update the specified TUSS procedure in storage.
     *
     * @param Request $request
     * @param Tuss $tuss
     * @return JsonResponse
     */
    public function update(Request $request, Tuss $tuss): JsonResponse
    {
        try {
            // Check if user has permission to update TUSS procedures
            if (!$request->user()->can('manage_tuss')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update TUSS procedures'
                ], 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'code' => 'sometimes|string|unique:tuss_procedures,code,'.$tuss->id,
                'description' => 'sometimes|string',
                'chapter' => 'nullable|string',
                'group' => 'nullable|string',
                'subgroup' => 'nullable|string',
                'category' => 'nullable|string',
                'is_active' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Update the TUSS procedure
            $tuss->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'TUSS procedure updated successfully',
                'data' => new TussResource($tuss)
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating TUSS procedure: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update TUSS procedure',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the active status of the specified TUSS procedure.
     *
     * @param Tuss $tuss
     * @return JsonResponse
     */
    public function toggleActive(Tuss $tuss): JsonResponse
    {
        try {
            // Check if user has permission to update TUSS procedures
            if (!request()->user()->can('manage_tuss')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update TUSS procedures'
                ], 403);
            }

            // Toggle the active status
            $tuss->update([
                'is_active' => !$tuss->is_active
            ]);

            return response()->json([
                'success' => true,
                'message' => 'TUSS procedure status updated successfully',
                'data' => new TussResource($tuss)
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling TUSS procedure status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle TUSS procedure status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified TUSS procedure from storage.
     *
     * @param Tuss $tuss
     * @return JsonResponse
     */
    public function destroy(Tuss $tuss): JsonResponse
    {
        try {
            // Check if user has permission to delete TUSS procedures
            if (!request()->user()->can('manage_tuss')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete TUSS procedures'
                ], 403);
            }

            // Check if the TUSS procedure is in use
            if ($tuss->solicitations()->exists() || $tuss->contracts()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete TUSS procedure that is in use',
                    'data' => [
                        'solicitations_count' => $tuss->solicitations()->count(),
                        'contracts_count' => $tuss->contracts()->count()
                    ]
                ], 422);
            }

            // Delete the TUSS procedure
            $tuss->delete();

            return response()->json([
                'success' => true,
                'message' => 'TUSS procedure deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting TUSS procedure: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete TUSS procedure',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get chapters.
     *
     * @return JsonResponse
     */
    public function getChapters(): JsonResponse
    {
        try {
            $chapters = Tuss::select('chapter')
                ->where('is_active', true)
                ->whereNotNull('chapter')
                ->distinct()
                ->orderBy('chapter')
                ->pluck('chapter');
            
            return response()->json([
                'success' => true,
                'data' => $chapters
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting TUSS chapters: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get TUSS chapters',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get groups for a specific chapter.
     *
     * @param string $chapter
     * @return JsonResponse
     */
    public function getGroups(string $chapter): JsonResponse
    {
        try {
            $groups = Tuss::select('group')
                ->where('chapter', $chapter)
                ->where('is_active', true)
                ->whereNotNull('group')
                ->distinct()
                ->orderBy('group')
                ->pluck('group');
            
            return response()->json([
                'success' => true,
                'data' => $groups
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting TUSS groups: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get TUSS groups',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get subgroups for a specific chapter and group.
     *
     * @param string $chapter
     * @param string $group
     * @return JsonResponse
     */
    public function getSubgroups(string $chapter, string $group): JsonResponse
    {
        try {
            $subgroups = Tuss::select('subgroup')
                ->where('chapter', $chapter)
                ->where('group', $group)
                ->where('is_active', true)
                ->whereNotNull('subgroup')
                ->distinct()
                ->orderBy('subgroup')
                ->pluck('subgroup');
            
            return response()->json([
                'success' => true,
                'data' => $subgroups
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting TUSS subgroups: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get TUSS subgroups',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 