<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SettingsExport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:export 
                           {path=settings.json : Path to export file} 
                           {--group= : Export only specific group} 
                           {--exclude-private : Exclude private settings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export system settings to a JSON file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');
        $group = $this->option('group');
        $excludePrivate = $this->option('exclude-private');
        
        $query = SystemSetting::query();
        
        if ($group) {
            $query->where('group', $group);
        }
        
        if ($excludePrivate) {
            $query->where('is_public', true);
        }
        
        $settings = $query->orderBy('group')->orderBy('key')->get();
        
        if ($settings->isEmpty()) {
            $this->error('No settings found to export.');
            return 1;
        }
        
        $exportData = [
            'metadata' => [
                'exported_at' => now()->toIso8601String(),
                'count' => $settings->count(),
                'version' => '1.0',
            ],
            'settings' => [],
        ];
        
        foreach ($settings as $setting) {
            $valueForExport = $setting->value;
            
            // Format JSON and arrays for better readability
            if ($setting->data_type === 'array' || $setting->data_type === 'json') {
                $valueForExport = json_decode($valueForExport, true);
            } elseif ($setting->data_type === 'boolean') {
                $valueForExport = $valueForExport === 'true';
            } elseif ($setting->data_type === 'integer') {
                $valueForExport = (int) $valueForExport;
            } elseif ($setting->data_type === 'float') {
                $valueForExport = (float) $valueForExport;
            }
            
            $exportData['settings'][] = [
                'key' => $setting->key,
                'value' => $valueForExport,
                'group' => $setting->group,
                'description' => $setting->description,
                'is_public' => $setting->is_public,
                'data_type' => $setting->data_type,
            ];
        }
        
        try {
            File::put($path, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info(sprintf(
                'Successfully exported %d settings to %s',
                $settings->count(),
                $path
            ));
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to export settings: ' . $e->getMessage());
            return 1;
        }
    }
} 