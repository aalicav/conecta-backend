<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\SchedulingConfigService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class SchedulingConfigController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(protected NotificationService $notificationService)
    {
        $this->middleware(['auth:sanctum', 'role:super_admin']);
    }

    /**
     * Get current scheduling configuration
     *
     * @return JsonResponse
     */
    public function getConfig(): JsonResponse
    {
        $config = [
            'scheduling_enabled' => SchedulingConfigService::isAutomaticSchedulingEnabled(),
            'scheduling_priority' => SchedulingConfigService::getSchedulingPriority(),
            'scheduling_min_days' => SchedulingConfigService::getMinDaysAhead(),
            'allow_manual_override' => SchedulingConfigService::allowManualOverride(),
        ];

        return response()->json([
            'success' => true,
            'data' => $config
        ]);
    }

    /**
     * Update scheduling configuration
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateConfig(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'scheduling_enabled' => 'sometimes|boolean',
                'scheduling_priority' => 'sometimes|in:cost,distance,availability,balanced',
                'scheduling_min_days' => 'sometimes|integer|min:0',
                'allow_manual_override' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updated = false;
            $changes = [];

            // Update scheduling_enabled if provided
            if ($request->has('scheduling_enabled')) {
                $enabled = $request->boolean('scheduling_enabled');
                if (SchedulingConfigService::setAutomaticScheduling($enabled)) {
                    $updated = true;
                    $changes['scheduling_enabled'] = $enabled;
                }
            }

            // Update scheduling_priority if provided
            if ($request->has('scheduling_priority')) {
                $priority = $request->input('scheduling_priority');
                if (SchedulingConfigService::setSchedulingPriority($priority)) {
                    $updated = true;
                    $changes['scheduling_priority'] = $priority;
                }
            }

            // Update scheduling_min_days if provided
            if ($request->has('scheduling_min_days')) {
                $minDays = $request->integer('scheduling_min_days');
                if (SchedulingConfigService::setMinDaysAhead($minDays)) {
                    $updated = true;
                    $changes['scheduling_min_days'] = $minDays;
                }
            }

            // Update allow_manual_override if provided
            if ($request->has('allow_manual_override')) {
                $allowOverride = $request->boolean('allow_manual_override');
                if (SchedulingConfigService::setManualOverride($allowOverride)) {
                    $updated = true;
                    $changes['allow_manual_override'] = $allowOverride;
                }
            }

            if (!$updated) {
                return response()->json([
                    'success' => false,
                    'message' => 'No configuration settings were updated'
                ], 400);
            }

            // Send notification about config changes
            $this->notificationService->notifySchedulingConfigChanged($changes, Auth::user());

            return response()->json([
                'success' => true,
                'message' => 'Scheduling configuration updated successfully',
                'data' => [
                    'scheduling_enabled' => SchedulingConfigService::isAutomaticSchedulingEnabled(),
                    'scheduling_priority' => SchedulingConfigService::getSchedulingPriority(),
                    'scheduling_min_days' => SchedulingConfigService::getMinDaysAhead(),
                    'allow_manual_override' => SchedulingConfigService::allowManualOverride(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating scheduling configuration: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update scheduling configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle scheduling on/off
     *
     * @return JsonResponse
     */
    public function toggleScheduling(): JsonResponse
    {
        try {
            $currentStatus = SchedulingConfigService::isAutomaticSchedulingEnabled();
            $newStatus = !$currentStatus;
            
            if (SchedulingConfigService::setAutomaticScheduling($newStatus)) {
                // Send notification about the toggle
                $this->notificationService->notifySchedulingConfigChanged(
                    ['scheduling_enabled' => $newStatus], 
                    Auth::user()
                );
                
                return response()->json([
                    'success' => true,
                    'message' => 'Automatic scheduling ' . ($newStatus ? 'enabled' : 'disabled') . ' successfully',
                    'scheduling_enabled' => $newStatus
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle automatic scheduling'
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error toggling scheduling: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle automatic scheduling',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 