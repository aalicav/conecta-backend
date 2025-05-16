<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'type' => $this->getNotificationType(),
            'title' => $this->getTitle(),
            'message' => $this->data['message'] ?? null,
            'data' => $this->getNotificationData(),
            'read_at' => $this->read_at ? Carbon::parse($this->read_at)->toIso8601String() : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * Get user-friendly notification type.
     *
     * @return string
     */
    protected function getNotificationType()
    {
        // If the notification type is a class
        if (is_string($this->type) && class_exists($this->type)) {
            $type = class_basename($this->type);
            return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $type));
        }
        
        // If type is in data (for DatabaseNotification)
        if (isset($this->data['type'])) {
            return $this->data['type'];
        }
        
        // Fallback
        return 'notification';
    }

    /**
     * Get notification title.
     *
     * @return string|null
     */
    protected function getTitle()
    {
        if (isset($this->data['title'])) {
            return $this->data['title'];
        }

        $type = $this->getNotificationType();
        
        $titles = [
            'appointment_notification' => 'Appointment Update',
            'payment_notification' => 'Payment Update',
            'solicitation_notification' => 'Solicitation Update',
            'system_notification' => 'System Alert'
        ];
        
        return $titles[$type] ?? 'Notification';
    }

    /**
     * Get cleaned notification data.
     *
     * @return array
     */
    protected function getNotificationData()
    {
        // Filter out keys used for presentation
        $data = array_filter($this->data, function ($key) {
            return !in_array($key, ['title', 'message']);
        }, ARRAY_FILTER_USE_KEY);
        
        return $data;
    }
} 