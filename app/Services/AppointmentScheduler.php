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
use App\Models\User;
use App\Notifications\SchedulingFailed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Database\Eloquent\Collection;
use App\Services\NotificationService;

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
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Maximum distance in kilometers for provider search
     */
    protected $maxDistanceKm = 50;

    /**
     * Constructor
     */
    public function __construct(MapboxService $mapboxService = null, NotificationService $notificationService = null)
    {
        $this->mapboxService = $mapboxService ?? new MapboxService();
        $this->notificationService = $notificationService ?? new NotificationService();
        
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
        Log::info("Starting automatic scheduling for solicitation #{$solicitation->id}", [
            'tuss_id' => $solicitation->tuss_id,
            'health_plan_id' => $solicitation->health_plan_id,
            'preferred_date_start' => $solicitation->preferred_date_start,
            'preferred_date_end' => $solicitation->preferred_date_end
        ]);

        try {
            // Check if scheduling is enabled
            if (!SchedulingConfigService::isAutomaticSchedulingEnabled()) {
                Log::info('Automatic scheduling is disabled. Solicitation ID: ' . $solicitation->id);
                $this->notifySchedulingFailed($solicitation, 'O agendamento automático está desabilitado no sistema.');
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

            Log::info("Patient details", [
                'patient_id' => $patient->id,
                'address' => $patient->address,
                'city' => $patient->city,
                'state' => $patient->state,
                'postal_code' => $patient->postal_code
            ]);

            // Get patient location
            $patientLat = $solicitation->preferred_location_lat;
            $patientLng = $solicitation->preferred_location_lng;

            Log::info("Initial patient location", [
                'lat' => $patientLat,
                'lng' => $patientLng,
                'has_location' => !is_null($patientLat) && !is_null($patientLng)
            ]);

            // If no explicit location is provided, try to geocode patient's address
            if (!$patientLat || !$patientLng) {
                Log::info("No explicit location provided for solicitation #{$solicitation->id}, trying to geocode patient address");
                
                // Check if $patient is a Collection and extract the first item
                if ($patient instanceof \Illuminate\Database\Eloquent\Collection) {
                    $patient = $patient->first();
                }
                
                $address = $this->buildFullAddress($patient);
                
                Log::info("Built full address for geocoding", [
                    'address' => $address,
                    'has_address' => !empty($address)
                ]);

                if ($address) {
                    $geocodeResult = $this->mapboxService->geocodeAddress($address);
                    
                    Log::info("Geocoding result", [
                        'success' => $geocodeResult['success'] ?? false,
                        'latitude' => $geocodeResult['latitude'] ?? null,
                        'longitude' => $geocodeResult['longitude'] ?? null,
                        'error' => $geocodeResult['error'] ?? null
                    ]);
                    
                    if ($geocodeResult['success']) {
                        $patientLat = $geocodeResult['latitude'];
                        $patientLng = $geocodeResult['longitude'];
                        
                        // Save the geocoded coordinates to the solicitation for future use
                        $solicitation->update([
                            'preferred_location_lat' => $patientLat,
                            'preferred_location_lng' => $patientLng
                        ]);
                        
                        Log::info("Successfully geocoded and saved patient location", [
                            'lat' => $patientLat,
                            'lng' => $patientLng
                        ]);
                    } else {
                        Log::warning("Failed to geocode patient address", [
                            'address' => $address,
                            'error' => $geocodeResult['error'] ?? 'Unknown error'
                        ]);
                    }
                } else {
                    Log::warning("Could not build full address for patient", [
                        'patient_id' => $patient->id,
                        'has_address' => !empty($patient->address),
                        'has_city' => !empty($patient->city),
                        'has_state' => !empty($patient->state)
                    ]);
                }
            }

            Log::info("Final patient location for scheduling", [
                'lat' => $patientLat,
                'lng' => $patientLng,
                'has_location' => !is_null($patientLat) && !is_null($patientLng)
            ]);

            // Get providers that offer this procedure
            $maxDistanceKm = $solicitation->max_distance_km ?: $this->maxDistanceKm;
            $provider = null;

            if ($patientLat && $patientLng) {
                // If we have location data, find the nearest and most affordable provider
                $result = $this->findBestProvider($solicitation);
                if ($result['success']) {
                    $provider = $result['data'][0] ?? null;
                    Log::info("Found provider with location data", [
                        'provider_type' => $provider['provider_type'] ?? null,
                        'provider_id' => $provider['provider_id'] ?? null,
                        'price' => $provider['price'] ?? null,
                        'distance' => $provider['distance'] ?? null
                    ]);
                }
            } else {
                // Fallback: just find the most affordable provider
                $provider = $this->findMostAffordableProvider($solicitation, $procedure);
                Log::info("Found provider without location data", [
                    'provider_type' => $provider['provider_type'] ?? null,
                    'provider_id' => $provider['provider_id'] ?? null,
                    'price' => $provider['price'] ?? null
                ]);
            }

            if (!$provider) {
                Log::error("No suitable provider found for solicitation #{$solicitation->id}");
                $this->notifyNoProvidersFound($solicitation);
                return null;
            }

            // Find a suitable date and time for the appointment
            $scheduledDate = $this->findAvailableSlot($provider, $solicitation);

            if (!$scheduledDate) {
                Log::error("No available slots found for provider " . $provider['provider_type'] . " #{$provider['provider_id']} for solicitation #{$solicitation->id}");
                $this->notifySchedulingFailed($solicitation, 'Não foi encontrado nenhum horário disponível com o prestador selecionado.');
                return null;
            }

            Log::info("Found available slot", [
                'provider_type' => $provider['provider_type'],
                'provider_id' => $provider['provider_id'],
                'scheduled_date' => $scheduledDate
            ]);

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
            
            Log::info("Appointment created successfully", [
                'appointment_id' => $appointment->id,
                'provider_type' => $provider['provider_type'],
                'provider_id' => $provider['provider_id'],
                'scheduled_date' => $scheduledDate
            ]);
            
            // Update solicitation status
            $solicitation->status = Solicitation::STATUS_SCHEDULED;
            $solicitation->save();
            
            Log::info("Solicitation status updated to scheduled", [
                'solicitation_id' => $solicitation->id
            ]);
            
            return $appointment;
        } catch (\Exception $e) {
            Log::error("Error in scheduling appointment for solicitation #{$solicitation->id}: " . $e->getMessage());
            $this->notifySchedulingFailed($solicitation, 'Ocorreu um erro durante o processo de agendamento: ' . $e->getMessage());
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
                    $query->where('tuss_procedure_id', $tussId);
                });
            })
            ->get();

        $professionals = Professional::active()
            ->whereHas('pricingContracts', function($query) use ($tussId) {
                $query->whereHas('pricingItems', function($query) use ($tussId) {
                    $query->where('tuss_procedure_id', $tussId);
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
        $contract = $provider->pricingContracts()
            ->where('tuss_procedure_id', $tussId)
            ->where('is_active', true)
            ->first();
        
        return $contract ? $contract->price : null;
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
            $providers = [];

            Log::info("Starting provider search for solicitation #{$solicitation->id}", [
                'tuss_id' => $tussId
            ]);

            // Get all active pricing contracts for this TUSS
            $pricingContracts = PricingContract::where('tuss_procedure_id', $tussId)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('end_date')
                      ->orWhere('end_date', '>=', now());
                })
                ->get();

            Log::info("Found pricing contracts", [
                'tuss_id' => $tussId,
                'total_contracts' => $pricingContracts->count()
            ]);

            if ($pricingContracts->isEmpty()) {
                Log::warning("No active pricing contracts found for TUSS #{$tussId}");
                return [
                    'success' => false,
                    'message' => 'No active pricing contracts found',
                    'data' => []
                ];
            }

            foreach ($pricingContracts as $contract) {
                // Get the contractable (clinic or professional)
                $contractable = $contract->contractable;
                
                if (!$contractable || !$contractable->is_active || $contractable->status !== 'approved') {
                    Log::info("Skipping inactive contractable", [
                        'contract_id' => $contract->id,
                        'contractable_type' => $contract->contractable_type,
                        'contractable_id' => $contract->contractable_id,
                        'is_active' => $contractable ? $contractable->is_active : false,
                        'status' => $contractable ? $contractable->status : null
                    ]);
                    continue;
                }

                // Calculate distance if coordinates available
                $distance = null;
                if ($solicitation->preferred_location_lat && $solicitation->preferred_location_lng && 
                    $contractable->latitude && $contractable->longitude) {
                    $distance = $this->calculateDistance(
                        $solicitation->preferred_location_lat,
                        $solicitation->preferred_location_lng,
                        $contractable->latitude,
                        $contractable->longitude
                    );
                }

                $providers[] = [
                    'provider_type' => $contract->contractable_type,
                    'provider_id' => $contract->contractable_id,
                    'price' => $contract->price,
                    'distance' => $distance
                ];
            }

            if (empty($providers)) {
                Log::warning("No suitable providers found for solicitation #{$solicitation->id}");
                return [
                    'success' => false,
                    'message' => 'No suitable providers found',
                    'data' => []
                ];
            }

            // Sort providers by price and distance
            usort($providers, function($a, $b) {
                // First compare by price
                $priceCompare = $a['price'] <=> $b['price'];
                if ($priceCompare !== 0) {
                    return $priceCompare;
                }
                
                // If prices are equal, compare by distance
                if ($a['distance'] === null && $b['distance'] === null) {
                    return 0;
                }
                if ($a['distance'] === null) {
                    return 1;
                }
                if ($b['distance'] === null) {
                    return -1;
                }
                return $a['distance'] <=> $b['distance'];
            });

            return [
                'success' => true,
                'message' => 'Providers found successfully',
                'data' => $providers
            ];

        } catch (\Exception $e) {
            Log::error("Error finding providers for solicitation #{$solicitation->id}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error finding providers: ' . $e->getMessage(),
                'data' => []
            ];
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
     * Notify relevant users when no providers are found for a solicitation.
     *
     * @param Solicitation $solicitation
     * @return void
     */
    protected function notifyNoProvidersFound(Solicitation $solicitation): void
    {
        try {
            // Build location message
            $locationMsg = '';
            if ($solicitation->state) {
                $locationMsg = " no estado {$solicitation->state}";
                if ($solicitation->city) {
                    $locationMsg .= " e cidade {$solicitation->city}";
                }
            }

            // Get users to notify
            $users = User::whereHas('roles', function($query) {
                $query->whereIn('name', ['super_admin', 'network_manager']);
            })->get();

            // Add the user who requested the reprocessing if they are not a health plan
            if ($solicitation->reprocessed_by) {
                $requestingUser = User::find($solicitation->reprocessed_by);
                if ($requestingUser && !$requestingUser->hasRole('plan_admin')) {
                    $users->push($requestingUser);
                }
            }

            // Send notification
            Notification::send($users, new SchedulingFailed(
                $solicitation,
                "Não foram encontrados prestadores disponíveis{$locationMsg} para esta especialidade/procedimento."
            ));

            // Log the event
            Log::warning(
                "No providers found for solicitation #{$solicitation->id}" .
                ($locationMsg ? " (filtering by location:{$locationMsg})" : '')
            );
        } catch (\Exception $e) {
            Log::error("Error sending no providers notification: " . $e->getMessage());
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
            return [
                'success' => false,
                'message' => 'No providers found for this procedure'
            ];
        }

        // Sort by price (lowest first)
        usort($allProviders, function ($a, $b) {
            return $a['price'] <=> $b['price'];
        });

        // Return the most affordable provider
        return [
            'success' => true,
            'data' => $allProviders[0]
        ];
    }

    /**
     * Get clinics that offer the specified procedure
     *
     * @param Solicitation $solicitation
     * @param Tuss|TussProcedure $procedure
     * @return array Formatted clinic providers with location and price
     */
    protected function getClinicsForProcedure(Solicitation $solicitation, $procedure): array
    {
        $query = Clinic::active()
            ->whereHas('pricingContracts', function($query) use ($procedure) {
                $query->whereHas('pricingItems', function($query) use ($procedure) {
                    $query->where('tuss_procedure_id', $procedure->id);
                });
            });

        // Filter by state and city if provided
        if ($solicitation->state) {
            $query->where('state', $solicitation->state);
            if ($solicitation->city) {
                $query->where('city', $solicitation->city);
            }
        }

        return $query->get();
    }

    /**
     * Get professionals that offer the specified procedure
     *
     * @param Solicitation $solicitation
     * @param Tuss|TussProcedure $procedure
     * @return array Formatted professional providers with location and price
     */
    protected function getProfessionalsForProcedure(Solicitation $solicitation, $procedure): array
    {
        $query = Professional::active()
            ->whereHas('pricingContracts', function($query) use ($procedure) {
                $query->whereHas('pricingItems', function($query) use ($procedure) {
                    $query->where('tuss_procedure_id', $procedure->id);
                });
            });

        // Filter by state and city if provided
        if ($solicitation->state) {
            $query->where('state', $solicitation->state);
            if ($solicitation->city) {
                $query->where('city', $solicitation->city);
            }
        }

        return $query->get();
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
        Log::info("Starting slot search for provider", [
            'provider_type' => $provider['provider_type'],
            'provider_id' => $provider['provider_id'],
            'solicitation_id' => $solicitation->id
        ]);

        // Start looking from the preferred start date
        $startDate = Carbon::parse($solicitation->preferred_date_start);
        $endDate = Carbon::parse($solicitation->preferred_date_end);
        
        Log::info("Date range for slot search", [
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        // Make sure we start with a future date
        $startDate = max($startDate, Carbon::now()->addHours(1));
        
        // Check if preferred end date is after start date
        if ($endDate->lt($startDate)) {
            $endDate = $startDate->copy()->addDays(7);
            Log::info("Adjusted end date", [
                'new_end_date' => $endDate
            ]);
        }
        
        $providerType = $provider['provider_type'];
        $providerId = $provider['provider_id'];
        
        // Get the schedule for this provider
        $schedule = $this->getProviderSchedule($providerType, $providerId);
        
        if (empty($schedule)) {
            // If no specific schedule, use default business hours
            $schedule = $this->getDefaultSchedule();
            Log::info("Using default schedule", [
                'schedule' => $schedule
            ]);
        } else {
            Log::info("Using provider specific schedule", [
                'schedule' => $schedule
            ]);
        }
        
        // Get existing appointments for this provider within the date range
        $existingAppointments = Appointment::where('provider_type', $providerType)
            ->where('provider_id', $providerId)
            ->whereBetween('scheduled_date', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED])
            ->orderBy('scheduled_date')
            ->get();

        Log::info("Found existing appointments", [
            'count' => $existingAppointments->count(),
            'date_range' => [
                'start' => $startDate->startOfDay(),
                'end' => $endDate->endOfDay()
            ]
        ]);
        
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
                Log::info("Skipping non-business day", [
                    'date' => $currentDate->toDateString(),
                    'day_of_week' => $dayOfWeek
                ]);
                $currentDate->addDay();
                continue;
            }
            
            Log::info("Checking slots for day", [
                'date' => $currentDate->toDateString(),
                'day_of_week' => $dayOfWeek,
                'time_slots' => $schedule[$dayOfWeek]
            ]);
            
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
                
                Log::info("Checking time slot", [
                    'slot_start' => $slotStart,
                    'slot_end' => $slotEnd
                ]);
                
                // Skip if slot is in the past
                if ($slotStart->lt(Carbon::now())) {
                    Log::info("Skipping past slot", [
                        'slot_start' => $slotStart
                    ]);
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
                            Log::info("Slot is booked", [
                                'test_time' => $testTime,
                                'test_end' => $testEnd,
                                'booked_start' => Carbon::createFromTimestamp($bookedStart),
                                'booked_end' => $bookedEnd
                            ]);
                            break;
                        }
                    }
                    
                    if (!$isBooked) {
                        Log::info("Found available slot", [
                            'slot_time' => $testTime
                        ]);
                        return $testTime;
                    }
                    
                    // Move to next possible slot (30 min increments)
                    $testTime->addMinutes(30);
                }
            }
            
            // Move to next day
            $currentDate->addDay();
        }
        
        Log::info("No available slots found in date range");
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

    /**
     * Send notification about scheduling failure
     *
     * @param Solicitation $solicitation
     * @param string $reason
     * @return void
     */
    protected function notifySchedulingFailed(Solicitation $solicitation, string $reason): void
    {
        try {
            // Get users to notify (network managers and admins)
            $users = User::role(['network_manager', 'super_admin'])
                ->get();

            if ($users->isNotEmpty()) {
                Notification::send($users, new SchedulingFailed($solicitation, $reason));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send scheduling failed notification: " . $e->getMessage());
        }
    }
} 