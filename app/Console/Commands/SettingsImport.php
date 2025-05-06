<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SettingsImport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:import 
                           {path : Path to import file} 
                           {--force : Overwrite existing settings without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import system settings from a JSON file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');
        $force = $this->option('force');
        
        if (!File::exists($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }
        
        try {
            $jsonContent = File::get($path);
            $data = json_decode($jsonContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON file');
                return 1;
            }
            
            if (!isset($data['settings']) || !is_array($data['settings'])) {
                $this->error('Invalid settings file format: missing "settings" array');
                return 1;
            }
            
            $this->info('Loading settings from file...');
            
            // Validate settings format
            $invalidSettings = [];
            $existingSettings = [];
            $newSettings = [];
            
            foreach ($data['settings'] as $index => $setting) {
                // Validate required fields
                if (!isset($setting['key']) || !isset($setting['value']) || !isset($setting['data_type'])) {
                    $invalidSettings[] = "Setting #{$index} is missing required fields (key, value, data_type)";
                    continue;
                }
                
                // Validate data type
                $validator = Validator::make(['type' => $setting['data_type']], [
                    'type' => [
                        'required',
                        Rule::in(['string', 'boolean', 'integer', 'float', 'array', 'json']),
                    ],
                ]);
                
                if ($validator->fails()) {
                    $invalidSettings[] = "Setting '{$setting['key']}' has invalid data type: {$setting['data_type']}";
                    continue;
                }
                
                // Check if setting already exists
                if (SystemSetting::where('key', $setting['key'])->exists()) {
                    $existingSettings[] = $setting['key'];
                } else {
                    $newSettings[] = $setting['key'];
                }
            }
            
            if (!empty($invalidSettings)) {
                $this->error('Found invalid settings:');
                foreach ($invalidSettings as $error) {
                    $this->line(" - {$error}");
                }
                return 1;
            }
            
            $this->info(sprintf(
                'Found %d settings (%d new, %d existing)',
                count($data['settings']),
                count($newSettings),
                count($existingSettings)
            ));
            
            if (!empty($existingSettings) && !$force) {
                if (!$this->confirm('This will overwrite existing settings. Continue?')) {
                    $this->info('Import canceled.');
                    return 0;
                }
            }
            
            // Import settings
            DB::beginTransaction();
            try {
                $imported = 0;
                
                foreach ($data['settings'] as $setting) {
                    $key = $setting['key'];
                    $value = $setting['value'];
                    $dataType = $setting['data_type'];
                    
                    // Convert value to string for storage
                    if ($dataType === 'array' || $dataType === 'json') {
                        if (!is_string($value)) {
                            $value = json_encode($value);
                        }
                    } elseif ($dataType === 'boolean') {
                        $value = $value ? 'true' : 'false';
                    } else {
                        $value = (string) $value;
                    }
                    
                    $settingData = [
                        'key' => $key,
                        'value' => $value,
                        'data_type' => $dataType,
                        'group' => $setting['group'] ?? 'general',
                        'description' => $setting['description'] ?? null,
                        'is_public' => $setting['is_public'] ?? false,
                    ];
                    
                    SystemSetting::updateOrCreate(['key' => $key], $settingData);
                    $imported++;
                }
                
                DB::commit();
                $this->info("Successfully imported {$imported} settings.");
                
                return 0;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error('Error during import: ' . $e->getMessage());
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('Failed to read import file: ' . $e->getMessage());
            return 1;
        }
    }
} 