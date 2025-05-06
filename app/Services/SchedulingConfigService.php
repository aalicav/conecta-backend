<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SchedulingConfigService
{
    /**
     * Priority constants
     */
    const PRIORITY_COST = 'cost';
    const PRIORITY_DISTANCE = 'distance';
    const PRIORITY_AVAILABILITY = 'availability';
    const PRIORITY_BALANCED = 'balanced';

    /**
     * Check if automatic scheduling is enabled
     *
     * @return bool
     */
    public static function isAutomaticSchedulingEnabled(): bool
    {
        return self::getBoolSetting('scheduling_enabled', false);
    }

    /**
     * Get the current scheduling priority
     *
     * @return string
     */
    public static function getSchedulingPriority(): string
    {
        return self::getSetting('scheduling_priority', self::PRIORITY_BALANCED);
    }

    /**
     * Get the minimum days ahead for scheduling
     *
     * @return int
     */
    public static function getMinDaysAhead(): int
    {
        return (int)self::getSetting('scheduling_min_days', '1');
    }

    /**
     * Check if manual override is allowed
     *
     * @return bool
     */
    public static function allowManualOverride(): bool
    {
        return self::getBoolSetting('allow_manual_override', true);
    }

    /**
     * Enable or disable automatic scheduling
     *
     * @param bool $enabled
     * @return bool
     */
    public static function setAutomaticScheduling(bool $enabled): bool
    {
        return self::updateSetting('scheduling_enabled', $enabled ? 'true' : 'false');
    }

    /**
     * Set the scheduling priority
     *
     * @param string $priority
     * @return bool
     */
    public static function setSchedulingPriority(string $priority): bool
    {
        if (!in_array($priority, [
            self::PRIORITY_COST,
            self::PRIORITY_DISTANCE,
            self::PRIORITY_AVAILABILITY,
            self::PRIORITY_BALANCED
        ])) {
            return false;
        }

        return self::updateSetting('scheduling_priority', $priority);
    }

    /**
     * Set the minimum days ahead for scheduling
     *
     * @param int $days
     * @return bool
     */
    public static function setMinDaysAhead(int $days): bool
    {
        if ($days < 0) {
            return false;
        }

        return self::updateSetting('scheduling_min_days', (string)$days);
    }

    /**
     * Set whether manual override is allowed
     *
     * @param bool $allowed
     * @return bool
     */
    public static function setManualOverride(bool $allowed): bool
    {
        return self::updateSetting('allow_manual_override', $allowed ? 'true' : 'false');
    }

    /**
     * Get a boolean setting
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    private static function getBoolSetting(string $key, bool $default): bool
    {
        $value = self::getSetting($key, $default ? 'true' : 'false');
        return $value === 'true';
    }

    /**
     * Get a setting value
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    private static function getSetting(string $key, string $default): string
    {
        return Cache::remember('setting_' . $key, 3600, function () use ($key, $default) {
            $setting = SystemSetting::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    /**
     * Update a setting
     *
     * @param string $key
     * @param string $value
     * @return bool
     */
    private static function updateSetting(string $key, string $value): bool
    {
        try {
            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
            
            Cache::forget('setting_' . $key);
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to update setting {$key}: " . $e->getMessage());
            return false;
        }
    }
} 