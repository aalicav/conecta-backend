<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;

class SettingsSet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:set {key : The setting key} {value : The value to set} {--type=string : Data type (string|integer|float|boolean|array|json)} {--group=general : Setting group} {--description= : Setting description} {--public : Make setting publicly accessible}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update a system setting';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $key = $this->argument('key');
        $value = $this->argument('value');
        $dataType = $this->option('type');
        $group = $this->option('group');
        $description = $this->option('description');
        $isPublic = $this->option('public');
        
        // Validate data type
        $validTypes = ['string', 'integer', 'float', 'boolean', 'array', 'json'];
        if (!in_array($dataType, $validTypes)) {
            $this->error("Invalid data type. Must be one of: " . implode(', ', $validTypes));
            return 1;
        }
        
        // Format value based on data type
        $formattedValue = $this->formatValue($value, $dataType);
        
        try {
            // Create or update the setting
            $setting = SystemSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $formattedValue,
                    'data_type' => $dataType,
                    'group' => $group,
                    'description' => $description,
                    'is_public' => $isPublic,
                ]
            );
            
            $this->info("Setting '{$key}' has been saved successfully.");
            return 0;
        } catch (\Exception $e) {
            $this->error("Error saving setting: " . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Format the value based on the specified data type
     *
     * @param string $value
     * @param string $dataType
     * @return string
     */
    private function formatValue($value, $dataType)
    {
        switch ($dataType) {
            case 'integer':
                return (string)(int) $value;
            case 'float':
                return (string)(float) $value;
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
            case 'array':
            case 'json':
                // If value is already a JSON string, validate it
                if (is_string($value) && $this->isJson($value)) {
                    return $value;
                }
                
                // Try to treat value as comma-separated list
                if (is_string($value) && strpos($value, ',') !== false) {
                    $array = array_map('trim', explode(',', $value));
                    return json_encode($array);
                }
                
                $this->warn("Value doesn't appear to be valid JSON or comma-separated list. Storing as JSON string.");
                return json_encode([$value]);
            default:
                return (string) $value;
        }
    }
    
    /**
     * Check if a string is valid JSON
     *
     * @param string $string
     * @return boolean
     */
    private function isJson($string)
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
} 