<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class Settings
{
    /**
     * Cache lifetime in seconds
     */
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Cache key prefix
     */
    private const CACHE_PREFIX = 'app_setting_';

    /**
     * Get a system setting by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            function () use ($key, $default) {
                return SystemSetting::getValue($key, $default);
            }
        );
    }

    /**
     * Check if a system setting exists.
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return SystemSetting::where('key', $key)->exists();
    }

    /**
     * Set a system setting value.
     *
     * @param string $key
     * @param mixed $value
     * @param string|null $group
     * @param string|null $description
     * @param bool $is_public
     * @return bool
     */
    public static function set(string $key, $value, ?string $group = null, ?string $description = null, bool $is_public = false): bool
    {
        // Check if setting exists
        $setting = SystemSetting::where('key', $key)->first();
        
        // Convert value to string for storage
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
            $dataType = 'json';
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
            $dataType = 'boolean';
        } elseif (is_int($value)) {
            $value = (string) $value;
            $dataType = 'integer';
        } elseif (is_float($value)) {
            $value = (string) $value;
            $dataType = 'float';
        } else {
            $value = (string) $value;
            $dataType = 'string';
        }
        
        if ($setting) {
            // Update existing setting
            $data = ['value' => $value];
            
            if ($group) {
                $data['group'] = $group;
            }
            
            if ($description) {
                $data['description'] = $description;
            }
            
            $data['is_public'] = $is_public;
            
            $result = $setting->update($data);
        } else {
            // Create new setting
            try {
                SystemSetting::create([
                    'key' => $key,
                    'value' => $value,
                    'data_type' => $dataType,
                    'group' => $group ?? 'general',
                    'description' => $description ?? "Setting {$key}",
                    'is_public' => $is_public,
                ]);
                $result = true;
            } catch (\Exception $e) {
                return false;
            }
        }
        
        // Clear the cache
        Cache::forget(self::CACHE_PREFIX . $key);
        Cache::forget(self::CACHE_PREFIX . 'all');
        if ($setting) {
            Cache::forget(self::CACHE_PREFIX . 'group_' . $setting->group);
        }
        if ($group) {
            Cache::forget(self::CACHE_PREFIX . 'group_' . $group);
        }
        
        return $result;
    }

    /**
     * Remove a system setting.
     *
     * @param string $key
     * @return bool
     */
    public static function forget(string $key): bool
    {
        $setting = SystemSetting::where('key', $key)->first();
        
        if (!$setting) {
            return false;
        }
        
        $group = $setting->group;
        $result = $setting->delete();
        
        // Clear the cache
        Cache::forget(self::CACHE_PREFIX . $key);
        Cache::forget(self::CACHE_PREFIX . 'all');
        Cache::forget(self::CACHE_PREFIX . 'group_' . $group);
        
        return $result;
    }

    /**
     * Get all system settings.
     *
     * @return array
     */
    public static function all(): array
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'all',
            self::CACHE_TTL,
            function () {
                $settings = SystemSetting::all();
                $result = [];
                
                foreach ($settings as $setting) {
                    $result[$setting->key] = SystemSetting::castValue($setting->value, $setting->data_type);
                }
                
                return $result;
            }
        );
    }

    /**
     * Load all settings into cache.
     *
     * @return void
     */
    public static function load(): void
    {
        self::all();
        
        // Load all groups
        $groups = SystemSetting::select('group')->distinct()->pluck('group');
        
        foreach ($groups as $group) {
            self::getGroup($group);
        }
    }

    /**
     * Flush all settings from cache.
     *
     * @return void
     */
    public static function flush(): void
    {
        $settings = SystemSetting::all();
        
        foreach ($settings as $setting) {
            Cache::forget(self::CACHE_PREFIX . $setting->key);
            Cache::forget(self::CACHE_PREFIX . 'group_' . $setting->group);
        }
        
        Cache::forget(self::CACHE_PREFIX . 'all');
    }

    /**
     * Get all settings for a specific group.
     *
     * @param string $group
     * @return array
     */
    public static function getGroup(string $group): array
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'group_' . $group,
            self::CACHE_TTL,
            function () use ($group) {
                return SystemSetting::getGroup($group);
            }
        );
    }

    /**
     * Get a boolean setting.
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    /**
     * Get an integer setting.
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    /**
     * Get a float setting.
     *
     * @param string $key
     * @param float $default
     * @return float
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, $default);
    }

    /**
     * Get an array or JSON setting.
     *
     * @param string $key
     * @param array $default
     * @return array
     */
    public static function getArray(string $key, array $default = []): array
    {
        $value = self::get($key);
        
        if (is_null($value)) {
            return $default;
        }
        
        if (is_array($value)) {
            return $value;
        }
        
        // Try to decode JSON
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }
} 