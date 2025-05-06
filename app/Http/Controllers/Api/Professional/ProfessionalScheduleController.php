<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Controller;
use App\Models\Professional;
use App\Models\ScheduleConfig;
use App\Models\ScheduleException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProfessionalScheduleController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }
    
    /**
     * Get schedule configuration for a professional.
     *
     * @param Professional $professional
     * @return JsonResponse
     */
    public function getSchedule(Professional $professional): JsonResponse
    {
        try {
            // Check permissions - must be professional themselves or admin
            if (!$this->canManageSchedule($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this schedule'
                ], 403);
            }
            
            // Get regular schedule configuration
            $scheduleConfig = $professional->scheduleConfig()->get();
            
            // Get exceptions (time off, vacations, etc.)
            $scheduleExceptions = $professional->scheduleExceptions()
                ->where('end_date', '>=', now())
                ->orderBy('start_date')
                ->get();
                
            // Get upcoming appointments
            $upcomingAppointments = $professional->appointments()
                ->where('scheduled_at', '>=', now())
                ->where('status', '!=', 'cancelled')
                ->take(10)
                ->get();
                
            return response()->json([
                'success' => true,
                'message' => 'Schedule retrieved successfully',
                'data' => [
                    'professional' => [
                        'id' => $professional->id,
                        'name' => $professional->name
                    ],
                    'schedule_config' => $scheduleConfig,
                    'exceptions' => $scheduleExceptions,
                    'upcoming_appointments' => $upcomingAppointments
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving professional schedule: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update schedule configuration for a professional.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function updateSchedule(Request $request, Professional $professional): JsonResponse
    {
        try {
            // Check permissions - must be professional themselves or admin
            if (!$this->canManageSchedule($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this schedule'
                ], 403);
            }
            
            $validator = Validator::make($request->all(), [
                'schedule' => 'required|array',
                'schedule.*.day_of_week' => 'required|integer|between:0,6',
                'schedule.*.start_time' => 'required|date_format:H:i',
                'schedule.*.end_time' => 'required|date_format:H:i|after:schedule.*.start_time',
                'schedule.*.is_available' => 'required|boolean',
                'schedule.*.location_id' => 'nullable|exists:locations,id',
                'schedule.*.break_start' => 'nullable|date_format:H:i',
                'schedule.*.break_end' => 'nullable|date_format:H:i|after:schedule.*.break_start',
                'slot_duration' => 'nullable|integer|min:5|max:240',
                'buffer_time' => 'nullable|integer|min:0|max:60',
                'max_daily_appointments' => 'nullable|integer|min:1',
                'advance_booking_days' => 'nullable|integer|min:1|max:365',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            DB::beginTransaction();
            
            // Delete existing schedule config
            $professional->scheduleConfig()->delete();
            
            // Create new schedule config
            foreach ($request->schedule as $scheduleItem) {
                $professional->scheduleConfig()->create([
                    'day_of_week' => $scheduleItem['day_of_week'],
                    'start_time' => $scheduleItem['start_time'],
                    'end_time' => $scheduleItem['end_time'],
                    'is_available' => $scheduleItem['is_available'],
                    'location_id' => $scheduleItem['location_id'] ?? null,
                    'break_start' => $scheduleItem['break_start'] ?? null,
                    'break_end' => $scheduleItem['break_end'] ?? null,
                ]);
            }
            
            // Update professional settings
            $professional->update([
                'appointment_slot_duration' => $request->slot_duration ?? 30,
                'appointment_buffer_time' => $request->buffer_time ?? 0,
                'max_daily_appointments' => $request->max_daily_appointments ?? null,
                'advance_booking_days' => $request->advance_booking_days ?? 30,
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Schedule updated successfully',
                'data' => [
                    'professional' => [
                        'id' => $professional->id,
                        'name' => $professional->name
                    ],
                    'schedule_config' => $professional->scheduleConfig()->get()
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating professional schedule: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Add a schedule exception (vacation, time off, etc.)
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function addException(Request $request, Professional $professional): JsonResponse
    {
        try {
            // Check permissions - must be professional themselves or admin
            if (!$this->canManageSchedule($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this schedule'
                ], 403);
            }
            
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'is_all_day' => 'required|boolean',
                'start_time' => 'nullable|required_if:is_all_day,false|date_format:H:i',
                'end_time' => 'nullable|required_if:is_all_day,false|date_format:H:i|after:start_time',
                'exception_type' => 'required|string|in:vacation,sick_leave,personal,other',
                'location_id' => 'nullable|exists:locations,id',
                'recurrence_pattern' => 'nullable|string|in:daily,weekly,monthly,none',
                'recurrence_end_date' => 'nullable|required_if:recurrence_pattern,daily,weekly,monthly|date|after:end_date',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Check if there are overlapping appointments
            if ($this->hasOverlappingAppointments($professional, $request->start_date, $request->end_date)) {
                return response()->json([
                    'success' => false,
                    'message' => 'There are already scheduled appointments during this period',
                    'warning' => 'Please reschedule the appointments first or choose a different period'
                ], 422);
            }
            
            // Create schedule exception
            $exception = $professional->scheduleExceptions()->create([
                'title' => $request->title,
                'description' => $request->description,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'is_all_day' => $request->is_all_day,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'exception_type' => $request->exception_type,
                'location_id' => $request->location_id,
                'recurrence_pattern' => $request->recurrence_pattern ?? 'none',
                'recurrence_end_date' => $request->recurrence_end_date,
                'created_by' => Auth::id()
            ]);
            
            // Handle recurrence if needed
            if ($request->recurrence_pattern && $request->recurrence_pattern !== 'none') {
                $this->createRecurringExceptions(
                    $professional, 
                    $exception, 
                    $request->recurrence_pattern, 
                    $request->recurrence_end_date
                );
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Schedule exception added successfully',
                'data' => [
                    'professional' => [
                        'id' => $professional->id,
                        'name' => $professional->name
                    ],
                    'exception' => $exception
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding professional schedule exception: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to add schedule exception',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Remove a schedule exception.
     *
     * @param Professional $professional
     * @param int $exceptionId
     * @return JsonResponse
     */
    public function removeException(Professional $professional, int $exceptionId): JsonResponse
    {
        try {
            // Check permissions - must be professional themselves or admin
            if (!$this->canManageSchedule($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this schedule'
                ], 403);
            }
            
            $exception = $professional->scheduleExceptions()->findOrFail($exceptionId);
            $exception->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Schedule exception removed successfully',
                'data' => [
                    'professional' => [
                        'id' => $professional->id,
                        'name' => $professional->name
                    ],
                    'exception_id' => $exceptionId
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing professional schedule exception: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove schedule exception',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get available slots for a professional on a specific date.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function getAvailableSlots(Request $request, Professional $professional): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date' => 'required|date',
                'service_id' => 'nullable|exists:services,id',
                'location_id' => 'nullable|exists:locations,id',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $date = Carbon::parse($request->date);
            $dayOfWeek = $date->dayOfWeek;
            
            // Check if professional is available on this day
            $scheduleConfig = $professional->scheduleConfig()
                ->where('day_of_week', $dayOfWeek)
                ->where('is_available', true)
                ->first();
                
            if (!$scheduleConfig) {
                return response()->json([
                    'success' => true,
                    'message' => 'No availability for this day',
                    'data' => [
                        'professional' => [
                            'id' => $professional->id,
                            'name' => $professional->name
                        ],
                        'date' => $date->format('Y-m-d'),
                        'available_slots' => []
                    ]
                ]);
            }
            
            // Check for exceptions
            $hasException = $professional->scheduleExceptions()
                ->where(function($query) use ($date) {
                    $query->whereDate('start_date', '<=', $date)
                          ->whereDate('end_date', '>=', $date);
                })
                ->whereNull('start_time') // Full day exceptions
                ->exists();
                
            if ($hasException) {
                return response()->json([
                    'success' => true,
                    'message' => 'Professional is not available on this date due to an exception',
                    'data' => [
                        'professional' => [
                            'id' => $professional->id,
                            'name' => $professional->name
                        ],
                        'date' => $date->format('Y-m-d'),
                        'available_slots' => []
                    ]
                ]);
            }
            
            // Filter by location if specified
            if ($request->has('location_id') && $scheduleConfig->location_id && $scheduleConfig->location_id != $request->location_id) {
                return response()->json([
                    'success' => true,
                    'message' => 'Professional is not available at this location on this date',
                    'data' => [
                        'professional' => [
                            'id' => $professional->id,
                            'name' => $professional->name
                        ],
                        'date' => $date->format('Y-m-d'),
                        'available_slots' => []
                    ]
                ]);
            }
            
            // Get slot duration
            $slotDuration = $professional->appointment_slot_duration ?? 30;
            $bufferTime = $professional->appointment_buffer_time ?? 0;
            
            // Get already booked appointments
            $bookedSlots = $professional->appointments()
                ->whereDate('scheduled_at', $date)
                ->where('status', '!=', 'cancelled')
                ->get()
                ->map(function($appointment) {
                    $startTime = Carbon::parse($appointment->scheduled_at);
                    $endTime = Carbon::parse($appointment->end_time);
                    return [
                        'start' => $startTime->format('H:i'),
                        'end' => $endTime->format('H:i')
                    ];
                })
                ->toArray();
                
            // Generate available slots
            $availableSlots = [];
            $startTime = Carbon::parse($scheduleConfig->start_time);
            $endTime = Carbon::parse($scheduleConfig->end_time);
            
            // Handle break time
            $breakStart = $scheduleConfig->break_start ? Carbon::parse($scheduleConfig->break_start) : null;
            $breakEnd = $scheduleConfig->break_end ? Carbon::parse($scheduleConfig->break_end) : null;
            
            while ($startTime->addMinutes($slotDuration)->lte($endTime)) {
                $slotStart = (clone $startTime)->subMinutes($slotDuration);
                $slotEnd = clone $startTime;
                
                // Skip if slot is during break
                if ($breakStart && $breakEnd) {
                    $slotStartTime = $slotStart->format('H:i');
                    $slotEndTime = $slotEnd->format('H:i');
                    $breakStartTime = $breakStart->format('H:i');
                    $breakEndTime = $breakEnd->format('H:i');
                    
                    if (
                        ($slotStartTime >= $breakStartTime && $slotStartTime < $breakEndTime) ||
                        ($slotEndTime > $breakStartTime && $slotEndTime <= $breakEndTime) ||
                        ($slotStartTime < $breakStartTime && $slotEndTime > $breakEndTime)
                    ) {
                        // Skip this slot as it overlaps with break
                        continue;
                    }
                }
                
                // Check if slot overlaps with any booked appointment
                $isBooked = false;
                foreach ($bookedSlots as $bookedSlot) {
                    $bookedStart = $bookedSlot['start'];
                    $bookedEnd = $bookedSlot['end'];
                    
                    if (
                        ($slotStart->format('H:i') >= $bookedStart && $slotStart->format('H:i') < $bookedEnd) ||
                        ($slotEnd->format('H:i') > $bookedStart && $slotEnd->format('H:i') <= $bookedEnd) ||
                        ($slotStart->format('H:i') < $bookedStart && $slotEnd->format('H:i') > $bookedEnd)
                    ) {
                        $isBooked = true;
                        break;
                    }
                }
                
                if (!$isBooked) {
                    // Add buffer time if needed
                    if ($bufferTime > 0) {
                        $bufferEnd = (clone $slotEnd)->addMinutes($bufferTime);
                        if ($bufferEnd->format('H:i') > $endTime->format('H:i')) {
                            // Skip this slot if buffer goes beyond end time
                            continue;
                        }
                    }
                    
                    $availableSlots[] = [
                        'start' => $slotStart->format('H:i'),
                        'end' => $slotEnd->format('H:i'),
                        'formatted' => $slotStart->format('g:i A') . ' - ' . $slotEnd->format('g:i A')
                    ];
                }
                
                // Add buffer time to the start time for next iteration
                if ($bufferTime > 0) {
                    $startTime->addMinutes($bufferTime);
                }
            }
            
            // Check if professional has max daily appointments limit
            if ($professional->max_daily_appointments) {
                $bookedCount = count($bookedSlots);
                $availableCount = $professional->max_daily_appointments - $bookedCount;
                
                if ($availableCount <= 0) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Professional has reached maximum number of appointments for this day',
                        'data' => [
                            'professional' => [
                                'id' => $professional->id,
                                'name' => $professional->name
                            ],
                            'date' => $date->format('Y-m-d'),
                            'available_slots' => []
                        ]
                    ]);
                }
                
                // Limit available slots to remaining count
                $availableSlots = array_slice($availableSlots, 0, $availableCount);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Available slots retrieved successfully',
                'data' => [
                    'professional' => [
                        'id' => $professional->id,
                        'name' => $professional->name
                    ],
                    'date' => $date->format('Y-m-d'),
                    'available_slots' => $availableSlots
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving available slots: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available slots',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get a professional's availability for a date range.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function getAvailabilityCalendar(Request $request, Professional $professional): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'location_id' => 'nullable|exists:locations,id',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            
            // Limit to maximum period (e.g., 3 months)
            $maxPeriod = 90; // days
            if ($startDate->diffInDays($endDate) > $maxPeriod) {
                $endDate = (clone $startDate)->addDays($maxPeriod);
            }
            
            // Get schedule configuration
            $scheduleConfig = $professional->scheduleConfig()->get()->keyBy('day_of_week');
            
            // Get all exceptions in this date range
            $exceptions = $professional->scheduleExceptions()
                ->where(function($query) use ($startDate, $endDate) {
                    $query->whereBetween('start_date', [$startDate, $endDate])
                          ->orWhereBetween('end_date', [$startDate, $endDate]);
                })
                ->get();
                
            // Get all appointments in this date range
            $appointments = $professional->appointments()
                ->whereBetween('scheduled_at', [$startDate, $endDate])
                ->where('status', '!=', 'cancelled')
                ->get();
                
            $availabilityCalendar = [];
            $currentDate = clone $startDate;
            
            while ($currentDate->lte($endDate)) {
                $dayOfWeek = $currentDate->dayOfWeek;
                $dayConfig = $scheduleConfig->get($dayOfWeek);
                
                $dayAvailability = [
                    'date' => $currentDate->format('Y-m-d'),
                    'day_of_week' => $dayOfWeek,
                    'is_available' => false,
                    'reason' => null,
                    'time_slots' => []
                ];
                
                // Check if this day is in the schedule
                if ($dayConfig && $dayConfig->is_available) {
                    // Check if there's an exception for this day
                    $hasException = $exceptions->filter(function($exception) use ($currentDate) {
                        $exceptionStart = Carbon::parse($exception->start_date);
                        $exceptionEnd = Carbon::parse($exception->end_date);
                        return $currentDate->between($exceptionStart, $exceptionEnd);
                    })->first();
                    
                    if ($hasException && $hasException->is_all_day) {
                        $dayAvailability['is_available'] = false;
                        $dayAvailability['reason'] = $hasException->title;
                    } else {
                        // Check location constraint if provided
                        if ($request->has('location_id') && $dayConfig->location_id && $dayConfig->location_id != $request->location_id) {
                            $dayAvailability['is_available'] = false;
                            $dayAvailability['reason'] = 'Not available at this location';
                        } else {
                            $dayAvailability['is_available'] = true;
                            
                            // Add time slots information
                            $dayAvailability['time_slots'] = [
                                'start_time' => $dayConfig->start_time,
                                'end_time' => $dayConfig->end_time,
                                'break_start' => $dayConfig->break_start,
                                'break_end' => $dayConfig->break_end,
                            ];
                            
                            // Add appointment count for this day
                            $appointmentCount = $appointments->filter(function($appointment) use ($currentDate) {
                                return Carbon::parse($appointment->scheduled_at)->isSameDay($currentDate);
                            })->count();
                            
                            $dayAvailability['appointment_count'] = $appointmentCount;
                            
                            // Check if max appointments reached
                            if ($professional->max_daily_appointments && $appointmentCount >= $professional->max_daily_appointments) {
                                $dayAvailability['is_available'] = false;
                                $dayAvailability['reason'] = 'Maximum appointments reached';
                            }
                        }
                    }
                } else {
                    $dayAvailability['reason'] = 'Not in regular schedule';
                }
                
                $availabilityCalendar[] = $dayAvailability;
                $currentDate->addDay();
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Availability calendar retrieved successfully',
                'data' => [
                    'professional' => [
                        'id' => $professional->id,
                        'name' => $professional->name
                    ],
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'calendar' => $availabilityCalendar
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving availability calendar: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve availability calendar',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Check if the authenticated user can manage this professional's schedule.
     *
     * @param Professional $professional
     * @return bool
     */
    private function canManageSchedule(Professional $professional): bool
    {
        $user = Auth::user();
        
        // Professional can manage their own schedule
        if ($user->entity_type === 'App\\Models\\Professional' && $user->entity_id === $professional->id) {
            return true;
        }
        
        // Admins can manage any schedule
        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return true;
        }
        
        // Clinic admins can manage their professionals' schedules
        if ($user->hasRole('clinic_admin') && $professional->clinic_id === $user->clinic_id) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if there are any appointments that overlap with the exception period.
     *
     * @param Professional $professional
     * @param string $startDate
     * @param string $endDate
     * @return bool
     */
    private function hasOverlappingAppointments(Professional $professional, string $startDate, string $endDate): bool
    {
        return $professional->appointments()
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->where('status', '!=', 'cancelled')
            ->exists();
    }
    
    /**
     * Create recurring exceptions based on pattern.
     *
     * @param Professional $professional
     * @param ScheduleException $exception
     * @param string $pattern
     * @param string $endDate
     * @return void
     */
    private function createRecurringExceptions(
        Professional $professional, 
        ScheduleException $exception, 
        string $pattern, 
        string $endDate
    ): void {
        $startDate = Carbon::parse($exception->start_date);
        $initialEndDate = Carbon::parse($exception->end_date);
        $duration = $startDate->diffInDays($initialEndDate);
        $recurrenceEndDate = Carbon::parse($endDate);
        
        $currentStart = clone $startDate;
        
        while (true) {
            switch ($pattern) {
                case 'daily':
                    $currentStart->addDay();
                    break;
                case 'weekly':
                    $currentStart->addWeek();
                    break;
                case 'monthly':
                    $currentStart->addMonth();
                    break;
                default:
                    return;
            }
            
            $currentEnd = (clone $currentStart)->addDays($duration);
            
            if ($currentStart->gt($recurrenceEndDate)) {
                break;
            }
            
            // Create new exception
            $professional->scheduleExceptions()->create([
                'title' => $exception->title,
                'description' => $exception->description,
                'start_date' => $currentStart->format('Y-m-d'),
                'end_date' => $currentEnd->format('Y-m-d'),
                'is_all_day' => $exception->is_all_day,
                'start_time' => $exception->start_time,
                'end_time' => $exception->end_time,
                'exception_type' => $exception->exception_type,
                'location_id' => $exception->location_id,
                'recurrence_pattern' => 'none', // Child exceptions don't recur
                'parent_exception_id' => $exception->id,
                'created_by' => Auth::id()
            ]);
        }
    }
} 