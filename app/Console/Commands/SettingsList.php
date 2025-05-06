<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;

class SettingsList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:list {--group= : Filter by group} {--public : Show only public settings} {--format=table : Output format (table|json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all system settings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $group = $this->option('group');
        $onlyPublic = $this->option('public');
        $format = $this->option('format');
        
        // Build query
        $query = SystemSetting::query();
        
        if ($group) {
            $query->where('group', $group);
        }
        
        if ($onlyPublic) {
            $query->where('is_public', true);
        }
        
        $settings = $query->orderBy('group')->orderBy('key')->get();
        
        if ($settings->isEmpty()) {
            $this->info('No settings found.');
            return 0;
        }
        
        // Format output
        if ($format === 'json') {
            $this->output->writeln(json_encode($settings, JSON_PRETTY_PRINT));
            return 0;
        }
        
        // Format for table output
        $rows = $settings->map(function ($setting) {
            return [
                'key' => $setting->key,
                'value' => $this->formatValueForDisplay($setting->value, $setting->data_type),
                'type' => $setting->data_type,
                'group' => $setting->group,
                'public' => $setting->is_public ? 'Yes' : 'No',
                'description' => $setting->description ?: '-',
            ];
        });
        
        $this->table(
            ['Key', 'Value', 'Type', 'Group', 'Public', 'Description'],
            $rows
        );
        
        return 0;
    }
    
    /**
     * Format value for display based on data type
     *
     * @param string $value
     * @param string $dataType
     * @return string
     */
    private function formatValueForDisplay($value, $dataType)
    {
        if ($dataType === 'boolean') {
            return $value === 'true' ? '<fg=green>true</>' : '<fg=red>false</>';
        }
        
        if (in_array($dataType, ['array', 'json']) && $this->isJson($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && count($decoded) > 3) {
                return '[' . implode(', ', array_slice($decoded, 0, 3)) . ', ...]';
            }
            return json_encode($decoded, JSON_UNESCAPED_SLASHES);
        }
        
        return (string) $value;
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