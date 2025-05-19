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

class ContractExpirationAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $contract;

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
            // Ensure we still have a valid contract
            $contract = Contract::find($this->contract->id);
            
            if (!$contract) {
                Log::error("Contract expiration alert failed: Contract ID {$this->contract->id} not found");
                return;
            }

            // Check if contract is still valid for alerting
            if (!$contract->end_date || $contract->end_date->isPast()) {
                Log::info("Skipping expiration alert for contract #{$contract->contract_number}: contract has expired or has no end date");
                return;
            }

            // Calculate days until expiration
            $daysUntilExpiration = now()->diffInDays($contract->end_date);
            $expectedDays = $contract->alert_days_before_expiration;

            // If we're off by more than 7 days, log it (could happen due to queue delays)
            if (abs($daysUntilExpiration - $expectedDays) > 7) {
                Log::warning("Contract expiration alert timing off: expected {$expectedDays} days before expiration, actual {$daysUntilExpiration} days", [
                    'contract_id' => $contract->id,
                    'contract_number' => $contract->contract_number,
                    'end_date' => $contract->end_date->format('Y-m-d')
                ]);
            }

            // Get all recipients for the notification
            $recipients = $contract->getAlertRecipients();

            // Send notifications
            foreach ($recipients as $recipient) {
                $recipient->notify(new ContractExpirationNotification($contract, false));
            }

            // Update contract to record that we sent the alert
            $contract->update([
                'last_alert_sent_at' => now(),
                'alert_count' => $contract->alert_count + 1
            ]);

            // Schedule a recurring alert for when the contract expires (if not renewed)
            $expirationDate = $contract->end_date;
            RecurringContractExpirationAlert::dispatch($contract)
                ->delay($expirationDate);

            Log::info("Contract expiration alert sent for contract #{$contract->contract_number}", [
                'contract_id' => $contract->id,
                'days_until_expiration' => $daysUntilExpiration,
                'recipients_count' => $recipients->count()
            ]);
        } catch (\Exception $e) {
            Log::error("Error sending contract expiration alert: " . $e->getMessage(), [
                'contract_id' => $this->contract->id ?? null,
                'exception' => $e
            ]);
        }
    }
} 