<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Solicitation;
use App\Models\SystemSetting;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\PricingContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Services\SchedulingConfigService;
use App\Models\Patient;
use App\Models\TussProcedure;
use Illuminate\Support\Facades\DB;
use App\Services\MapboxService;

class AppointmentScheduler
{
    const PRIORITY_COST = 'cost';
    const PRIORITY_DISTANCE = 'distance';
    const PRIORITY_AVAILABILITY = 'availability';
    const PRIORITY_BALANCED = 'balanced';

    /**
     * @var MapboxService
     */
    protected $mapboxService;

    /**
     * Maximum distance in kilometers for provider search
     */
    protected $maxDistanceKm = 50;

    /**
     * Constructor
     */
    public function __construct(MapboxService $mapboxService = null)
    {
        $this->mapboxService = $mapboxService ?? new MapboxService();
        
        // Get max distance from config or env
        $this->maxDistanceKm = env('MAX_PROVIDER_DISTANCE_KM', 50);
    }

    /**
     * Schedule an appointment for a solicitation based on current system settings.
     *
     * @param Solicitation $solicitation
     * @return Appointment|null
     */
    public function scheduleAppointment(Solicitation $solicitation): ?Appointment
    {
        try {
            // Check if scheduling is enabled
            if (!SchedulingConfigService::isAutomaticSchedulingEnabled()) {
                Log::info('Automatic scheduling is disabled. Solicitation ID: ' . $solicitation->id);
                return null;
            }

            // Get scheduling settings
            $schedulingPriority = SchedulingConfigService::getSchedulingPriority();
            $minDaysAhead = SchedulingConfigService::getMinDaysAhead();
            
            // Calculate the earliest appointment date
            $earliestDate = Carbon::today()->addDays($minDaysAhead);
            
            // Adjust if preferred date is later than the earliest allowed date
            $startDate = $solicitation->preferred_date_start;
            if ($startDate && $startDate->lt($earliestDate)) {
                $startDate = $earliestDate;
            }
            
            // Check if date range is valid
            if (!$startDate || !$solicitation->preferred_date_end || $startDate->gt($solicitation->preferred_date_end)) {
                Log::error('Invalid date range for scheduling. Solicitation ID: ' . $solicitation->id);
                return null;
            }

            // Get patient and procedure details
            $patient = Patient::findOrFail($solicitation->patient_id);
            $procedure = TussProcedure::findOrFail($solicitation->tuss_id);
            
            // Check if $procedure is a Collection and extract the first item
            if ($procedure instanceof \Illuminate\Database\Eloquent\Collection) {
                $procedure = $procedure->first();
            }

            // Check if we have patient location data
            $patientLat = $solicitation->preferred_location_lat;
            $patientLng = $solicitation->preferred_location_lng;

            // If no explicit location is provided, try to geocode patient's address
            if (!$patientLat || !$patientLng) {
                Log::info("No explicit location provided for solicitation #{$solicitation->id}, trying to geocode patient address");
                
                // Check if $patient is a Collection and extract the first item
                if ($patient instanceof \Illuminate\Database\Eloquent\Collection) {
                    $patient = $patient->first();
                }
                
                $address = $this->buildFullAddress($patient);
                if ($address) {
                    $geocodeResult = $this->mapboxService->geocodeAddress($address);
                    
                    if ($geocodeResult['success']) {
                        $patientLat = $geocodeResult['latitude'];
                        $patientLng = $geocodeResult['longitude'];
                        
                        // Save the geocoded coordinates to the solicitation for future use
                        $solicitation->update([
                            'preferred_location_lat' => $patientLat,
                            'preferred_location_lng' => $patientLng
                        ]);
                        
                        Log::info("Successfully geocoded patient address for solicitation #{$solicitation->id}");
                    } else {
                        Log::warning("Failed to geocode patient address for solicitation #{$solicitation->id}");
                    }
                }
            }

            // Get providers that offer this procedure
            $maxDistanceKm = $solicitation->max_distance_km ?: $this->maxDistanceKm;
            $provider = null;

            if ($patientLat && $patientLng) {
                // If we have location data, find the nearest and most affordable provider
                $provider = $this->findBestProvider($solicitation);
            } else {
                // Fallback: just find the most affordable provider
                $provider = $this->findMostAffordableProvider($solicitation, $procedure);
            }

            if (!$provider) {
                Log::error("No suitable provider found for solicitation #{$solicitation->id}");
                return null;
            }

            // Find a suitable date and time for the appointment
            $scheduledDate = $this->findAvailableSlot($provider, $solicitation);

            if (!$scheduledDate) {
                Log::error("No available slots found for provider " . $provider['provider_type'] . " #{$provider['provider_id']} for solicitation #{$solicitation->id}");
                return null;
            }

            // Create the appointment
            $appointment = new Appointment([
                'solicitation_id' => $solicitation->id,
                'provider_type' => $provider['provider_type'],
                'provider_id' => $provider['provider_id'],
                'scheduled_date' => $scheduledDate,
                'status' => Appointment::STATUS_SCHEDULED,
                'created_by' => $solicitation->requested_by
            ]);

            $appointment->save();
            
            // Update solicitation status
            $solicitation->status = Solicitation::STATUS_SCHEDULED;
            $solicitation->save();
            
            Log::info("Successfully scheduled appointment #{$appointment->id} for solicitation #{$solicitation->id}");
            
            return $appointment;
        } catch (\Exception $e) {
            Log::error("Error in scheduling appointment for solicitation #{$solicitation->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find candidates based on scheduling priority.
     *
     * @param Solicitation $solicitation
     * @param string $priority
     * @return array
     */
    private function findCandidates(Solicitation $solicitation, string $priority): array
    {
        $tussId = $solicitation->tuss_id;
        $candidates = [];

        // Get active providers who can perform this procedure
        $clinics = Clinic::active()
            ->whereHas('pricingContracts', function($query) use ($tussId) {
                $query->whereHas('pricingItems', function($query) use ($tussId) {
                    $query->where('tuss_id', $tussId);
                });
            })
            ->get();

        $professionals = Professional::active()
            ->whereHas('pricingContracts', function($query) use ($tussId) {
                $query->whereHas('pricingItems', function($query) use ($tussId) {
                    $query->where('tuss_id', $tussId);
                });
            })
            ->get();

        // Combine and filter providers
        $providers = $this->combineProviders($clinics, $professionals);
        
        if (empty($providers)) {
            return [];
        }

        // Sort candidates based on priority
        switch ($priority) {
            case self::PRIORITY_COST:
                $candidates = $this->rankByCost($providers, $tussId);
                break;
            case self::PRIORITY_DISTANCE:
                $candidates = $this->rankByDistance($providers, $solicitation);
                break;
            case self::PRIORITY_AVAILABILITY:
                $candidates = $this->rankByAvailability($providers, $solicitation);
                break;
            case self::PRIORITY_BALANCED:
            default:
                $candidates = $this->rankBalanced($providers, $solicitation, $tussId);
                break;
        }

        return $candidates;
    }

    /**
     * Combine providers from different sources.
     *
     * @param \Illuminate\Database\Eloquent\Collection $clinics
     * @param \Illuminate\Database\Eloquent\Collection $professionals
     * @return array
     */
    private function combineProviders($clinics, $professionals): array
    {
        $providers = [];
        
        foreach ($clinics as $clinic) {
            $providers[] = $clinic;
        }
        
        foreach ($professionals as $professional) {
            $providers[] = $professional;
        }
        
        return $providers;
    }

    /**
     * Rank providers by cost.
     *
     * @param array $providers
     * @param int $tussId
     * @return array
     */
    private function rankByCost(array $providers, int $tussId): array
    {
        // Sort providers by price for the procedure
        usort($providers, function ($a, $b) use ($tussId) {
            $priceA = $this->getPriceForTuss($a, $tussId) ?? PHP_INT_MAX;
            $priceB = $this->getPriceForTuss($b, $tussId) ?? PHP_INT_MAX;
            return $priceA <=> $priceB;
        });
        
        return $providers;
    }

    /**
     * Get the price for a specific TUSS procedure.
     *
     * @param mixed $provider
     * @param int $tussId
     * @return float|null
     */
    private function getPriceForTuss($provider, int $tussId): ?float
    {
        $contract = $provider->pricingContracts()->active()->first();
        
        if (!$contract) {
            return null;
        }
        
        $pricingItem = $contract->pricingItems()->where('tuss_id', $tussId)->first();
        
        return $pricingItem ? $pricingItem->price : null;
    }

    /**
     * Rank providers by distance.
     *
     * @param array $providers
     * @param Solicitation $solicitation
     * @return array
     */
    private function rankByDistance(array $providers, Solicitation $solicitation): array
    {
        // If no location preference is set, return providers as is
        if (!$solicitation->preferred_location_lat || !$solicitation->preferred_location_lng) {
            return $providers;
        }
        
        $lat = $solicitation->preferred_location_lat;
        $lng = $solicitation->preferred_location_lng;
        $maxDistance = $solicitation->max_distance_km ?? 50;
        
        // Calculate distance for each provider
        $providersWithDistance = [];
        
        foreach ($providers as $provider) {
            if (!$provider->latitude || !$provider->longitude) {
                continue;
            }
            
            $distance = $this->calculateDistance(
                $lat, 
                $lng, 
                $provider->latitude, 
                $provider->longitude
            );
            
            if ($distance <= $maxDistance) {
                $provider->distance = $distance;
                $providersWithDistance[] = $provider;
            }
        }
        
        // Sort by distance
        usort($providersWithDistance, function ($a, $b) {
            return $a->distance <=> $b->distance;
        });
        
        return $providersWithDistance;
    }

    /**
     * Calculate the distance between two points using Haversine formula.
     *
     * @param float $lat1
     * @param float $lon1
     * @param float $lat2
     * @param float $lon2
     * @return float Distance in kilometers
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Radius of the earth in km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = $earthRadius * $c;
        
        return $distance;
    }

    /**
     * Rank providers by availability.
     *
     * @param array $providers
     * @param Solicitation $solicitation
     * @return array
     */
    private function rankByAvailability(array $providers, Solicitation $solicitation): array
    {
        $startDate = $solicitation->preferred_date_start;
        $endDate = $solicitation->preferred_date_end;
        
        // Count existing appointments for each provider in the date range
        $providersWithLoad = [];
        
        foreach ($providers as $provider) {
            $providerType = get_class($provider);
            
            $appointmentCount = Appointment::where('provider_type', $providerType)
                ->where('provider_id', $provider->id)
                ->whereBetween('scheduled_date', [$startDate, $endDate])
                ->where('status', '!=', Appointment::STATUS_CANCELLED)
                ->count();
            
            $provider->appointment_load = $appointmentCount;
            $providersWithLoad[] = $provider;
        }
        
        // Sort by appointment load (ascending)
        usort($providersWithLoad, function ($a, $b) {
            return $a->appointment_load <=> $b->appointment_load;
        });
        
        return $providersWithLoad;
    }

    /**
     * Rank providers using a balanced approach.
     *
     * @param array $providers
     * @param Solicitation $solicitation
     * @param int $tussId
     * @return array
     */
    private function rankBalanced(array $providers, Solicitation $solicitation, int $tussId): array
    {
        $providers = $this->rankByDistance($providers, $solicitation);
        
        if (empty($providers)) {
            return [];
        }
        
        // Calculate a score based on multiple factors
        foreach ($providers as $provider) {
            $price = $this->getPriceForTuss($provider, $tussId) ?? PHP_INT_MAX;
            $distance = $provider->distance ?? PHP_INT_MAX;
            
            // Normalize price (assume price between 0 and 10000)
            $normalizedPrice = min(1, max(0, 1 - $price / 10000));
            
            // Normalize distance (assume max distance of 50km)
            $maxDistance = $solicitation->max_distance_km ?? 50;
            $normalizedDistance = min(1, max(0, 1 - $distance / $maxDistance));
            
            // Calculate appointment load score
            $providerType = get_class($provider);
            $appointmentCount = Appointment::where('provider_type', $providerType)
                ->where('provider_id', $provider->id)
                ->whereBetween('scheduled_date', [$solicitation->preferred_date_start, $solicitation->preferred_date_end])
                ->where('status', '!=', Appointment::STATUS_CANCELLED)
                ->count();
            
            // Normalize load (assume max 50 appointments in period)
            $normalizedLoad = min(1, max(0, 1 - $appointmentCount / 50));
            
            // Calculate final score (weighted average)
            $provider->score = (0.4 * $normalizedPrice) + (0.4 * $normalizedDistance) + (0.2 * $normalizedLoad);
        }
        
        // Sort by score (descending)
        usort($providers, function ($a, $b) {
            return $b->score <=> $a->score;
        });
        
        return $providers;
    }

    /**
     * Determine the best appointment date.
     *
     * @param mixed $provider
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Carbon|null
     */
    private function determineAppointmentDate($provider, Carbon $startDate, Carbon $endDate): ?Carbon
    {
        $providerType = get_class($provider);
        
        // Get existing appointments for this provider in the date range
        $existingAppointments = Appointment::where('provider_type', $providerType)
            ->where('provider_id', $provider->id)
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->orderBy('scheduled_date')
            ->get();
        
        // Find suitable slot (simple approach - find first day with fewer than 5 appointments)
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            $dayAppointmentsCount = $existingAppointments->filter(function ($appointment) use ($currentDate) {
                return $appointment->scheduled_date->isSameDay($currentDate);
            })->count();
            
            if ($dayAppointmentsCount < 5) {
                // Find a time slot (9am-5pm, hourly)
                for ($hour = 9; $hour < 17; $hour++) {
                    $proposedTime = $currentDate->copy()->setHour($hour)->setMinute(0)->setSecond(0);
                    
                    $timeConflict = $existingAppointments->contains(function ($appointment) use ($proposedTime) {
                        return $appointment->scheduled_date->setSecond(0)->setMinute(0)->eq($proposedTime);
                    });
                    
                    if (!$timeConflict) {
                        return $proposedTime;
                    }
                }
            }
            
            $currentDate->addDay();
        }
        
        return null;
    }

    /**
     * Find the best provider for a solicitation based on TUSS specialty and cost.
     *
     * @param Solicitation $solicitation
     * @return array
     */
    public function findBestProvider(Solicitation $solicitation): array
    {
        try {
            $tussId = $solicitation->tuss_id;

            // Get active providers who can perform this procedure
            $providers = [];

            // Get clinics
            $clinics = Clinic::active()
                ->whereHas('pricingContracts', function($query) use ($tussId) {
                    $query->whereHas('pricingItems', function($query) use ($tussId) {
                        $query->where('tuss_id', $tussId);
                    });
                })
                ->get();

            foreach ($clinics as $clinic) {
                $price = $this->getPriceForTuss($clinic, $tussId);
                if ($price !== null) {
                    $providers[] = [
                        'provider_type' => Clinic::class,
                        'provider_id' => $clinic->id,
                        'name' => $clinic->name,
                        'price' => $price
                    ];
                }
            }

            // Get professionals
            $professionals = Professional::active()
                ->whereHas('pricingContracts', function($query) use ($tussId) {
                    $query->whereHas('pricingItems', function($query) use ($tussId) {
                        $query->where('tuss_id', $tussId);
                    });
                })
                ->get();

            foreach ($professionals as $professional) {
                $price = $this->getPriceForTuss($professional, $tussId);
                if ($price !== null) {
                    $providers[] = [
                        'provider_type' => Professional::class,
                        'provider_id' => $professional->id,
                        'name' => $professional->name,
                        'price' => $price
                    ];
                }
            }

            if (empty($providers)) {
                return [
                    'success' => false,
                    'message' => 'No providers found for this specialty'
                ];
            }

            // Sort providers by price
            usort($providers, function($a, $b) {
                return $a['price'] <=> $b['price'];
            });

            // Return the provider with lowest price
            return [
                'success' => true,
                'provider' => $providers[0]
            ];

        } catch (\Exception $e) {
            Log::error("Error finding best provider for solicitation #{$solicitation->id}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error finding provider: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Find the most affordable provider for the procedure
     *
     * @param Solicitation $solicitation
     * @param TussProcedure $procedure
     * @return array|null Provider info or null if none found
     */
    protected function findMostAffordableProvider(Solicitation $solicitation, TussProcedure $procedure): ?array
    {
        // Get all clinics and professionals that offer this procedure
        $clinicProviders = $this->getClinicsForProcedure($solicitation, $procedure);
        $professionalProviders = $this->getProfessionalsForProcedure($solicitation, $procedure);

        // Combine all providers
        $allProviders = array_merge($clinicProviders, $professionalProviders);

        if (empty($allProviders)) {
            return null;
        }

        // Sort by price (lowest first)
        usort($allProviders, function ($a, $b) {
            return $a['price'] <=> $b['price'];
        });

        // Return the most affordable provider
        return $allProviders[0];
    }

    /**
     * Get clinics that offer the specified procedure
     *
     * @param Solicitation $solicitation
     * @param TussProcedure $procedure
     * @return array Formatted clinic providers with location and price
     */
    protected function getClinicsForProcedure(Solicitation $solicitation, TussProcedure $procedure): array
    {
        // Get clinics that offer this procedure for this health plan
        $clinics = DB::table('clinics')
            ->join('clinic_procedures', 'clinics.id', '=', 'clinic_procedures.clinic_id')
            ->join('health_plan_clinic_procedures', function ($join) use ($solicitation) {
                $join->on('clinic_procedures.id', '=', 'health_plan_clinic_procedures.clinic_procedure_id')
                    ->where('health_plan_clinic_procedures.health_plan_id', '=', $solicitation->health_plan_id);
            })
            ->where('clinic_procedures.tuss_procedure_id', '=', $procedure->id)
            ->where('clinics.status', '=', 'approved')
            ->select(
                'clinics.id',
                'clinics.name',
                'clinics.address',
                'clinics.city',
                'clinics.state',
                'clinics.postal_code',
                'clinics.latitude',
                'clinics.longitude',
                'health_plan_clinic_procedures.price'
            )
            ->get();

        return $clinics->map(function ($clinic) {
            return [
                'provider_type' => Clinic::class,
                'provider_id' => $clinic->id,
                'name' => $clinic->name,
                'address' => $clinic->address,
                'city' => $clinic->city,
                'state' => $clinic->state,
                'postal_code' => $clinic->postal_code,
                'latitude' => $clinic->latitude,
                'longitude' => $clinic->longitude,
                'price' => $clinic->price,
            ];
        })->toArray();
    }

    /**
     * Get professionals that offer the specified procedure
     *
     * @param Solicitation $solicitation
     * @param TussProcedure $procedure
     * @return array Formatted professional providers with location and price
     */
    protected function getProfessionalsForProcedure(Solicitation $solicitation, TussProcedure $procedure): array
    {
        // Get professionals that offer this procedure for this health plan
        $professionals = DB::table('professionals')
            ->join('professional_procedures', 'professionals.id', '=', 'professional_procedures.professional_id')
            ->join('health_plan_professional_procedures', function ($join) use ($solicitation) {
                $join->on('professional_procedures.id', '=', 'health_plan_professional_procedures.professional_procedure_id')
                    ->where('health_plan_professional_procedures.health_plan_id', '=', $solicitation->health_plan_id);
            })
            ->where('professional_procedures.tuss_procedure_id', '=', $procedure->id)
            ->where('professionals.status', '=', 'approved')
            ->select(
                'professionals.id',
                'professionals.name',
                'professionals.address',
                'professionals.city',
                'professionals.state',
                'professionals.postal_code',
                'professionals.latitude',
                'professionals.longitude',
                'health_plan_professional_procedures.price'
            )
            ->get();

        return $professionals->map(function ($professional) {
            return [
                'provider_type' => Professional::class,
                'provider_id' => $professional->id,
                'name' => $professional->name,
                'address' => $professional->address,
                'city' => $professional->city,
                'state' => $professional->state,
                'postal_code' => $professional->postal_code,
                'latitude' => $professional->latitude,
                'longitude' => $professional->longitude,
                'price' => $professional->price,
            ];
        })->toArray();
    }

    /**
     * Find an available appointment slot for the provider
     *
     * @param array $provider
     * @param Solicitation $solicitation
     * @return Carbon|null The scheduled date or null if no slots available
     */
    protected function findAvailableSlot(array $provider, Solicitation $solicitation): ?Carbon
    {
        // Start looking from the preferred start date
        $startDate = Carbon::parse($solicitation->preferred_date_start);
        $endDate = Carbon::parse($solicitation->preferred_date_end);
        
        // Make sure we start with a future date
        $startDate = max($startDate, Carbon::now()->addHours(1));
        
        // Check if preferred end date is after start date
        if ($endDate->lt($startDate)) {
            $endDate = $startDate->copy()->addDays(7);
        }
        
        $providerType = $provider['provider_type'];
        $providerId = $provider['provider_id'];
        
        // Get the schedule for this provider
        $schedule = $this->getProviderSchedule($providerType, $providerId);
        
        if (empty($schedule)) {
            // If no specific schedule, use default business hours
            $schedule = $this->getDefaultSchedule();
        }
        
        // Get existing appointments for this provider within the date range
        $existingAppointments = Appointment::where('provider_type', $providerType)
            ->where('provider_id', $providerId)
            ->whereBetween('scheduled_date', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED])
            ->orderBy('scheduled_date')
            ->get();
        
        // Format appointments as timestamp => duration pairs
        $bookedSlots = [];
        foreach ($existingAppointments as $appointment) {
            $appointmentStart = Carbon::parse($appointment->scheduled_date);
            // Assume 1-hour appointments by default
            $duration = $this->getAppointmentDuration($appointment->solicitation->tuss_id);
            $bookedSlots[$appointmentStart->timestamp] = $duration;
        }
        
        // Find available slot
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            // Skip if not a business day
            $dayOfWeek = $currentDate->dayOfWeek;
            if (!isset($schedule[$dayOfWeek])) {
                $currentDate->addDay();
                continue;
            }
            
            // Check each time slot for this day
            foreach ($schedule[$dayOfWeek] as $timeSlot) {
                $slotStart = $currentDate->copy()->setTime(
                    (int)substr($timeSlot['start'], 0, 2),
                    (int)substr($timeSlot['start'], 3, 2)
                );
                
                $slotEnd = $currentDate->copy()->setTime(
                    (int)substr($timeSlot['end'], 0, 2),
                    (int)substr($timeSlot['end'], 3, 2)
                );
                
                // Skip if slot is in the past
                if ($slotStart->lt(Carbon::now())) {
                    continue;
                }
                
                // Get duration for this procedure
                $duration = $this->getAppointmentDuration($solicitation->tuss_id);
                
                // Check if this slot is available
                $isAvailable = true;
                $testTime = $slotStart->copy();
                
                while ($testTime->lt($slotEnd) && $testTime->copy()->addMinutes($duration)->lte($slotEnd)) {
                    $isBooked = false;
                    
                    foreach ($bookedSlots as $bookedStart => $bookedDuration) {
                        $bookedEnd = Carbon::createFromTimestamp($bookedStart)->addMinutes($bookedDuration);
                        $testEnd = $testTime->copy()->addMinutes($duration);
                        
                        // Check if there's an overlap
                        if (($testTime->between(Carbon::createFromTimestamp($bookedStart), $bookedEnd)) ||
                            ($testEnd->between(Carbon::createFromTimestamp($bookedStart), $bookedEnd)) ||
                            (Carbon::createFromTimestamp($bookedStart)->between($testTime, $testEnd)) ||
                            ($bookedEnd->between($testTime, $testEnd))) {
                            $isBooked = true;
                            break;
                        }
                    }
                    
                    if (!$isBooked) {
                        // Found an available slot
                        return $testTime;
                    }
                    
                    // Move to next possible slot (30 min increments)
                    $testTime->addMinutes(30);
                }
            }
            
            // Move to next day
            $currentDate->addDay();
        }
        
        // No available slots found
        return null;
    }
    
    /**
     * Get the schedule for a provider
     *
     * @param string $providerType
     * @param int $providerId
     * @return array Schedule by day of week
     */
    protected function getProviderSchedule(string $providerType, int $providerId): array
    {
        // This would typically come from a database table
        // Example structure:
        // [
        //   1 => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']], // Monday
        //   2 => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']], // Tuesday
        //   ...
        // ]
        
        // For now, return an empty array which will fallback to default schedule
        return [];
    }
    
    /**
     * Get default business hours schedule
     *
     * @return array Schedule by day of week
     */
    protected function getDefaultSchedule(): array
    {
        return [
            1 => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']], // Monday
            2 => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']], // Tuesday
            3 => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']], // Wednesday
            4 => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']], // Thursday
            5 => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']], // Friday
            6 => [['start' => '09:00', 'end' => '12:00']], // Saturday
            // 0 is Sunday - no slots by default
        ];
    }
    
    /**
     * Get appointment duration for a procedure in minutes
     *
     * @param int $tussId
     * @return int Duration in minutes
     */
    protected function getAppointmentDuration(int $tussId): int
    {
        // This could be pulled from a procedure-specific duration table
        // For now, use a default of 60 minutes
        return 60;
    }
    
    /**
     * Build a full address string from patient data
     *
     * @param Patient $patient
     * @return string|null
     */
    protected function buildFullAddress(Patient $patient): ?string
    {
        if (!$patient->address) {
            return null;
        }
        
        $address = $patient->address;
        
        if ($patient->city) {
            $address .= ", {$patient->city}";
        }
        
        if ($patient->state) {
            $address .= ", {$patient->state}";
        }
        
        if ($patient->postal_code) {
            $address .= " - {$patient->postal_code}";
        }
        
        return $address;
    }
} 