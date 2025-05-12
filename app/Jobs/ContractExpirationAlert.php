<?php

namespace App\Jobs;

use App\Models\Document;
use App\Notifications\ContractExpirationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ContractExpirationAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $document;

    /**
     * Create a new job instance.
     */
    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Get the health plan and its representatives
            $healthPlan = $this->document->documentable;
            
            if (!$healthPlan) {
                Log::error('Health plan not found for document: ' . $this->document->id);
                return;
            }

            // Get users to notify
            $usersToNotify = collect();

            // Add legal representative if exists
            if ($healthPlan->legal_representative_email) {
                $legalRep = \App\Models\User::where('email', $healthPlan->legal_representative_email)->first();
                if ($legalRep) {
                    $usersToNotify->push($legalRep);
                }
            }

            // Add operational representative if exists
            if ($healthPlan->operational_representative_email) {
                $opRep = \App\Models\User::where('email', $healthPlan->operational_representative_email)->first();
                if ($opRep) {
                    $usersToNotify->push($opRep);
                }
            }

            // Add health plan admin
            $admin = $healthPlan->user;
            if ($admin) {
                $usersToNotify->push($admin);
            }

            // Add system admins
            $systemAdmins = \App\Models\User::role('admin')->get();
            $usersToNotify = $usersToNotify->merge($systemAdmins);

            // Send notifications
            foreach ($usersToNotify as $user) {
                $user->notify(new ContractExpirationNotification($this->document));
            }

            Log::info('Contract expiration alert sent', [
                'document_id' => $this->document->id,
                'health_plan_id' => $healthPlan->id,
                'notified_users_count' => $usersToNotify->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending contract expiration alert: ' . $e->getMessage());
        }
    }
} 