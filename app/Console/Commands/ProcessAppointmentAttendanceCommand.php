<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ProcessAppointmentAttendance;

class ProcessAppointmentAttendanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:process-attendance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process attendance for confirmed appointments and handle billing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting appointment attendance processing...');
        
        ProcessAppointmentAttendance::dispatch();
        
        $this->info('Job dispatched successfully.');
    }
} 