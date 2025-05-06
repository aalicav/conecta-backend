<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, $default = null)
 * @method static bool has(string $key)
 * @method static bool set(string $key, $value, ?int $userId = null)
 * @method static array getGroup(string $group)
 * @method static bool getBool(string $key, bool $default = false)
 * @method static int getInt(string $key, int $default = 0)
 * @method static float getFloat(string $key, float $default = 0.0)
 * @method static array getArray(string $key, array $default = [])
 * 
 * @see \App\Services\Settings
 */
class Setting extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'settings';
    }
} 