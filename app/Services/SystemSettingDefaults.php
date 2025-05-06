<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SystemSettingDefaults
{
    /**
     * Default system settings.
     *
     * @var array
     */
    protected static $defaults = [
        // Scheduling settings
        [
            'key' => 'scheduling_enabled',
            'value' => 'true',
            'data_type' => 'boolean',
            'group' => 'scheduling',
            'description' => 'Enable or disable automatic scheduling',
            'is_public' => false,
        ],
        [
            'key' => 'scheduling_priority',
            'value' => 'balanced',
            'data_type' => 'string',
            'group' => 'scheduling',
            'description' => 'Priority for automatic scheduling (cost, distance, availability, balanced)',
            'is_public' => false,
        ],
        [
            'key' => 'min_days_advance',
            'value' => '1',
            'data_type' => 'integer',
            'group' => 'scheduling',
            'description' => 'Minimum days in advance for scheduling',
            'is_public' => false,
        ],
        [
            'key' => 'allow_manual_override',
            'value' => 'true',
            'data_type' => 'boolean',
            'group' => 'scheduling',
            'description' => 'Allow manual override of automatic scheduling',
            'is_public' => false,
        ],
        
        // Payment settings
        [
            'key' => 'payment_method',
            'value' => 'on_confirmation',
            'data_type' => 'string',
            'group' => 'payment',
            'description' => 'When to charge (on_scheduling, on_confirmation)',
            'is_public' => false,
        ],
        [
            'key' => 'auto_generate_invoice',
            'value' => 'true',
            'data_type' => 'boolean',
            'group' => 'payment',
            'description' => 'Automatically generate invoices',
            'is_public' => false,
        ],
        
        // Notification settings
        [
            'key' => 'email_notifications',
            'value' => 'true',
            'data_type' => 'boolean',
            'group' => 'notifications',
            'description' => 'Enable email notifications',
            'is_public' => true,
        ],
        [
            'key' => 'sms_notifications',
            'value' => 'true',
            'data_type' => 'boolean',
            'group' => 'notifications',
            'description' => 'Enable SMS notifications',
            'is_public' => true,
        ],
        
        // System settings
        [
            'key' => 'system_name',
            'value' => 'Healthcare Backoffice System',
            'data_type' => 'string',
            'group' => 'system',
            'description' => 'System name',
            'is_public' => true,
        ],
        [
            'key' => 'contact_email',
            'value' => 'support@healthcaresystem.com',
            'data_type' => 'string',
            'group' => 'system',
            'description' => 'Contact email',
            'is_public' => true,
        ],
    ];

    /**
     * Reset all system settings to their default values.
     *
     * @param int|null $userId
     * @return bool
     */
    public static function resetAll(?int $userId = null): bool
    {
        try {
            DB::beginTransaction();
            
            // Delete all existing settings
            SystemSetting::truncate();
            
            // Create default settings
            foreach (self::$defaults as $defaultSetting) {
                SystemSetting::create(array_merge($defaultSetting, [
                    'updated_by' => $userId,
                ]));
            }
            
            DB::commit();
            
            // Clear the cache
            Settings::flush();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error resetting system settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reset settings for a specific group.
     *
     * @param string $group
     * @param int|null $userId
     * @return bool
     */
    public static function resetGroup(string $group, ?int $userId = null): bool
    {
        try {
            DB::beginTransaction();
            
            // Delete settings for the specified group
            SystemSetting::where('group', $group)->delete();
            
            // Create default settings for the group
            $groupDefaults = array_filter(self::$defaults, function ($setting) use ($group) {
                return $setting['group'] === $group;
            });
            
            foreach ($groupDefaults as $defaultSetting) {
                SystemSetting::create(array_merge($defaultSetting, [
                    'updated_by' => $userId,
                ]));
            }
            
            DB::commit();
            
            // Clear the cache for this group
            Settings::getGroup($group); // This will refresh the cache
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error resetting system settings for group '$group': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if default settings are installed.
     *
     * @return bool
     */
    public static function areDefaultsInstalled(): bool
    {
        return SystemSetting::count() >= count(self::$defaults);
    }

    /**
     * Install default settings if they don't exist.
     *
     * @param int|null $userId
     * @return bool
     */
    public static function installDefaults(?int $userId = null): bool
    {
        if (self::areDefaultsInstalled()) {
            return true; // Already installed
        }
        
        try {
            DB::beginTransaction();
            
            foreach (self::$defaults as $defaultSetting) {
                // Only create if the setting doesn't exist
                if (!SystemSetting::where('key', $defaultSetting['key'])->exists()) {
                    SystemSetting::create(array_merge($defaultSetting, [
                        'updated_by' => $userId,
                    ]));
                }
            }
            
            DB::commit();
            
            // Clear the cache
            Settings::flush();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error installing default system settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all default settings.
     *
     * @return array
     */
    public static function getDefaults(): array
    {
        return self::$defaults;
    }
} 