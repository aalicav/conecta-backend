<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Professional;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SuriChatbotController extends Controller
{
    protected $notificationService;
    
    public function __construct(NotificationService $notificationService = null)
    {
        $this->middleware('auth:sanctum')->except(['webhook']);
        $this->notificationService = $notificationService ?? app(NotificationService::class);
    }
    
    /**
     * Handle webhook from SURI chatbot
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            // Validate webhook token for security
            $token = $request->header('X-Suri-Token');
            if (!$token || $token !== config('services.suri.webhook_token')) {
                Log::warning('Invalid webhook access attempt', [
                    'ip' => $request->ip(),
                    'token' => $token
                ]);
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $validator = Validator::make($request->all(), [
                'message_id' => 'required|string',
                'user_id' => 'required|string',
                'message_content' => 'required|string',
                'intent' => 'required|string',
                'session_id' => 'required|string',
                'parameters' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Process the intent
            $intent = $request->intent;
            switch ($intent) {
                case 'appointment_scheduling':
                    return $this->handleAppointmentScheduling($request);
                    
                case 'appointment_status':
                    return $this->handleAppointmentStatus($request);
                    
                case 'find_professional':
                    return $this->handleProfessionalSearch($request);
                    
                default:
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'reply' => 'I didn\'t understand your request. Could you please rephrase it?'
                        ]
                    ]);
            }
        } catch (\Exception $e) {
            Log::error('Error processing SURI webhook: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to process request',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle appointment scheduling intent
     */
    private function handleAppointmentScheduling(Request $request): JsonResponse
    {
        $params = $request->parameters ?? [];
        
        // Check required parameters
        if (!isset($params['specialty']) || !isset($params['preferred_date'])) {
            return response()->json([
                'success' => true,
                'data' => [
                    'reply' => 'I need more information to schedule your appointment. What specialty do you need and what date would you prefer?',
                    'missing_parameters' => ['specialty', 'preferred_date']
                ]
            ]);
        }
        
        // Find available professionals
        $professionals = Professional::where('specialty', 'like', "%{$params['specialty']}%")
            ->where('is_active', true)
            ->take(3)
            ->get();
            
        if ($professionals->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'reply' => 'Sorry, we couldn\'t find any professionals available for that specialty.'
                ]
            ]);
        }
        
        // Build response with professional options
        $professionalOptions = $professionals->map(function($prof) {
            return [
                'id' => $prof->id,
                'name' => $prof->name,
                'specialty' => $prof->specialty
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'reply' => 'I found some professionals available for your appointment. Which one would you prefer?',
                'options' => $professionalOptions,
                'context' => [
                    'intent' => 'select_professional',
                    'specialty' => $params['specialty'],
                    'preferred_date' => $params['preferred_date']
                ]
            ]
        ]);
    }
    
    /**
     * Handle appointment status check intent
     */
    private function handleAppointmentStatus(Request $request): JsonResponse
    {
        $userId = $request->user_id;
        
        // Find patient by external user ID
        $patient = Patient::where('external_id', $userId)->first();
        
        if (!$patient) {
            return response()->json([
                'success' => true,
                'data' => [
                    'reply' => 'I couldn\'t find your patient information. Please verify your registration in our system.'
                ]
            ]);
        }
        
        // Get upcoming appointments
        $appointments = Appointment::whereHas('solicitation', function($query) use ($patient) {
            $query->where('patient_id', $patient->id);
        })
        ->where('scheduled_at', '>=', now())
        ->orderBy('scheduled_at')
        ->get();
        
        if ($appointments->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'reply' => 'You don\'t have any upcoming appointments scheduled.'
                ]
            ]);
        }
        
        // Format appointment information
        $appointmentInfo = $appointments->map(function($appointment) {
            $professional = $appointment->provider;
            $scheduledDate = \Carbon\Carbon::parse($appointment->scheduled_at)->format('d/m/Y H:i');
            
            return "- {$scheduledDate} with Dr. {$professional->name} ({$professional->specialty})";
        })->join("\n");
        
        return response()->json([
            'success' => true,
            'data' => [
                'reply' => "Your upcoming appointments are:\n{$appointmentInfo}",
                'appointment_count' => $appointments->count()
            ]
        ]);
    }
    
    /**
     * Handle professional search intent
     */
    private function handleProfessionalSearch(Request $request): JsonResponse
    {
        $params = $request->parameters ?? [];
        
        if (!isset($params['specialty'])) {
            return response()->json([
                'success' => true,
                'data' => [
                    'reply' => 'What specialty are you looking for?',
                    'missing_parameters' => ['specialty']
                ]
            ]);
        }
        
        $professionals = Professional::where('specialty', 'like', "%{$params['specialty']}%")
            ->where('is_active', true)
            ->take(5)
            ->get();
            
        if ($professionals->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'reply' => 'Sorry, we couldn\'t find any professionals for that specialty.'
                ]
            ]);
        }
        
        $professionalsList = $professionals->map(function($prof) {
            return "- Dr. {$prof->name} ({$prof->specialty})";
        })->join("\n");
        
        return response()->json([
            'success' => true,
            'data' => [
                'reply' => "I found the following professionals:\n{$professionalsList}",
                'professionals' => $professionals->map(function($prof) {
                    return [
                        'id' => $prof->id,
                        'name' => $prof->name,
                        'specialty' => $prof->specialty
                    ];
                })
            ]
        ]);
    }
    
    /**
     * Send message to SURI chatbot from the system
     */
    public function sendMessage(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'patient_id' => 'required|exists:patients,id',
                'message' => 'required|string',
                'context' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $patient = Patient::findOrFail($request->patient_id);
            
            // Integration with SURI API (simulate for the example)
            Log::info('Message sent to SURI', [
                'patient_id' => $patient->id,
                'message' => $request->message,
                'context' => $request->context
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => [
                    'message_id' => uniqid('msg_'),
                    'timestamp' => now()->toIso8601String()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending message to SURI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 