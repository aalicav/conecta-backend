<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, Billable, SoftDeletes, HasApiTokens, \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'entity_id',
        'entity_type',
        'is_active',
        'profile_photo',
        'phone_number',
        'notification_preferences',
    ];

    protected $guard_name = 'api';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the entity that the user belongs to.
     */
    public function entity()
    {
        return $this->morphTo();
    }

    /**
     * Check if user is super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Check if user is a health plan admin.
     */
    public function isHealthPlanAdmin(): bool
    {
        return $this->hasRole('plan_admin');
    }

    /**
     * Check if user is a clinic admin.
     */
    public function isClinicAdmin(): bool
    {
        return $this->hasRole('clinic_admin');
    }

    /**
     * Check if user is a professional.
     */
    public function isProfessional(): bool
    {
        return $this->hasRole('professional');
    }

    /**
     * Route notifications for the WhatsApp channel.
     *
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return string
     */
    public function routeNotificationForWhatsapp($notification)
    {
        return $this->phone_number;
    }

    /**
     * Determine if the user can receive WhatsApp notifications.
     *
     * @return bool
     */
    public function canReceiveWhatsApp()
    {
        return $this->is_active && !empty($this->phone_number);
    }

    /**
     * Get user's notification preferences.
     *
     * @return array
     */
    public function notificationPreferences()
    {
        return $this->notification_preferences ?? [
            'email' => true,
            'whatsapp' => true,
            'in_app' => true
        ];
    }

    /**
     * Check if a specific notification channel is enabled.
     *
     * @param string $channel
     * @return bool
     */
    public function notificationChannelEnabled($channel)
    {
        $preferences = $this->notificationPreferences();
        return isset($preferences[$channel]) ? $preferences[$channel] : true;
    }
}
