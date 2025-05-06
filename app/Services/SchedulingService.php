<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Professional;
use App\Models\Solicitation;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchedulingService
{
    /**
     * Schedule a solicitation.
     *
     * @param Solicitation $solicitation
     * @param bool $isAutomatic
     * @return array
     */
    public function scheduleSolicitation(Solicitation $solicitation, bool $isAutomatic = false): array
    {
        Log::info("Scheduling solicitation #{$solicitation->id}", [
            'auto' => $isAutomatic,
            'tuss' => $solicitation->tuss_id,
            'priority' => $solicitation->priority
        ]);

        try {
            // Start a database transaction
            DB::beginTransaction();

            // Find the best provider for the solicitation
            $provider = $this->findBestProvider($solicitation);

            if (!$provider) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'No suitable provider found for this solicitation',
                ];
            }

            // Create an appointment with the provider
            $appointment = $this->createAppointment($solicitation, $provider);

            if (!$appointment) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Failed to create appointment with the selected provider',
                ];
            }

            // Mark the solicitation as scheduled
            $solicitation->markAsScheduled($isAutomatic);

            // Commit the transaction
            DB::commit();

            return [
                'success' => true,
                'message' => 'Solicitation scheduled successfully',
                'appointment' => $appointment,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error scheduling solicitation #{$solicitation->id}: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'An error occurred during scheduling: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Find the best provider for a solicitation based on system settings.
     *
     * @param Solicitation $solicitation
     * @return Professional|Clinic|null
     */
    protected function findBestProvider(Solicitation $solicitation)
    {
        // Get scheduling priority from settings
        $priority = Settings::get('scheduling_priority', 'balanced');
        
        // In a real implementation, this would be a complex algorithm
        // that considers various factors based on the priority setting.
        // For now, let's simulate a basic implementation that finds either
        // a professional or a clinic that can handle the TUSS procedure.

        // Get all providers with active contracts for this TUSS code
        $providers = $this->getAvailableProviders($solicitation);
        
        if (empty($providers)) {
            return null;
        }
        
        // Choose provider based on priority
        return $this->selectProviderByPriority($providers, $priority, $solicitation);
    }

    /**
     * Get all available providers for a solicitation.
     *
     * @param Solicitation $solicitation
     * @return array
     */
    protected function getAvailableProviders(Solicitation $solicitation): array
    {
        // This is a placeholder implementation.
        // In a real system, this would query the database to find providers
        // with active contracts for the TUSS code and availability during
        // the preferred date range.

        $professionals = Professional::where('is_active', true)
            ->whereHas('contracts', function ($query) use ($solicitation) {
                $query->where('tuss_id', $solicitation->tuss_id)
                    ->where('is_active', true);
            })
            ->whereHas('availabilities', function ($query) use ($solicitation) {
                $query->where('start_time', '<=', $solicitation->preferred_date_end)
                    ->where('end_time', '>=', $solicitation->preferred_date_start);
            })
            ->get();
            
        $clinics = Clinic::where('is_active', true)
            ->whereHas('contracts', function ($query) use ($solicitation) {
                $query->where('tuss_id', $solicitation->tuss_id)
                    ->where('is_active', true);
            })
            ->get();
            
        return [
            'professionals' => $professionals,
            'clinics' => $clinics,
        ];
    }

    /**
     * Select a provider based on priority settings.
     *
     * @param array $providers
     * @param string $priority
     * @param Solicitation $solicitation
     * @return Professional|Clinic|null
     */
    protected function selectProviderByPriority(array $providers, string $priority, Solicitation $solicitation)
    {
        // This is a simplified implementation.
        // In a real system, this would apply complex algorithms based on the priority.

        switch ($priority) {
            case 'cost':
                // Find provider with lowest cost
                return $this->findLowestCostProvider($providers, $solicitation);
                
            case 'distance':
                // Find provider closest to the patient
                return $this->findClosestProvider($providers, $solicitation);
                
            case 'availability':
                // Find provider with earliest availability
                return $this->findEarliestAvailableProvider($providers, $solicitation);
                
            case 'balanced':
            default:
                // Use a balanced approach considering all factors
                return $this->findBalancedProvider($providers, $solicitation);
        }
    }

    /**
     * Find the provider with the lowest cost.
     *
     * @param array $providers
     * @param Solicitation $solicitation
     * @return Professional|Clinic|null
     */
    protected function findLowestCostProvider(array $providers, Solicitation $solicitation)
    {
        // In a real implementation, this would compare contract costs
        // For now, just return the first available provider
        
        if (!empty($providers['professionals'])) {
            return $providers['professionals']->first();
        }
        
        if (!empty($providers['clinics'])) {
            return $providers['clinics']->first();
        }
        
        return null;
    }

    /**
     * Find the provider closest to the patient.
     *
     * @param array $providers
     * @param Solicitation $solicitation
     * @return Professional|Clinic|null
     */
    protected function findClosestProvider(array $providers, Solicitation $solicitation)
    {
        // In a real implementation, this would calculate distances
        // For now, just return the first available provider
        
        if (!empty($providers['professionals'])) {
            return $providers['professionals']->first();
        }
        
        if (!empty($providers['clinics'])) {
            return $providers['clinics']->first();
        }
        
        return null;
    }

    /**
     * Find the provider with the earliest availability.
     *
     * @param array $providers
     * @param Solicitation $solicitation
     * @return Professional|Clinic|null
     */
    protected function findEarliestAvailableProvider(array $providers, Solicitation $solicitation)
    {
        // In a real implementation, this would compare availabilities
        // For now, just return the first available provider
        
        if (!empty($providers['professionals'])) {
            return $providers['professionals']->first();
        }
        
        if (!empty($providers['clinics'])) {
            return $providers['clinics']->first();
        }
        
        return null;
    }

    /**
     * Find the best provider using a balanced approach.
     *
     * @param array $providers
     * @param Solicitation $solicitation
     * @return Professional|Clinic|null
     */
    protected function findBalancedProvider(array $providers, Solicitation $solicitation)
    {
        // In a real implementation, this would use a weighted algorithm
        // For now, just return the first available provider
        
        if (!empty($providers['professionals'])) {
            return $providers['professionals']->first();
        }
        
        if (!empty($providers['clinics'])) {
            return $providers['clinics']->first();
        }
        
        return null;
    }

    /**
     * Create an appointment for a solicitation with a provider.
     *
     * @param Solicitation $solicitation
     * @param Professional|Clinic $provider
     * @return Appointment|null
     */
    protected function createAppointment(Solicitation $solicitation, $provider)
    {
        try {
            // Find the best date/time within the preferred range
            $scheduledDate = $this->findBestTimeSlot($solicitation, $provider);
            
            if (!$scheduledDate) {
                return null;
            }
            
            // Create the appointment
            $appointment = new Appointment();
            $appointment->solicitation_id = $solicitation->id;
            $appointment->provider_type = get_class($provider);
            $appointment->provider_id = $provider->id;
            $appointment->status = 'scheduled';
            $appointment->scheduled_date = $scheduledDate;
            $appointment->save();
            
            return $appointment;
        } catch (\Exception $e) {
            Log::error('Error creating appointment: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find the best time slot for an appointment.
     *
     * @param Solicitation $solicitation
     * @param Professional|Clinic $provider
     * @return \Carbon\Carbon|null
     */
    protected function findBestTimeSlot(Solicitation $solicitation, $provider)
    {
        // This is a placeholder implementation.
        // In a real system, this would find an available time slot
        // within the provider's availability and the solicitation's
        // preferred date range.
        
        // For now, just use the start of the preferred date range
        return $solicitation->preferred_date_start;
    }
} 