<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool has(string $key)
 * @method static void set(string $key, mixed $value, ?string $group = null, ?string $description = null, bool $is_public = false)
 * @method static void forget(string $key)
 * @method static array all()
 * @method static void load()
 * @method static void flush()
 * 
 * @see \App\Services\Settings
 */
class Settings extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'settings';
    }
} 