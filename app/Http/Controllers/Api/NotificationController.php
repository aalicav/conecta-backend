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

class NotificationController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
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
                'role' => 'required|string',
                'title' => 'required|string|max:255',
                'body' => 'required|string',
                'action_link' => 'nullable|string',
                'icon' => 'nullable|string',
                'priority' => 'nullable|in:low,normal,high',
                'except_user_id' => 'nullable|exists:users,id',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Validation failed', 
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Use the notification service to send to role
            app(NotificationService::class)->sendToRole(
                $request->input('role'),
                $request->only(['title', 'body', 'action_link', 'icon', 'priority']),
                $request->input('except_user_id')
            );
            
            return response()->json([
                'status' => 'success', 
                'message' => 'Notification sent to users with role: ' . $request->input('role')
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
            $count = Auth::user()->unreadNotifications()->count();
            
            return response()->json([
                'status' => 'success',
                'count' => $count
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get unread notifications count: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get unread notifications count'
            ], 500);
        }
    }
} 