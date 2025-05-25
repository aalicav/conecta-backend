<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Str;
use App\Models\Professional;
use App\Models\Clinic;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ProfessionalRegistrationSubmitted;
use App\Notifications\DocumentAnalysisRequired;
use App\Notifications\NewClinicRegistered;

class NotificationController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:send notifications');
    }

    /**
     * Display a listing of the user's notifications.
     *
     * @param Request $request
     * @return AnonymousResourceCollection|JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 15);
            $notifications = Auth::user()->notifications()->paginate($perPage);

            return NotificationResource::collection($notifications);
        } catch (\Exception $e) {
            Log::error('Error fetching notifications: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of unread notifications.
     *
     * @param Request $request
     * @return AnonymousResourceCollection|JsonResponse
     */
    public function unread(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 15);
            $notifications = Auth::user()->unreadNotifications()->paginate($perPage);

            return NotificationResource::collection($notifications);
        } catch (\Exception $e) {
            Log::error('Error fetching unread notifications: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch unread notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified notification.
     *
     * @param string $id
     * @return NotificationResource|JsonResponse
     */
    public function show(string $id)
    {
        try {
            $notification = Auth::user()->notifications()->findOrFail($id);
            
            return new NotificationResource($notification);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark a notification as read.
     *
     * @param string $id
     * @return JsonResponse
     */
    public function markAsRead(string $id): JsonResponse
    {
        try {
            $notification = Auth::user()->notifications()->findOrFail($id);
            
            if ($notification->read_at === null) {
                $notification->markAsRead();
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error marking notification as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark all notifications as read.
     *
     * @return JsonResponse
     */
    public function markAllAsRead(): JsonResponse
    {
        try {
            Auth::user()->unreadNotifications->markAsRead();
            
            return response()->json(['status' => 'success', 'message' => 'Marked all notifications as read']);
        } catch (\Exception $e) {
            Log::error('Failed to mark all notifications as read: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to mark all notifications as read'], 500);
        }
    }

    /**
     * Remove the specified notification.
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $notification = Auth::user()->notifications()->findOrFail($id);
            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user notification settings.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $validator = validator($request->all(), [
                'email_notifications' => 'sometimes|boolean',
                'push_notifications' => 'sometimes|boolean',
                'sms_notifications' => 'sometimes|boolean',
                'notification_types' => 'sometimes|array',
                'notification_types.*' => 'sometimes|in:appointments,payments,system_alerts,solicitations'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $settings = $user->notification_settings ?? [];
            
            if ($request->has('email_notifications')) {
                $settings['email_notifications'] = $request->email_notifications;
            }
            
            if ($request->has('push_notifications')) {
                $settings['push_notifications'] = $request->push_notifications;
            }
            
            if ($request->has('sms_notifications')) {
                $settings['sms_notifications'] = $request->sms_notifications;
            }
            
            if ($request->has('notification_types')) {
                $settings['notification_types'] = $request->notification_types;
            }
            
            $user->notification_settings = $settings;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Notification settings updated successfully',
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating notification settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update notification settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user notification settings.
     *
     * @return JsonResponse
     */
    public function getSettings(): JsonResponse
    {
        try {
            $user = Auth::user();
            $settings = $user->notification_settings ?? [
                'email_notifications' => true,
                'push_notifications' => true,
                'sms_notifications' => false,
                'notification_types' => ['appointments', 'payments', 'system_alerts', 'solicitations']
            ];

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching notification settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notification settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a notification to all users with a specific role.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToRole(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'role' => 'required|string|exists:roles,name',
                'title' => 'required|string|max:255',
                'body' => 'required|string',
                'action_link' => 'nullable|string',
                'icon' => 'nullable|string',
                'priority' => 'nullable|in:low,normal,high',
                'type' => 'nullable|string',
                'except_user_id' => 'nullable|exists:users,id'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Validation failed', 
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Get a service instance
            $notificationService = app(NotificationService::class);
            
            // Send notification to role
            $notificationService->sendToRole(
                $request->input('role'),
                [
                    'title' => $request->input('title'),
                    'body' => $request->input('body'),
                    'action_link' => $request->input('action_link'),
                    'action_text' => $request->input('action_text', 'View'),
                    'icon' => $request->input('icon'),
                    'priority' => $request->input('priority', 'normal'),
                    'type' => $request->input('type', 'general'),
                ],
                $request->input('except_user_id')
            );
            
            return response()->json([
                'status' => 'success', 
                'message' => 'Notification sent to role'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send notification to role: ' . $e->getMessage());
            return response()->json([
                'status' => 'error', 
                'message' => 'Failed to send notification to role'
            ], 500);
        }
    }
    
    /**
     * Send a notification to a specific user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToUser(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'title' => 'required|string|max:255',
                'body' => 'required|string',
                'action_link' => 'nullable|string',
                'icon' => 'nullable|string',
                'priority' => 'nullable|in:low,normal,high',
                'type' => 'nullable|string',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Validation failed', 
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $user = User::find($request->input('user_id'));
            
            if (!$user) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'User not found'
                ], 404);
            }
            
            // Create the notification data
            $notificationData = [
                'title' => $request->input('title'),
                'body' => $request->input('body'),
                'action_url' => $request->input('action_link'),
                'action_text' => $request->input('action_text', 'View'),
                'icon' => $request->input('icon'),
                'priority' => $request->input('priority', 'normal'),
                'type' => $request->input('type', 'general'),
                'data' => [
                    'type' => $request->input('type', 'general')
                ]
            ];
            
            $user->notify(new \Illuminate\Notifications\DatabaseNotification($notificationData));
            
            return response()->json([
                'status' => 'success', 
                'message' => 'Notification sent to user'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send notification to user: ' . $e->getMessage());
            return response()->json([
                'status' => 'error', 
                'message' => 'Failed to send notification to user'
            ], 500);
        }
    }

    /**
     * Get count of unread notifications for the authenticated user.
     *
     * @return JsonResponse
     */
    public function unreadCount(): JsonResponse
    {
        try {
            // Simply count unread notifications - no model retrieval needed
            $count = Auth::user()->unreadNotifications()->count();
            
            return response()->json([
                'status' => 'success',
                'count' => $count
            ]);
        } catch (\Exception $e) {
            // This should only happen for connection errors, not empty results
            Log::error('Error retrieving notification count: ' . $e->getMessage());
            return response()->json([
                'status' => 'success',
                'count' => 0
            ]);
        }
    }

    /**
     * Send a test notification to the authenticated user.
     *
     * @return JsonResponse
     */
    public function test(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Create a simple array notification
            $notification = [
                'id' => Str::uuid()->toString(),
                'type' => 'App\\Notifications\\TestNotification',
                'notifiable_type' => get_class($user),
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'Notificação de Teste',
                    'body' => 'Esta é uma notificação de teste enviada via endpoint de teste.',
                    'action_url' => '/dashboard',
                    'action_text' => 'Ver Dashboard',
                    'icon' => 'bell',
                    'priority' => 'normal',
                    'type' => 'test_notification'
                ]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now()
            ];
            
            // Insert directly into the notifications table
            DB::table('notifications')->insert($notification);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Test notification sent successfully',
                'data' => json_decode($notification['data'], true)
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send test notification: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send test notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a test email to the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function testEmail(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'email' => 'nullable|email',
                'subject' => 'nullable|string|max:255',
                'message' => 'nullable|string',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Validation failed', 
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = $request->input('email', $user->email);
            $subject = $request->input('subject', 'Teste de Email - ' . config('app.name'));
            $message = $request->input('message', 'Este é um email de teste enviado via API.');

            Log::info('Enviando para: ' . $email);

            // Enviar email usando a classe de email TestMail
            \Illuminate\Support\Facades\Mail::to($email)
                ->send(new \App\Mail\TestMail($message, $subject));
            
            // Obter configurações atuais para retornar ao usuário
            $mailConfig = config('mail');
            $safeConfig = [
                'driver' => $mailConfig['default'],
                'host' => $mailConfig['mailers']['smtp']['host'] ?? null,
                'port' => $mailConfig['mailers']['smtp']['port'] ?? null,
                'from_address' => $mailConfig['from']['address'] ?? null,
                'from_name' => $mailConfig['from']['name'] ?? null,
                'encryption' => $mailConfig['mailers']['smtp']['encryption'] ?? null,
            ];
            
            return response()->json([
                'status' => 'success',
                'message' => 'Email de teste enviado com sucesso',
                'data' => [
                    'email' => $email,
                    'subject' => $subject,
                    'mail_config' => $safeConfig
                ]
            ]);

        } catch (\Swift_TransportException $e) {
            Log::error('Erro de conexão SMTP: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Falha na conexão com o servidor de email',
                'error' => $e->getMessage(),
                'error_type' => 'smtp_connection'
            ], 500);
        } catch (\Exception $e) {
            Log::error('Falha ao enviar email de teste: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            return response()->json([
                'status' => 'error',
                'message' => 'Falha ao enviar email de teste',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Send notifications to all pending registrations
     */
    public function notifyPendingRegistrations(): JsonResponse
    {
        try {
            // Get all pending professionals
            $pendingProfessionals = Professional::where('status', 'pending')->get();
            
            // Get all pending clinics
            $pendingClinics = Clinic::where('status', 'pending')->get();
            
            // Get commercial and legal teams
            $commercialTeam = User::role('commercial')->get();
            $legalTeam = User::role('legal')->get();
            
            $notifiedCount = 0;
            
            // Notify about pending professionals
            foreach ($pendingProfessionals as $professional) {
                // Notify commercial team
                Notification::send($commercialTeam, new ProfessionalRegistrationSubmitted($professional));
                
                // Notify legal team
                Notification::send($legalTeam, new DocumentAnalysisRequired($professional, 'professional'));
                
                $notifiedCount++;
            }
            
            // Notify about pending clinics
            foreach ($pendingClinics as $clinic) {
                // Notify commercial team
                Notification::send($commercialTeam, new NewClinicRegistered($clinic));
                
                // Notify legal team
                Notification::send($legalTeam, new DocumentAnalysisRequired($clinic, 'clinic'));
                
                $notifiedCount++;
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Notificações enviadas com sucesso',
                'data' => [
                    'total_notified' => $notifiedCount,
                    'professionals_count' => $pendingProfessionals->count(),
                    'clinics_count' => $pendingClinics->count()
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error sending pending registration notifications: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar notificações',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 