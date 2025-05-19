<?php

namespace App\Jobs;

use App\Models\Contract;
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

    protected $contract;
    protected $maxAttempts = 3;
    protected $attemptDelay = 7; // days between recurring alerts

    /**
     * Create a new job instance.
     */
    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Check if contract still exists and is not renewed
            $contract = Contract::find($this->contract->id);
            
            if (!$contract) {
                Log::error("Recurring contract expiration alert failed: Contract ID {$this->contract->id} not found");
                return;
            }

            // Check if contract has been renewed (has a new version)
            $hasRenewal = Contract::where('contractable_type', $contract->contractable_type)
                ->where('contractable_id', $contract->contractable_id)
                ->where('start_date', '>', $contract->end_date)
                ->exists();

            if ($hasRenewal) {
                Log::info("Contract #{$contract->contract_number} has been renewed, skipping recurring alert");
                return;
            }

            // Check if contract is already well past expiration (more than 30 days)
            if ($contract->end_date && $contract->end_date->addDays(30)->isPast()) {
                // Only send max 3 alerts for severely expired contracts
                if ($contract->alert_count >= 3) {
                    Log::info("Contract #{$contract->contract_number} is severely expired and has reached max alerts, stopping alerts");
                    return;
                }
            }

            // Get all recipients for notification
            $recipients = $contract->getAlertRecipients();

            // Send notifications
            foreach ($recipients as $recipient) {
                $recipient->notify(new ContractExpirationNotification($contract, true));
            }

            // Update contract alert status
            $contract->update([
                'last_alert_sent_at' => now(),
                'alert_count' => $contract->alert_count + 1
            ]);

            // Schedule next alert if we haven't reached max attempts
            if ($contract->alert_count < $this->maxAttempts) {
                // Schedule next alert
                $nextAlertDate = Carbon::now()->addDays($this->attemptDelay);
                self::dispatch($contract)->delay($nextAlertDate);
            }

            Log::info("Recurring contract expiration alert sent for contract #{$contract->contract_number}", [
                'contract_id' => $contract->id,
                'alert_count' => $contract->alert_count,
                'recipients_count' => $recipients->count()
            ]);

        } catch (\Exception $e) {
            Log::error("Error sending recurring contract expiration alert: " . $e->getMessage(), [
                'contract_id' => $this->contract->id ?? null,
                'exception' => $e
            ]);
        }
    }
} 