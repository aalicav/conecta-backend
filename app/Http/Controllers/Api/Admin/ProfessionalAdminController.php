<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalResource;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class ProfessionalAdminController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('role:super_admin');
    }

    /**
     * Get statistics about professionals.
     *
     * @return JsonResponse
     */
    public function getStats(): JsonResponse
    {
        try {
            $stats = [
                'total' => Professional::count(),
                'active' => Professional::where('is_active', true)->count(),
                'pending' => Professional::where('status', 'pending')->count(),
                'approved' => Professional::where('status', 'approved')->count(),
                'rejected' => Professional::where('status', 'rejected')->count(),
                'by_specialty' => $this->getProfessionalsBySpecialty(),
                'by_type' => $this->getProfessionalsByType(),
                'recent' => Professional::orderBy('created_at', 'desc')->take(5)->get(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving professional stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve professional statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Batch update professionals status.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchUpdate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'professionals' => 'required|array',
                'professionals.*' => 'exists:professionals,id',
                'status' => 'required|in:approved,rejected,pending',
                'is_active' => 'required|boolean',
                'rejection_reason' => 'nullable|string|required_if:status,rejected',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $professionals = Professional::whereIn('id', $request->professionals)->get();
            $updated = 0;

            foreach ($professionals as $professional) {
                // Update professional status
                if ($professional->status != $request->status) {
                    $professional->status = $request->status;
                    
                    if ($request->status == 'approved') {
                        $professional->approved_at = now();
                        $professional->approved_by = Auth::id();
                    } elseif ($request->status == 'rejected') {
                        $professional->rejection_reason = $request->rejection_reason;
                    }
                }

                // Update active status
                $professional->is_active = $request->is_active;
                $professional->save();

                // Update associated user
                if ($professional->user) {
                    $professional->user->is_active = $request->is_active;
                    $professional->user->save();
                }

                $updated++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$updated} professionals updated successfully",
                'updated_count' => $updated
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error batch updating professionals: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to batch update professionals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a user account for a professional.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function createAccount(Request $request, Professional $professional): JsonResponse
    {
        try {
            // Check if professional already has a user account
            if ($professional->user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Professional already has a user account'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'email' => 'required|email|unique:users,email',
                'password' => 'required|min:8',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Create user
            $user = User::create([
                'name' => $professional->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'entity_id' => $professional->id,
                'entity_type' => Professional::class,
                'is_active' => $request->boolean('is_active', false),
            ]);

            // Assign professional role
            $role = Role::where('name', 'professional')->first();
            if ($role) {
                $user->assignRole($role);
            }

            DB::commit();

            // Load relationships
            $professional->load('user');

            return response()->json([
                'success' => true,
                'message' => 'User account created successfully for professional',
                'data' => new ProfessionalResource($professional)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating user account for professional: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get professionals export (CSV/Excel).
     *
     * @param Request $request
     * @return JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'format' => 'nullable|in:csv,excel',
                'status' => 'nullable|in:all,pending,approved,rejected',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $format = $request->format ?? 'csv';
            $status = $request->status ?? 'all';
            
            $query = Professional::query();
            
            if ($status !== 'all') {
                $query->where('status', $status);
            }
            
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
            
            $professionals = $query->with(['clinic', 'approver'])->get();
            
            // Implementation would connect to a export service or use Laravel Excel package
            // For now, just return the data as JSON
            return response()->json([
                'success' => true,
                'message' => 'Export successful. Implement actual file export in production.',
                'data' => $professionals,
                'format' => $format,
                'count' => $professionals->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting professionals: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to export professionals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get professionals by specialty for statistics.
     *
     * @return array
     */
    protected function getProfessionalsBySpecialty(): array
    {
        $specialtyStats = Professional::selectRaw('specialty, COUNT(*) as count')
            ->whereNotNull('specialty')
            ->groupBy('specialty')
            ->orderByDesc('count')
            ->get();
            
        return $specialtyStats->toArray();
    }

    /**
     * Get professionals by type for statistics.
     *
     * @return array
     */
    protected function getProfessionalsByType(): array
    {
        $typeStats = Professional::selectRaw('professional_type, COUNT(*) as count')
            ->groupBy('professional_type')
            ->orderByDesc('count')
            ->get();
            
        return $typeStats->toArray();
    }
} 