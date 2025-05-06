<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingRequest;
use App\Http\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SystemSettingController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display a listing of all system settings.
     *
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function index()
    {
        try {
            $settings = SystemSetting::orderBy('group')
                ->orderBy('key')
                ->get();
            
            return SystemSettingResource::collection($settings)
                ->additional(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error fetching system settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve system settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get settings for a specific group.
     *
     * @param string $group
     * @return JsonResponse
     */
    public function getGroup(string $group): JsonResponse
    {
        try {
            $settings = SystemSetting::where('group', $group)
                ->orderBy('key')
                ->get();
            
            return response()->json([
                'success' => true,
                'group' => $group,
                'data' => SystemSettingResource::collection($settings)
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching settings group '$group': " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to retrieve settings for group '$group'",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific setting by key.
     *
     * @param string $key
     * @return JsonResponse|SystemSettingResource
     */
    public function show(string $key)
    {
        try {
            $setting = SystemSetting::where('key', $key)->first();
            
            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => "Setting with key '$key' not found"
                ], 404);
            }
            
            return (new SystemSettingResource($setting))
                ->additional(['success' => true]);
        } catch (\Exception $e) {
            Log::error("Error fetching setting '$key': " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to retrieve setting '$key'",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a specific setting.
     *
     * @param Request $request
     * @param string $key
     * @return JsonResponse
     */
    public function update(Request $request, string $key): JsonResponse
    {
        try {
            $setting = SystemSetting::where('key', $key)->first();
            
            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => "Setting with key '$key' not found"
                ], 404);
            }
            
            $validator = Validator::make($request->all(), [
                'value' => 'required',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $value = $request->input('value');
            
            // Update the setting
            $success = SystemSetting::setValue($key, $value, auth()->id());
            
            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => "Failed to update setting '$key'"
                ], 500);
            }
            
            // Get the updated setting
            $updatedSetting = SystemSetting::where('key', $key)->first();
            
            return response()->json([
                'success' => true,
                'message' => "Setting '$key' updated successfully",
                'data' => new SystemSettingResource($updatedSetting)
            ]);
        } catch (\Exception $e) {
            Log::error("Error updating setting '$key': " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to update setting '$key'",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Batch update multiple settings.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchUpdate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'settings' => 'required|array',
                'settings.*.key' => 'required|string',
                'settings.*.value' => 'required',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $settings = $request->input('settings');
            $updated = 0;
            $failed = 0;
            $notFound = 0;
            $errors = [];
            $updatedSettings = [];
            
            foreach ($settings as $settingData) {
                $key = $settingData['key'];
                $value = $settingData['value'];
                
                $setting = SystemSetting::where('key', $key)->first();
                
                if (!$setting) {
                    $notFound++;
                    $errors[] = "Setting '$key' not found";
                    continue;
                }
                
                $success = SystemSetting::setValue($key, $value, auth()->id());
                
                if ($success) {
                    $updated++;
                    $updatedSettings[] = $setting->refresh();
                } else {
                    $failed++;
                    $errors[] = "Failed to update setting '$key'";
                }
            }
            
            return response()->json([
                'success' => $updated > 0,
                'message' => "$updated settings updated successfully" . 
                             ($failed > 0 ? ", $failed failed" : "") .
                             ($notFound > 0 ? ", $notFound not found" : ""),
                'summary' => [
                    'total' => count($settings),
                    'updated' => $updated,
                    'failed' => $failed,
                    'not_found' => $notFound
                ],
                'data' => SystemSettingResource::collection(collect($updatedSettings)),
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            Log::error("Error batch updating settings: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to batch update settings",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset settings for a specific group to their default values.
     *
     * @param string $group
     * @return JsonResponse
     */
    public function resetGroupToDefaults(string $group): JsonResponse
    {
        try {
            $success = \App\Services\SystemSettingDefaults::resetGroup($group, auth()->id());
            
            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => "Failed to reset settings for group '$group' to defaults"
                ], 500);
            }
            
            // Get the updated settings for this group
            $settings = SystemSetting::where('group', $group)
                ->orderBy('key')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => "Settings for group '$group' have been reset to default values",
                'data' => SystemSettingResource::collection($settings)
            ]);
        } catch (\Exception $e) {
            Log::error("Error resetting settings for group '$group' to defaults: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to reset settings for group '$group' to defaults",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset system settings to their default values.
     *
     * @return JsonResponse
     */
    public function resetToDefaults(): JsonResponse
    {
        try {
            $success = \App\Services\SystemSettingDefaults::resetAll(auth()->id());
            
            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to reset system settings to defaults'
                ], 500);
            }
            
            // Get all settings after reset
            $settings = SystemSetting::orderBy('group')
                ->orderBy('key')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'System settings have been reset to default values',
                'data' => SystemSettingResource::collection($settings)
            ]);
        } catch (\Exception $e) {
            Log::error("Error resetting settings to defaults: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to reset settings to defaults",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new system setting.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Check if user has permission to edit settings
        if (!$request->user()->can('edit settings')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Validate the request
        $request->validate([
            'key' => 'required|string|max:255|unique:system_settings',
            'value' => 'required',
            'group' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
            'data_type' => [
                'required',
                'string',
                Rule::in(['string', 'boolean', 'integer', 'float', 'array', 'json']),
            ],
        ]);

        // Format the value based on data type
        $value = $request->value;
        
        // Convert value to proper storage format
        if (in_array($request->data_type, ['array', 'json']) && !is_string($value)) {
            $value = json_encode($value);
        } elseif ($request->data_type === 'boolean' && is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        } else {
            $value = (string) $value;
        }

        // Create the new setting
        $setting = SystemSetting::create([
            'key' => $request->key,
            'value' => $value,
            'group' => $request->group,
            'description' => $request->description,
            'is_public' => $request->is_public ?? false,
            'data_type' => $request->data_type,
            'updated_by' => Auth::id(),
        ]);

        // Format the value for the response
        $responseValue = $value;
        switch ($request->data_type) {
            case 'boolean':
                $responseValue = $value === 'true';
                break;
            case 'integer':
                $responseValue = (int) $value;
                break;
            case 'float':
                $responseValue = (float) $value;
                break;
            case 'array':
            case 'json':
                $responseValue = json_decode($value, true) ?? [];
                break;
        }

        return response()->json([
            'message' => 'Setting created successfully',
            'setting' => [
                'key' => $setting->key,
                'value' => $responseValue,
                'group' => $setting->group,
                'description' => $setting->description,
                'is_public' => $setting->is_public,
                'data_type' => $setting->data_type,
            ]
        ], 201);
    }

    /**
     * Update multiple settings at once.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateMultiple(Request $request)
    {
        // Check if user has permission to edit settings
        if (!$request->user()->can('edit settings')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable',
        ]);

        $settings = $request->input('settings');
        $userId = Auth::id();
        $errors = [];
        $updated = [];

        foreach ($settings as $key => $value) {
            $setting = SystemSetting::where('key', $key)->first();
            
            if (!$setting) {
                $errors[] = "Setting with key '{$key}' not found";
                continue;
            }

            // Handle boolean values that come as strings
            if ($setting->data_type === 'boolean' && is_string($value)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            // Try to update the setting
            $success = SystemSetting::setValue($key, $value, $userId);
            
            if ($success) {
                $updated[] = $key;
            } else {
                $errors[] = "Failed to update setting with key '{$key}'";
            }
        }

        $response = [
            'updated' => $updated,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
            return response()->json($response, 422);
        }

        return response()->json($response);
    }

    /**
     * Delete a system setting.
     *
     * @param  string  $key
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($key, Request $request)
    {
        // Check if user has permission to edit settings
        if (!$request->user()->can('edit settings')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $setting = SystemSetting::where('key', $key)->first();
        
        if (!$setting) {
            return response()->json(['message' => 'Setting not found'], 404);
        }

        // Check if this is a system-critical setting
        if (in_array($key, [
            'scheduling_enabled',
            'scheduling_priority',
            'scheduling_min_days',
            'scheduling_allow_manual_override',
            // Add other critical settings here
        ])) {
            return response()->json([
                'message' => 'Cannot delete a system-critical setting',
            ], 403);
        }

        $setting->delete();

        return response()->json([
            'message' => 'Setting deleted successfully',
        ]);
    }

    /**
     * Get all public settings.
     *
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function getPublicSettings()
    {
        try {
            $settings = SystemSetting::where('is_public', true)
                ->orderBy('group')
                ->orderBy('key')
                ->get();
            
            return SystemSettingResource::collection($settings)
                ->additional(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error fetching public settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve public settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 