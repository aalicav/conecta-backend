<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;

class SettingsGet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:get {key : The setting key to retrieve}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the value of a specific system setting';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $key = $this->argument('key');
        
        $setting = SystemSetting::where('key', $key)->first();
        
        if (!$setting) {
            $this->error("Setting with key '{$key}' not found");
            return 1;
        }
        
        $value = $this->formatValue($setting->value, $setting->data_type);
        
        // Display setting details
        $this->info("Setting: {$key}");
        $this->table(
            ['Attribute', 'Value'],
            [
                ['Key', $setting->key],
                ['Value', $this->formatValueForDisplay($value, $setting->data_type)],
                ['Type', $setting->data_type],
                ['Group', $setting->group ?? 'general'],
                ['Description', $setting->description ?? 'No description'],
                ['Public', $setting->is_public ? 'Yes' : 'No'],
            ]
        );
        
        return 0;
    }
    
    /**
     * Format the setting value based on its data type
     *
     * @param string $value
     * @param string $dataType
     * @return mixed
     */
    private function formatValue($value, $dataType)
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
                return json_decode($value, true);
            default:
                return $value;
        }
    }
    
    /**
     * Format the value for display in console
     *
     * @param mixed $value
     * @param string $dataType
     * @return string
     */
    private function formatValueForDisplay($value, $dataType)
    {
        if ($dataType === 'boolean') {
            return $value ? 'true' : 'false';
        } elseif ($dataType === 'array' || $dataType === 'json') {
            return json_encode($value, JSON_PRETTY_PRINT);
        } elseif (is_scalar($value)) {
            return (string) $value;
        } else {
            return var_export($value, true);
        }
    }
} 