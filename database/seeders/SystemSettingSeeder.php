<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Services\SystemSettingDefaults;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding system settings...');
        
        // Install default settings
        $success = SystemSettingDefaults::installDefaults();
        
        if ($success) {
            $this->command->info('System settings seeded successfully.');
        } else {
            $this->command->error('Failed to seed system settings.');
        }
        
        // Report the number of settings
        $count = SystemSetting::count();
        $this->command->info("Total system settings: {$count}");
    }
} 