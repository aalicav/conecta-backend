<?php

namespace App\Services;

use App\Models\Solicitation;
use App\Models\Appointment;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\SchedulingException;
use App\Models\User;
use App\Models\NegotiationItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use App\Notifications\ProfessionalSchedulingRequest;
use App\Models\HealthPlan;

class AutomaticSchedulingService
{
    /**
     * Maximum distance in KM for proximity search
     */
    const MAX_DISTANCE_KM = 50;

    /**
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Create a new service instance.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function __construct()
    {
        $this->notificationService = new NotificationService();
    }

    /**
     * Schedule appointment automatically based on procedure, location and pricing
     *
     * @param Solicitation $solicitation
     * @return Appointment|null
     */
    public function scheduleAppointment(Solicitation $solicitation)
    {
        Log::info('Starting automatic scheduling for solicitation #' . $solicitation->id);
        
        try {
            // Mark solicitation as processing
            $solicitation->markAsProcessing();
            
            // 1. Find eligible providers (professionals and clinics)
            $providers = $this->findEligibleProviders($solicitation);
            
            if ($providers->isEmpty()) {
                Log::warning('No eligible providers found for solicitation #' . $solicitation->id);
                $solicitation->markAsFailed();
                return null;
            }
            
            // 2. Send WhatsApp notifications to all eligible professionals
            foreach ($providers as $provider) {
                if ($provider instanceof Professional) {
                    try {
                        $provider->notify(new ProfessionalSchedulingRequest($solicitation));
                        Log::info("Sent scheduling request to professional #{$provider->id} for solicitation #{$solicitation->id}");
                    } catch (\Exception $e) {
                        Log::error("Failed to send scheduling request to professional #{$provider->id}: " . $e->getMessage());
                    }
                }
            }
            
            // 3. Mark solicitation as waiting for professional response
            $solicitation->status = 'waiting_professional_response';
            $solicitation->save();
            
            Log::info('Successfully sent scheduling requests for solicitation #' . $solicitation->id);
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Error in automatic scheduling: ' . $e->getMessage());
            $solicitation->markAsFailed();
            return null;
        }
    }

