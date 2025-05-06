<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'data_type',
        'group',
        'description',
        'is_public',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_public' => 'boolean',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::updated(function ($setting) {
            // Clear cache when a setting is updated
            Cache::forget('system_setting_' . $setting->key);
            Cache::forget('system_settings_' . $setting->group);
        });

        static::created(function ($setting) {
            // Clear cache when a new setting is created
            Cache::forget('system_settings_' . $setting->group);
        });

        static::deleted(function ($setting) {
            // Clear cache when a setting is deleted
            Cache::forget('system_setting_' . $setting->key);
            Cache::forget('system_settings_' . $setting->group);
        });
    }

    /**
     * Get the user who last updated this setting.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get a setting value by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getValue(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        
        if (!$setting) {
            return $default;
        }
        
        return self::castValue($setting->value, $setting->data_type);
    }

    /**
     * Cast a value based on its data type.
     *
     * @param string $value
     * @param string $dataType
     * @return mixed
     */
    public static function castValue(string $value, string $dataType)
    {
        switch ($dataType) {
            case 'boolean':
                return $value === 'true' || $value === '1';
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'array':
            case 'json':
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];
            default:
                return $value;
        }
    }

    /**
     * Get all settings for a specific group.
     *
     * @param string $group
     * @return array
     */
    public static function getGroup(string $group): array
    {
        $settings = static::where('group', $group)->get();
        $result = [];
        
        foreach ($settings as $setting) {
            $result[$setting->key] = self::castValue($setting->value, $setting->data_type);
        }
        
        return $result;
    }

    /**
     * Set a system setting value.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $userId
     * @return bool
     */
    public static function setValue(string $key, $value, ?int $userId = null): bool
    {
        $setting = static::where('key', $key)->first();
        
        if (!$setting) {
            return false;
        }

        // Convert value to string for storage
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } else {
            $value = (string) $value;
        }

        $result = $setting->update([
            'value' => $value,
            'updated_by' => $userId,
        ]);

        // Clear the cache
        Cache::forget('system_setting_' . $key);
        Cache::forget('system_settings_' . $setting->group);

        return $result;
    }

    /**
     * Scope a query to only include public settings.
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope a query to only include settings in a specific group.
     */
    public function scopeInGroup($query, $group)
    {
        return $query->where('group', $group);
    }
} 