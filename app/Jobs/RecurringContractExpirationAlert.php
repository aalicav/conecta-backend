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
use Carbon\Carbon;

class RecurringContractExpirationAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $document;
    protected $maxAttempts = 3;
    protected $attemptDelay = 7; // days

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
            // Check if document still exists and is not renewed
            $document = Document::find($this->document->id);
            if (!$document || $document->is_renewed) {
                return;
            }

            // Get the health plan and its representatives
            $healthPlan = $document->documentable;
            if (!$healthPlan) {
                Log::error('Health plan not found for document: ' . $document->id);
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
                $user->notify(new ContractExpirationNotification($document, true));
            }

            // Schedule next alert if we haven't reached max attempts
            $attempts = $document->expiration_alert_attempts ?? 0;
            if ($attempts < $this->maxAttempts) {
                $document->expiration_alert_attempts = $attempts + 1;
                $document->save();

                // Schedule next alert
                $nextAlertDate = Carbon::now()->addDays($this->attemptDelay);
                $this->dispatch($document)->delay($nextAlertDate);
            }

            Log::info('Recurring contract expiration alert sent', [
                'document_id' => $document->id,
                'health_plan_id' => $healthPlan->id,
                'notified_users_count' => $usersToNotify->count(),
                'attempt' => $attempts + 1
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending recurring contract expiration alert: ' . $e->getMessage());
        }
    }
} 