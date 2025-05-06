<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SystemSettingAdminController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'can:settings.edit']);
    }

    /**
     * Get all available setting groups.
     *
     * @return JsonResponse
     */
    public function getGroups(): JsonResponse
    {
        try {
            $groups = SystemSetting::select('group')
                ->distinct()
                ->orderBy('group')
                ->pluck('group')
                ->toArray();
            
            return response()->json([
                'success' => true,
                'data' => $groups
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve setting groups: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve setting groups'
            ], 500);
        }
    }

    /**
     * Get statistics about system settings.
     *
     * @return JsonResponse
     */
    public function getStats(): JsonResponse
    {
        try {
            $totalSettings = SystemSetting::count();
            $groupCounts = SystemSetting::select('group', DB::raw('count(*) as count'))
                ->groupBy('group')
                ->orderBy('count', 'desc')
                ->get();
            
            $typeCounts = SystemSetting::select('data_type', DB::raw('count(*) as count'))
                ->groupBy('data_type')
                ->orderBy('count', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $totalSettings,
                    'by_group' => $groupCounts,
                    'by_type' => $typeCounts
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve setting statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve setting statistics'
            ], 500);
        }
    }

    /**
     * Create a new system setting group.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createGroup(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'group' => 'required|string|max:255',
                'settings' => 'required|array',
                'settings.*.key' => 'required|string|max:255|unique:system_settings,key',
                'settings.*.value' => 'required',
                'settings.*.data_type' => 'required|string|in:string,integer,boolean,array,json',
                'settings.*.description' => 'nullable|string',
                'settings.*.is_public' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $group = $request->input('group');
            $settings = $request->input('settings');
            
            DB::beginTransaction();
            
            foreach ($settings as $setting) {
                SystemSetting::create([
                    'key' => $setting['key'],
                    'value' => $setting['value'],
                    'group' => $group,
                    'data_type' => $setting['data_type'],
                    'description' => $setting['description'] ?? null,
                    'is_public' => $setting['is_public'] ?? false,
                ]);
            }
            
            DB::commit();
            Cache::forget('system_settings');
            
            return response()->json([
                'success' => true,
                'message' => "Settings group '{$group}' created successfully",
                'count' => count($settings)
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create settings group: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create settings group: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an entire settings group.
     *
     * @param string $group
     * @return JsonResponse
     */
    public function deleteGroup(string $group): JsonResponse
    {
        try {
            $count = SystemSetting::where('group', $group)->count();
            
            if ($count === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Group '{$group}' not found"
                ], 404);
            }
            
            SystemSetting::where('group', $group)->delete();
            Cache::forget('system_settings');
            
            return response()->json([
                'success' => true,
                'message' => "Settings group '{$group}' deleted successfully",
                'count' => $count
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete settings group: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete settings group'
            ], 500);
        }
    }

    /**
     * Export settings as JSON.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $group = $request->query('group');
            $query = SystemSetting::orderBy('group')->orderBy('key');
            
            if ($group) {
                $query->where('group', $group);
            }
            
            $settings = $query->get()->toArray();
            
            return response()->json([
                'success' => true,
                'data' => $settings,
                'count' => count($settings),
                'exported_at' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to export settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export settings'
            ], 500);
        }
    }

    /**
     * Import settings from JSON.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function import(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'settings' => 'required|array',
                'settings.*.key' => 'required|string|max:255',
                'settings.*.value' => 'required',
                'settings.*.group' => 'required|string|max:255',
                'settings.*.data_type' => 'required|string|in:string,integer,boolean,array,json',
                'replace_existing' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $settings = $request->input('settings');
            $replaceExisting = $request->input('replace_existing', false);
            
            $imported = 0;
            $skipped = 0;
            
            DB::beginTransaction();
            
            foreach ($settings as $setting) {
                $exists = SystemSetting::where('key', $setting['key'])->exists();
                
                if ($exists && !$replaceExisting) {
                    $skipped++;
                    continue;
                }
                
                if ($exists) {
                    SystemSetting::where('key', $setting['key'])->update([
                        'value' => $setting['value'],
                        'group' => $setting['group'],
                        'data_type' => $setting['data_type'],
                        'description' => $setting['description'] ?? null,
                        'is_public' => $setting['is_public'] ?? false,
                    ]);
                } else {
                    SystemSetting::create([
                        'key' => $setting['key'],
                        'value' => $setting['value'],
                        'group' => $setting['group'],
                        'data_type' => $setting['data_type'],
                        'description' => $setting['description'] ?? null,
                        'is_public' => $setting['is_public'] ?? false,
                    ]);
                }
                
                $imported++;
            }
            
            DB::commit();
            Cache::forget('system_settings');
            
            return response()->json([
                'success' => true,
                'message' => 'Settings imported successfully',
                'imported' => $imported,
                'skipped' => $skipped,
                'total' => count($settings)
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to import settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to import settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the audit log for system settings
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAuditLog()
    {
        try {
            // Assuming there's an auditing package or model events stored
            $audits = DB::table('audits')
                ->where('auditable_type', SystemSetting::class)
                ->orderBy('created_at', 'desc')
                ->paginate(15);
            
            return response()->json([
                'success' => true,
                'data' => $audits
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve system settings audit log: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve audit log'
            ], 500);
        }
    }

    /**
     * Create a backup of all system settings
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createBackup(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get all system settings
            $settings = SystemSetting::all();
            
            // Create a backup record with the settings data
            $backup = DB::table('system_setting_backups')->insertGetId([
                'name' => $request->name,
                'description' => $request->description,
                'data' => json_encode($settings),
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Backup created successfully',
                'backup_id' => $backup
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create system settings backup: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create backup'
            ], 500);
        }
    }

    /**
     * List all available backups
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function listBackups()
    {
        try {
            $backups = DB::table('system_setting_backups')
                ->select(['id', 'name', 'description', 'created_at', 'created_by'])
                ->orderBy('created_at', 'desc')
                ->get();
                
            return response()->json([
                'success' => true,
                'data' => $backups
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve system settings backups: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve backups'
            ], 500);
        }
    }

    /**
     * Restore settings from a backup
     * 
     * @param int $backup
     * @return \Illuminate\Http\JsonResponse
     */
    public function restoreBackup($backup)
    {
        try {
            $backupRecord = DB::table('system_setting_backups')
                ->where('id', $backup)
                ->first();
                
            if (!$backupRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup not found'
                ], 404);
            }
            
            $settingsData = json_decode($backupRecord->data, true);
            
            DB::beginTransaction();
            
            // Clear the cache before restoring
            Cache::forget('system_settings');
            
            // Restore all settings
            foreach ($settingsData as $setting) {
                SystemSetting::updateOrCreate(
                    ['key' => $setting['key']],
                    [
                        'value' => $setting['value'],
                        'data_type' => $setting['data_type'],
                        'group' => $setting['group'],
                        'updated_by' => auth()->id()
                    ]
                );
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Settings restored successfully from backup'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to restore system settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore settings'
            ], 500);
        }
    }

    /**
     * Delete a backup
     * 
     * @param int $backup
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteBackup($backup)
    {
        try {
            $deleted = DB::table('system_setting_backups')
                ->where('id', $backup)
                ->delete();
                
            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Backup deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete system settings backup: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete backup'
            ], 500);
        }
    }
} 