    /**
     * Find eligible providers (professionals and clinics) for the solicitation
     *
     * @param Solicitation $solicitation
     * @return Collection
     */
    public function findEligibleProviders(Solicitation $solicitation)
    {
        $providers = collect();
        $tussId = $solicitation->tuss_id;
        $healthPlanId = $solicitation->health_plan_id;
        
        // Patient location
        $patientLat = $solicitation->preferred_location_lat;
        $patientLng = $solicitation->preferred_location_lng;
        $maxDistance = $solicitation->max_distance_km ?: self::MAX_DISTANCE_KM;
        
        // 1. Find eligible professionals with active pricing contracts for this procedure
        $professionals = Professional::with(['pricingContracts' => function ($query) use ($tussId, $healthPlanId) {
                $query->where('tuss_procedure_id', $tussId)
                      ->where('is_active', true)
                      ->where('contractable_type', 'App\\Models\\HealthPlan')
                      ->where('contractable_id', $healthPlanId)
                      ->where(function ($q) {
                          $q->whereNull('end_date')
                            ->orWhere('end_date', '>=', now());
                      });
            }])
            ->where('is_active', true)
            ->where('status', 'approved')
            ->get();
        
        // 2. Find eligible clinics with active pricing contracts
        $clinics = Clinic::with(['pricingContracts' => function ($query) use ($tussId, $healthPlanId) {
                $query->where('tuss_procedure_id', $tussId)
                      ->where('is_active', true)
                      ->where('contractable_type', 'App\\Models\\HealthPlan')
                      ->where('contractable_id', $healthPlanId)
                      ->where(function ($q) {
                          $q->whereNull('end_date')
                            ->orWhere('end_date', '>=', now());
                      });
            }])
            ->where('is_active', true)
            ->where('status', 'approved')
            ->get();
        
        // 3. Process professionals
        foreach ($professionals as $professional) {
            // Skip if no active pricing contracts found
            if ($professional->pricingContracts->isEmpty()) {
                continue;
            }
            
            // Calculate distance if coordinates available
            $distance = null;
            if ($patientLat && $patientLng && $professional->latitude && $professional->longitude) {
                $distance = $this->calculateDistance(
                    $patientLat, 
                    $patientLng, 
                    $professional->latitude, 
                    $professional->longitude
                );
                
                // Skip if too far
                if ($distance > $maxDistance) {
                    continue;
                }
            }
            
            // Get the price from active pricing contract
            $price = $this->getPriceFromPricingContract($professional->pricingContracts, $tussId);
            
            $providers->push([
                'type' => 'App\\Models\\Professional',
                'id' => $professional->id,
                'model' => $professional,
                'distance' => $distance,
                'price' => $price
            ]);
        }
        
        // 4. Process clinics
        foreach ($clinics as $clinic) {
            // Skip if no active pricing contracts found
            if ($clinic->pricingContracts->isEmpty()) {
                continue;
            }
            
            // Calculate distance if coordinates available
            $distance = null;
            if ($patientLat && $patientLng && $clinic->latitude && $clinic->longitude) {
                $distance = $this->calculateDistance(
                    $patientLat, 
                    $patientLng, 
                    $clinic->latitude, 
                    $clinic->longitude
                );
                
                // Skip if too far
                if ($distance > $maxDistance) {
                    continue;
                }
            }
            
            // Get the price from active pricing contract
            $price = $this->getPriceFromPricingContract($clinic->pricingContracts, $tussId);
            
            $providers->push([
                'type' => 'App\\Models\\Clinic',
                'id' => $clinic->id,
                'model' => $clinic,
                'distance' => $distance,
                'price' => $price
            ]);
        }
        
        // 5. Check for health plan specific pricing in new table
        if ($healthPlanId) {
            $healthPlan = HealthPlan::find($healthPlanId);
            if ($healthPlan) {
                $healthPlanPrice = $healthPlan->getProcedurePrice($tussId);
                if ($healthPlanPrice !== null) {
                    // Add health plan price to all providers that don't have specific pricing
                    $providers = $providers->map(function ($provider) use ($healthPlanPrice) {
                        if ($provider['price'] === null || $provider['price'] === 0) {
                            $provider['price'] = $healthPlanPrice;
                        }
                        return $provider;
                    });
                }
            }
        }
        
        return $providers->sortBy('distance')->values();
    }

    /**
     * Find the best provider with an available time slot
     *
     * @param Collection $providers
     * @param Solicitation $solicitation
     * @return array|null
     */
    protected function findBestProviderWithTimeSlot(Collection $providers, Solicitation $solicitation)
    {
        $preferredStartDate = $solicitation->preferred_date_start ?: Carbon::now()->addDay();
        $preferredEndDate = $solicitation->preferred_date_end ?: Carbon::now()->addDays(14);
        
        foreach ($providers as $provider) {
            // Get available time slots for the provider
            $availableSlots = $this->getAvailableTimeSlots(
                $provider['model'],
                $provider['type'],
                $preferredStartDate,
                $preferredEndDate,
                $solicitation->tuss
            );
            
            if (!empty($availableSlots)) {
                // Take the first available slot (earliest)
                $slot = $availableSlots[0];
                
                return [
                    'provider_type' => $provider['type'],
                    'provider_id' => $provider['id'],
                    'model' => $provider['model'],
                    'price' => $provider['price'],
                    'scheduled_date' => $slot['start_time'],
                    'distance' => $provider['distance']
                ];
            }
        }
        
        return null;
    }

