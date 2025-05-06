<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Solicitation;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Contract;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\SuriChat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        // Determine the first day of the current month
        $startOfMonth = Carbon::now()->startOfMonth();
        $today = Carbon::today();

        // Get user for context-aware data
        $user = Auth::user();
        $userRole = $user->role;
        $userId = $user->id;

        // Initialize base query builders with appropriate scopes
        $appointmentsQuery = Appointment::query();
        $solicitationsQuery = Solicitation::query();
        $patientsQuery = Patient::query();
        $paymentsQuery = Payment::query();

        // Apply filters based on user role
        if ($userRole === 'professional') {
            $professionalId = Professional::where('user_id', $userId)->value('id');
            $appointmentsQuery->where('professional_id', $professionalId);
            $solicitationsQuery->where('professional_id', $professionalId);
            $paymentsQuery->where('entity_type', 'professional')->where('entity_id', $professionalId);
        } elseif ($userRole === 'clinic') {
            $clinicId = Clinic::where('user_id', $userId)->value('id');
            $appointmentsQuery->where('clinic_id', $clinicId);
            $solicitationsQuery->where('clinic_id', $clinicId);
            $paymentsQuery->where('entity_type', 'clinic')->where('entity_id', $clinicId);
        }

        // Calculate appointment statistics
        $appointments = [
            'total' => $appointmentsQuery->count(),
            'pending' => $appointmentsQuery->clone()->where('status', 'pending')->count(),
            'completed' => $appointmentsQuery->clone()->where('status', 'completed')->count(),
        ];

        // Calculate solicitation statistics
        $solicitations = [
            'total' => $solicitationsQuery->count(),
            'pending' => $solicitationsQuery->clone()->where('status', 'pending')->count(),
            'accepted' => $solicitationsQuery->clone()->where('status', 'accepted')->count(),
        ];

        // Calculate patient statistics
        $newPatientsThisMonth = $patientsQuery->where('created_at', '>=', $startOfMonth)->count();
        $patients = [
            'total' => $patientsQuery->count(),
            'active' => $newPatientsThisMonth,
        ];

        // Calculate revenue statistics
        $totalRevenue = $paymentsQuery->where('status', 'paid')->sum('amount');
        $pendingRevenue = $paymentsQuery->where('status', 'pending')->sum('amount');
        $revenue = [
            'total' => $totalRevenue,
            'pending' => $pendingRevenue,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'appointments' => $appointments,
                'solicitations' => $solicitations,
                'patients' => $patients,
                'revenue' => $revenue,
            ]
        ]);
    }

    /**
     * Get upcoming appointments
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUpcomingAppointments(Request $request)
    {
        $limit = $request->input('limit', 5);
        $user = Auth::user();
        $userRole = $user->role;
        $userId = $user->id;

        $query = Appointment::with(['patient', 'procedure'])
            ->whereDate('created_at', '>=', Carbon::today())
            ->orderBy('scheduled_date', 'asc');

        // Apply filters based on user role
        if ($userRole === 'professional') {
            $professionalId = Professional::where('user_id', $userId)->value('id');
            $query->where('professional_id', $professionalId);
        } elseif ($userRole === 'clinic') {
            $clinicId = Clinic::where('user_id', $userId)->value('id');
            $query->where('clinic_id', $clinicId);
        }

        $appointments = $query->take($limit)->get();

        // Format the response
        $formattedAppointments = $appointments->map(function ($appointment) {
            return [
                'id' => $appointment->id,
                'patient' => $appointment->patient->name,
                'patient_id' => $appointment->patient_id,
                'time' => $appointment->scheduled_date ? $appointment->scheduled_date->format('H:i') : null,
                'date' => $appointment->scheduled_date ? $appointment->scheduled_date->format('Y-m-d') : $appointment->created_at->format('Y-m-d'),
                'type' => $appointment->procedure ? $appointment->procedure->name : 'Consulta',
                'status' => $appointment->status,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedAppointments
        ]);
    }

    /**
     * Get today's appointments
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTodayAppointments()
    {
        $user = Auth::user();
        $userRole = $user->role;
        $userId = $user->id;

        $query = Appointment::with(['patient', 'procedure'])
            ->whereDate('created_at', Carbon::today()->format('Y-m-d'))
            ->orderBy('scheduled_date', 'asc');

        // Apply filters based on user role
        if ($userRole === 'professional') {
            $professionalId = Professional::where('user_id', $userId)->value('id');
            $query->where('professional_id', $professionalId);
        } elseif ($userRole === 'clinic') {
            $clinicId = Clinic::where('user_id', $userId)->value('id');
            $query->where('clinic_id', $clinicId);
        }

        $appointments = $query->get();

        // Format the response
        $formattedAppointments = $appointments->map(function ($appointment) {
            return [
                'id' => $appointment->id,
                'patient' => $appointment->patient->name,
                'patient_id' => $appointment->patient_id,
                'time' => $appointment->scheduled_date ? $appointment->scheduled_date->format('H:i') : null,
                'date' => $appointment->scheduled_date ? $appointment->scheduled_date->format('Y-m-d') : $appointment->created_at->format('Y-m-d'),
                'type' => $appointment->procedure ? $appointment->procedure->name : 'Consulta',
                'status' => $appointment->status,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedAppointments
        ]);
    }

    /**
     * Get recent notifications
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecentNotifications(Request $request)
    {
        $limit = $request->input('limit', 5);
        $user = Auth::user();

        $notifications = Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get();

        // Format the response
        $formattedNotifications = $notifications->map(function ($notification) {
            // Calculate relative time
            $relativeTime = Carbon::parse($notification->created_at)->diffForHumans();
            
            return [
                'id' => $notification->id,
                'sender' => $notification->sender,
                'content' => $notification->content,
                'time' => $relativeTime,
                'unread' => !$notification->read,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedNotifications
        ]);
    }

    /**
     * Mark a notification as read
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function markNotificationAsRead($id)
    {
        $user = Auth::user();
        
        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();
            
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }
        
        $notification->read = true;
        $notification->save();
        
        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Get SURI chatbot statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSuriStats()
    {
        $user = Auth::user();
        $userRole = $user->role;
        $userId = $user->id;
        
        // Count messages based on user role
        $query = SuriChat::query();
        
        if ($userRole === 'professional') {
            $professionalId = Professional::where('user_id', $userId)->value('id');
            $query->where('entity_type', 'professional')->where('entity_id', $professionalId);
        } elseif ($userRole === 'clinic') {
            $clinicId = Clinic::where('user_id', $userId)->value('id');
            $query->where('entity_type', 'clinic')->where('entity_id', $clinicId);
        } else {
            $query->where('user_id', $userId);
        }
        
        $messageCount = $query->count();
        
        return response()->json([
            'success' => true,
            'data' => [
                'message_count' => $messageCount
            ]
        ]);
    }
} 