    /**
     * Get available time slots for a provider
     *
     * @param mixed $provider
     * @param string $providerType
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param mixed $procedure
     * @return array
     */
    protected function getAvailableTimeSlots($provider, $providerType, $startDate, $endDate, $procedure)
    {
        // Get the provider's schedule/availability
        $schedules = $providerType === 'App\\Models\\Professional' 
            ? $provider->schedules 
            : $provider->workingHours;
        
        if (empty($schedules) || !$schedules->count()) {
            return [];
        }
        
        $availableSlots = [];
        $procedureDuration = $procedure->estimated_duration ?: 30; // Default 30 min if not specified
        
        // Iterate through each day in the date range
        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            $dayOfWeek = strtolower($currentDate->format('l'));
            
            // Find schedule for this day of week
            $daySchedule = $schedules->firstWhere('day_of_week', $dayOfWeek);
            
            if ($daySchedule && $daySchedule->is_working_day) {
                // Get start and end times for this day
                $startTime = Carbon::parse($daySchedule->start_time)->setDateFrom($currentDate);
                $endTime = Carbon::parse($daySchedule->end_time)->setDateFrom($currentDate);
                
                // Get existing appointments for this day
                $existingAppointments = Appointment::where('provider_type', $providerType)
                    ->where('provider_id', $provider->id)
                    ->whereDate('scheduled_date', $currentDate->toDateString())
                    ->whereIn('status', ['scheduled', 'confirmed'])
                    ->get();
                
                // Generate time slots
                $currentSlot = clone $startTime;
                while ($currentSlot->addMinutes($procedureDuration) <= $endTime) {
                    $slotStart = clone $currentSlot;
                    $slotEnd = (clone $currentSlot)->addMinutes($procedureDuration);
                    
                    // Check if slot conflicts with existing appointments
                    $isAvailable = true;
                    foreach ($existingAppointments as $appointment) {
                        $apptStart = Carbon::parse($appointment->scheduled_date);
                        $apptEnd = (clone $apptStart)->addMinutes($procedureDuration);
                        
                        // Check for overlap
                        if ($slotStart < $apptEnd && $slotEnd > $apptStart) {
                            $isAvailable = false;
                            break;
                        }
                    }
                    
                    if ($isAvailable) {
                        $availableSlots[] = [
                            'start_time' => $slotStart,
                            'end_time' => $slotEnd
                        ];
                    }
                }
            }
            
            $currentDate->addDay();
        }
        
        // Sort by date (earliest first)
        usort($availableSlots, function ($a, $b) {
            return $a['start_time']->timestamp - $b['start_time']->timestamp;
        });
        
        return $availableSlots;
    }

    /**
     * Create an appointment
     *
     * @param Solicitation $solicitation
     * @param array $providerWithSlot
     * @return Appointment|null
     */
    protected function createAppointment(Solicitation $solicitation, array $providerWithSlot)
    {
        try {
            $appointment = Appointment::create([
                'solicitation_id' => $solicitation->id,
                'provider_type' => $providerWithSlot['provider_type'],
                'provider_id' => $providerWithSlot['provider_id'],
                'status' => Appointment::STATUS_SCHEDULED,
                'scheduled_date' => $providerWithSlot['scheduled_date'],
                'notes' => 'Agendado automaticamente pelo sistema',
                'price' => $providerWithSlot['price']
            ]);
            
            return $appointment;
        } catch (\Exception $e) {
            Log::error('Failed to create appointment: ' . $e->getMessage(), [
                'solicitation_id' => $solicitation->id,
                'provider_type' => $providerWithSlot['provider_type'],
                'provider_id' => $providerWithSlot['provider_id'],
                'exception' => $e
            ]);
            
            return null;
        }
    }

    /**
     * Get price from active pricing contract
     *
     * @param Collection $pricingContracts
     * @param int $tussId
     * @return float|null
     */
    protected function getPriceFromPricingContract($pricingContracts, $tussId)
    {
        foreach ($pricingContracts as $contract) {
            if ($contract->tuss_procedure_id === $tussId && $contract->is_active) {
                return $contract->price;
            }
        }
        
        return null;
    }

    /**
     * Calculate distance between two points
     *
     * @param float $lat1
     * @param float $lon1
     * @param float $lat2
     * @param float $lon2
     * @return float Distance in kilometers
     */
    protected function calculateDistance($lat1, $lon1, $lat2, $lon2): float | null
    {
        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            return null;
        }
        
        // Convert degrees to radians
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        
        // Haversine formula
        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlon / 2) * sin($dlon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = 6371 * $c; // Earth radius in kilometers
        
        return $distance;
    }
} 