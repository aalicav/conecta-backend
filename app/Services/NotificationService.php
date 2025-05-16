<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Solicitation;
use App\Models\User;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Negotiation;
use App\Models\NegotiationItem;
use App\Models\Contract;
use App\Models\HealthPlan;
use App\Models\Clinic;
use App\Notifications\AppointmentCancelled;
use App\Notifications\AppointmentCompleted;
use App\Notifications\AppointmentConfirmed;
use App\Notifications\AppointmentMissed;
use App\Notifications\AppointmentReminder;
use App\Notifications\AppointmentScheduled;
use App\Notifications\AppointmentStatusChanged;
use App\Notifications\SchedulingConfigChanged;
use App\Notifications\SolicitationCreated;
use App\Notifications\SolicitationUpdated;
use App\Notifications\ProfessionalRegistrationSubmitted;
use App\Notifications\ProfessionalRegistrationReviewed;
use App\Notifications\ProfessionalContractLinked;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Auth;

class NotificationService
{
    /**
     * The WhatsApp service instance.
     *
     * @var \App\Services\WhatsAppService
     */
    protected $whatsAppService;

    /**
     * Create a new service instance.
     *
     * @param  \App\Services\WhatsAppService  $whatsAppService
     * @return void
     */
    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    /**
     * Send notification for a new solicitation.
     *
     * @param Solicitation $solicitation
     * @return void
     */
    public function notifySolicitationCreated(Solicitation $solicitation): void
    {
        try {
            // Find users to notify (health plan admins, etc.)
            $users = $this->getUsersToNotifyForSolicitation($solicitation);
            
            // Make sure we include super admins
            $superAdmins = User::role('super_admin')->where('is_active', true)->get();
            $users = $users->merge($superAdmins);
            
            if ($users->isEmpty()) {
                return;
            }
            
            Notification::send($users, new SolicitationCreated($solicitation));
            Log::info("Sent solicitation created notification for solicitation #{$solicitation->id} to " . $users->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send solicitation created notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for a scheduled appointment.
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyAppointmentScheduled(Appointment $appointment): void
    {
        // Enviar notificação do sistema
        try {
            // Find users to notify (patient, provider, health plan admin)
            $users = $this->getUsersToNotifyForAppointment($appointment);
            
            if (!$users->isEmpty()) {
                // Send system notification
                Notification::send($users, new AppointmentScheduled($appointment));
                Log::info("Sent appointment scheduled notification for appointment #{$appointment->id} to " . $users->count() . " users");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send appointment scheduled system notification: " . $e->getMessage());
        }
        
        // Enviar notificação do WhatsApp (independente da notificação do sistema)
        try {
            // Send WhatsApp notification to patient
            $this->sendWhatsAppAppointmentScheduled($appointment);
        } catch (\Exception $e) {
            Log::error("Failed to send appointment scheduled WhatsApp notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for an appointment status change.
     *
     * @param Appointment $appointment
     * @param string $previousStatus
     * @return void
     */
    public function notifyAppointmentStatusChanged(Appointment $appointment, string $previousStatus): void
    {
        try {
            // Find users to notify based on the type of status change
            $users = $this->getUsersToNotifyForAppointment($appointment);
            
            if ($users->isEmpty()) {
                return;
            }
            
            Notification::send($users, new AppointmentStatusChanged($appointment, $previousStatus));
            Log::info("Sent appointment status changed notification for appointment #{$appointment->id} from {$previousStatus} to {$appointment->status} to " . $users->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send appointment status changed notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for scheduling configuration changes.
     *
     * @param array $changes
     * @param User $changedBy
     * @return void
     */
    public function notifySchedulingConfigChanged(array $changes, User $changedBy): void
    {
        try {
            // Notify administrators only
            $users = User::role('super_admin')->where('id', '!=', $changedBy->id)->get();
            
            if ($users->isEmpty()) {
                return;
            }
            
            Notification::send($users, new SchedulingConfigChanged($changes, $changedBy));
            Log::info("Sent scheduling config changed notification to " . $users->count() . " admins");
        } catch (\Exception $e) {
            Log::error("Failed to send scheduling config changed notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for appointment confirmation.
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyAppointmentConfirmed(Appointment $appointment): void
    {
        // Enviar notificação do sistema
        try {
            // Principalmente notificar o paciente
            $users = $this->getPatientsToNotify($appointment);
            
            if (!$users->isEmpty()) {
                // Send system notification
                Notification::send($users, new AppointmentConfirmed($appointment));
                Log::info("Sent appointment confirmed notification for appointment #{$appointment->id} to " . $users->count() . " users");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send appointment confirmed system notification: " . $e->getMessage());
        }
        
        // Enviar notificação do WhatsApp (independente da notificação do sistema)
        try {
            // Send WhatsApp notification to patient
            $this->sendWhatsAppAppointmentConfirmed($appointment);
        } catch (\Exception $e) {
            Log::error("Failed to send appointment confirmed WhatsApp notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for appointment cancellation.
     *
     * @param Appointment $appointment
     * @param string|null $reason
     * @return void
     */
    public function notifyAppointmentCancelled(Appointment $appointment, ?string $reason = null): void
    {
        // Enviar notificação do sistema
        try {
            // Notificar todos os envolvidos
            $users = $this->getUsersToNotifyForAppointment($appointment);
            
            if (!$users->isEmpty()) {
                // Send system notification
                Notification::send($users, new AppointmentCancelled($appointment, $reason));
                Log::info("Sent appointment cancelled notification for appointment #{$appointment->id} to " . $users->count() . " users");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send appointment cancelled system notification: " . $e->getMessage());
        }
        
        // Enviar notificação do WhatsApp (independente da notificação do sistema)
        try {
            // Send WhatsApp notification to patient
            $this->sendWhatsAppAppointmentCancelled($appointment);
        } catch (\Exception $e) {
            Log::error("Failed to send appointment cancelled WhatsApp notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for missed appointment.
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyAppointmentMissed(Appointment $appointment): void
    {
        try {
            // Principalmente notificar o paciente
            $users = $this->getPatientsToNotify($appointment);
            
            if ($users->isEmpty()) {
                return;
            }
            
            Notification::send($users, new AppointmentMissed($appointment));
            Log::info("Sent appointment missed notification for appointment #{$appointment->id} to " . $users->count() . " users");
            
            // Note: Currently there is no WhatsApp template for missed appointments
        } catch (\Exception $e) {
            Log::error("Failed to send appointment missed notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for completed appointment.
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyAppointmentCompleted(Appointment $appointment): void
    {
        // Enviar notificação do sistema
        try {
            // Principalmente notificar o paciente
            $users = $this->getPatientsToNotify($appointment);
            
            if (!$users->isEmpty()) {
                // Send system notification
                Notification::send($users, new AppointmentCompleted($appointment));
                Log::info("Sent appointment completed notification for appointment #{$appointment->id} to " . $users->count() . " users");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send appointment completed system notification: " . $e->getMessage());
        }
        
        // Enviar notificação da pesquisa NPS por WhatsApp (independente da notificação do sistema)
        try {
            // After appointment is completed, send NPS survey via WhatsApp
            $this->sendWhatsAppNpsSurvey($appointment);
        } catch (\Exception $e) {
            Log::error("Failed to send NPS survey WhatsApp notification: " . $e->getMessage());
        }
    }

    /**
     * Send appointment reminder notification.
     *
     * @param Appointment $appointment
     * @param int $hoursRemaining
     * @return void
     */
    public function sendAppointmentReminder(Appointment $appointment, int $hoursRemaining = 24): void
    {
        // Enviar notificação do sistema
        try {
            // Principalmente notificar o paciente
            $users = $this->getPatientsToNotify($appointment);
            
            if (!$users->isEmpty()) {
                // For the current reminder system, send Notification
                Notification::send($users, new AppointmentReminder($appointment, $hoursRemaining));
                Log::info("Sent appointment reminder notification for appointment #{$appointment->id} ({$hoursRemaining} hours remaining) to " . $users->count() . " users");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send appointment reminder system notification: " . $e->getMessage());
        }
        
        // Enviar lembrete por WhatsApp quando estiver a 24 horas do agendamento
        try {
            if ($hoursRemaining == 24) {
                $this->sendWhatsAppAppointmentReminder($appointment);
            }
        } catch (\Exception $e) {
            Log::error("Failed to send appointment reminder WhatsApp notification: " . $e->getMessage());
        }
    }

    /**
     * Send WhatsApp appointment reminder.
     *
     * @param Appointment $appointment
     * @return void
     */
    protected function sendWhatsAppAppointmentReminder(Appointment $appointment): void
    {
        try {
            $patient = $appointment->solicitation->patient;
            $provider = $appointment->provider;
            
            // Only proceed if a valid provider is found
            if (!$provider) {
                return;
            }
            
            // Get clinic address from the provider
            $clinicAddress = $this->getClinicAddress($appointment);
            
            // Only send if we have a valid clinic address
            if (!$clinicAddress) {
                return;
            }
            
            $professional = null;
            if ($appointment->provider_type === 'App\\Models\\Professional') {
                $professional = \App\Models\Professional::find($appointment->provider_id);
            } else {
                // For clinics, we don't have a specific professional
                return;
            }
            
            if ($professional && $patient) {
                $this->whatsAppService->sendAppointmentReminderToPatient(
                    $patient,
                    $professional,
                    $appointment,
                    $clinicAddress
                );
                
                Log::info("Sent WhatsApp appointment reminder for appointment #{$appointment->id} to patient #{$patient->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp appointment reminder: " . $e->getMessage());
            // Just log the error, don't rethrow
        }
    }
    
    /**
     * Send WhatsApp appointment scheduled notification.
     *
     * @param Appointment $appointment
     * @return void
     */
    protected function sendWhatsAppAppointmentScheduled(Appointment $appointment): void
    {
        try {
            // Same as reminder, but sent at scheduling time
            $this->sendWhatsAppAppointmentReminder($appointment);
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp appointment scheduled notification: " . $e->getMessage());
            // Just log the error, don't rethrow
        }
    }
    
    /**
     * Send WhatsApp appointment confirmation notification.
     *
     * @param Appointment $appointment
     * @return void
     */
    protected function sendWhatsAppAppointmentConfirmed(Appointment $appointment): void
    {
        try {
            $patient = $appointment->solicitation->patient;
            
            if ($patient) {
                $this->whatsAppService->sendAppointmentConfirmationToPatient($patient);
                
                Log::info("Sent WhatsApp appointment confirmation for appointment #{$appointment->id} to patient #{$patient->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp appointment confirmation: " . $e->getMessage());
            // Just log the error, don't rethrow
        }
    }
    
    /**
     * Send WhatsApp appointment cancellation notification.
     *
     * @param Appointment $appointment
     * @return void
     */
    protected function sendWhatsAppAppointmentCancelled(Appointment $appointment): void
    {
        try {
            $patient = $appointment->solicitation->patient;
            
            if ($patient) {
                $this->whatsAppService->sendAppointmentCancellationToPatient($patient);
                
                Log::info("Sent WhatsApp appointment cancellation for appointment #{$appointment->id} to patient #{$patient->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp appointment cancellation: " . $e->getMessage());
            // Just log the error, don't rethrow
        }
    }
    
    /**
     * Send WhatsApp NPS survey after appointment completion.
     *
     * @param Appointment $appointment
     * @return void
     */
    protected function sendWhatsAppNpsSurvey(Appointment $appointment): void
    {
        try {
            $patient = $appointment->solicitation->patient;
            $provider = $appointment->provider;
            
            // Only proceed if a valid provider is found
            if (!$provider) {
                return;
            }
            
            $professional = null;
            if ($appointment->provider_type === 'App\\Models\\Professional') {
                $professional = \App\Models\Professional::find($appointment->provider_id);
            } else {
                // For clinics, we don't have a specific professional to rate
                return;
            }
            
            if ($professional && $patient) {
                $this->whatsAppService->sendNpsSurveyToPatient(
                    $patient,
                    $professional,
                    $appointment
                );
                
                Log::info("Sent WhatsApp NPS survey for appointment #{$appointment->id} to patient #{$patient->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp NPS survey: " . $e->getMessage());
            // Just log the error, don't rethrow
        }
    }
    
    /**
     * Get clinic address from appointment.
     *
     * @param Appointment $appointment
     * @return string|null
     */
    protected function getClinicAddress(Appointment $appointment): ?string
    {
        if ($appointment->provider_type === 'App\\Models\\Clinic') {
            $clinic = \App\Models\Clinic::find($appointment->provider_id);
            if ($clinic) {
                return $clinic->address . ', ' . $clinic->city . ' - ' . $clinic->state;
            }
        } elseif ($appointment->provider_type === 'App\\Models\\Professional') {
            $professional = \App\Models\Professional::find($appointment->provider_id);
            if ($professional && $professional->clinic) {
                return $professional->clinic->address . ', ' . $professional->clinic->city . ' - ' . $professional->clinic->state;
            }
        }
        
        return null;
    }

    /**
     * Get the users who should be notified about a solicitation.
     *
     * @param Solicitation $solicitation
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getUsersToNotifyForSolicitation(Solicitation $solicitation)
    {
        // Notify health plan admins and super admins
        $healthPlanAdmins = User::role('plan_admin')
            ->where('health_plan_id', $solicitation->health_plan_id)
            ->where('is_active', true)
            ->get();
            
        $superAdmins = User::role('super_admin')
            ->where('is_active', true)
            ->get();
            
        return $healthPlanAdmins->merge($superAdmins);
    }

    /**
     * Get the users who should be notified about an appointment.
     *
     * @param Appointment $appointment
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getUsersToNotifyForAppointment(Appointment $appointment)
    {
        $users = collect();
        
        // Get solicitation and related entities
        $solicitation = $appointment->solicitation;
        
        // Add health plan admin
        $healthPlanAdmins = User::role('health_plan_admin')
            ->where('health_plan_id', $solicitation->health_plan_id)
            ->where('is_active', true)
            ->get();
        $users = $users->merge($healthPlanAdmins);
        
        // Add provider users (clinic admin or professional)
        $providerType = $appointment->provider_type;
        
        if ($providerType === 'App\\Models\\Clinic') {
            $clinicAdmins = User::role('clinic_admin')
                ->where('clinic_id', $appointment->provider_id)
                ->where('is_active', true)
                ->get();
            $users = $users->merge($clinicAdmins);
        } elseif ($providerType === 'App\\Models\\Professional') {
            $professional = User::role('professional')
                ->where('professional_id', $appointment->provider_id)
                ->where('is_active', true)
                ->first();
                
            if ($professional) {
                $users->push($professional);
            }
        }
        
        // Add patient
        $patient = $solicitation->patient->user;
        if ($patient && $patient->is_active) {
            $users->push($patient);
        }
        
        return $users;
    }

    /**
     * Get the patients who should be notified about an appointment.
     *
     * @param Appointment $appointment
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getPatientsToNotify(Appointment $appointment)
    {
        $users = collect();
        
        // Get solicitation and patient
        $solicitation = $appointment->solicitation;
        $patient = $solicitation->patient->user;
        
        if ($patient && $patient->is_active) {
            $users->push($patient);
        }
        
        return $users;
    }

    /**
     * Send notification for a solicitation update.
     *
     * @param Solicitation $solicitation
     * @param array $changes
     * @return void
     */
    public function notifySolicitationUpdated(Solicitation $solicitation, array $changes = []): void
    {
        try {
            // Find users to notify
            $users = $this->getUsersToNotifyForSolicitation($solicitation);
            
            if ($users->isEmpty()) {
                return;
            }
            
            Notification::send($users, new SolicitationUpdated($solicitation, $changes));
            Log::info("Sent solicitation updated notification for solicitation #{$solicitation->id} to " . $users->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send solicitation updated notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for a new scheduling exception.
     *
     * @param \App\Models\SchedulingException $exception
     * @return void
     */
    public function notifyNewSchedulingException($exception): void
    {
        try {
            // Notificar admins sobre a nova exceção de agendamento
            $superAdmins = User::role('super_admin')->where('is_active', true)->get();
            
            if ($superAdmins->isEmpty()) {
                return;
            }
            
            if (class_exists('App\\Notifications\\SchedulingExceptionCreated')) {
                Notification::send($superAdmins, new \App\Notifications\SchedulingExceptionCreated($exception));
                Log::info("Sent scheduling exception created notification for exception #{$exception->id} to " . $superAdmins->count() . " admins");
            } else {
                // Enviar uma notificação genérica usando arrays
                $data = [
                    'title' => 'Nova Exceção de Agendamento',
                    'body' => "Uma exceção de agendamento foi solicitada para a Solicitação #{$exception->solicitation_id}",
                    'action_url' => "/scheduling-exceptions/{$exception->id}",
                    'action_text' => 'Ver Detalhes',
                ];
                
                foreach ($superAdmins as $admin) {
                    $admin->notify(new \Illuminate\Notifications\DatabaseNotification($data));
                }
                Log::info("Created scheduling exception notification records for exception #{$exception->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send scheduling exception created notification: " . $e->getMessage());
        }
    }
    
    /**
     * Send notification for an approved scheduling exception.
     *
     * @param \App\Models\SchedulingException $exception
     * @return void
     */
    public function notifySchedulingExceptionApproved($exception): void
    {
        try {
            // Notificar o solicitante da exceção que foi aprovada
            $requester = User::find($exception->requested_by);
            
            if (!$requester || !$requester->is_active) {
                return;
            }
            
            if (class_exists('App\\Notifications\\SchedulingExceptionApproved')) {
                $requester->notify(new \App\Notifications\SchedulingExceptionApproved($exception));
                Log::info("Sent scheduling exception approved notification for exception #{$exception->id} to user #{$requester->id}");
            } else {
                // Enviar uma notificação genérica
                $data = [
                    'title' => 'Exceção de Agendamento Aprovada',
                    'body' => "Sua exceção de agendamento para a Solicitação #{$exception->solicitation_id} foi APROVADA",
                    'action_url' => "/scheduling-exceptions/{$exception->id}",
                    'action_text' => 'Ver Detalhes',
                ];
                
                $requester->notify(new \Illuminate\Notifications\DatabaseNotification($data));
                Log::info("Created scheduling exception approved notification record for exception #{$exception->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send scheduling exception approved notification: " . $e->getMessage());
        }
    }
    
    /**
     * Send notification for a rejected scheduling exception.
     *
     * @param \App\Models\SchedulingException $exception
     * @return void
     */
    public function notifySchedulingExceptionRejected($exception): void
    {
        try {
            // Notificar o solicitante da exceção que foi rejeitada
            $requester = User::find($exception->requested_by);
            
            if (!$requester || !$requester->is_active) {
                return;
            }
            
            if (class_exists('App\\Notifications\\SchedulingExceptionRejected')) {
                $requester->notify(new \App\Notifications\SchedulingExceptionRejected($exception));
                Log::info("Sent scheduling exception rejected notification for exception #{$exception->id} to user #{$requester->id}");
            } else {
                // Enviar uma notificação genérica
                $data = [
                    'title' => 'Exceção de Agendamento Rejeitada',
                    'body' => "Sua exceção de agendamento para a Solicitação #{$exception->solicitation_id} foi REJEITADA",
                    'action_url' => "/scheduling-exceptions/{$exception->id}",
                    'action_text' => 'Ver Detalhes',
                ];
                
                $requester->notify(new \Illuminate\Notifications\DatabaseNotification($data));
                Log::info("Created scheduling exception rejected notification record for exception #{$exception->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send scheduling exception rejected notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification to all users with a specific role.
     *
     * @param string $roleName The name of the role
     * @param array $data The notification data (title, body, action_link, icon, etc.)
     * @param string|null $exceptUserId User ID to exclude from notification
     * @return void
     */
    public function sendToRole(string $roleName, array $data, string $exceptUserId = null): void
    {
        try {
            // Find all users with the specified role
            $query = User::role($roleName)->where('is_active', true);
            
            // Exclude specific user if provided
            if ($exceptUserId) {
                $query->where('id', '!=', $exceptUserId);
            }
            
            $users = $query->get();
            
            if ($users->isEmpty()) {
                Log::info("No users found with role '{$roleName}' to notify");
                return;
            }
            
            // Create a generic notification
            foreach ($users as $user) {
                $notificationData = [
                    'title' => $data['title'] ?? 'Nova Notificação',
                    'body' => $data['body'] ?? '',
                    'action_url' => $data['action_link'] ?? null,
                    'action_text' => $data['action_text'] ?? 'Ver Detalhes',
                    'icon' => $data['icon'] ?? null,
                    'priority' => $data['priority'] ?? 'normal',
                    'type' => $data['type'] ?? 'general',
                    'data' => [
                        'type' => $data['type'] ?? 'general'
                    ]
                ];
                
                $user->notify(new \Illuminate\Notifications\DatabaseNotification($notificationData));
            }
            
            Log::info("Sent notification to {$users->count()} users with role '{$roleName}'");
        } catch (\Exception $e) {
            Log::error("Failed to send notification to role '{$roleName}': " . $e->getMessage());
        }
    }

    /**
     * Send notification for a new professional registration.
     *
     * @param Professional $professional
     * @return void
     */
    public function notifyProfessionalRegistrationSubmitted(Professional $professional): void
    {
        try {
            // Find users with permission to approve professionals
            $validators = User::permission('approve professionals')->where('is_active', true)->get();
            
            if ($validators->isEmpty()) {
                return;
            }
            
            Notification::send($validators, new ProfessionalRegistrationSubmitted($professional));
            Log::info("Sent professional registration submitted notification for professional #{$professional->id} to " . $validators->count() . " validators");
        } catch (\Exception $e) {
            Log::error("Failed to send professional registration submitted notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for a professional registration review.
     *
     * @param Professional $professional
     * @param bool $approved
     * @param string|null $rejectionReason
     * @return void
     */
    public function notifyProfessionalRegistrationReviewed(Professional $professional, bool $approved, ?string $rejectionReason = null): void
    {
        try {
            $users = collect();

            // Notify the submitter
            $submitter = User::find($professional->created_by);
            if ($submitter && $submitter->is_active) {
                $users->push($submitter);
            }

            // If approved, also notify commercial team
            if ($approved) {
                $commercialTeam = User::permission('create contracts')->where('is_active', true)->get();
                $users = $users->merge($commercialTeam);
            }

            if ($users->isEmpty()) {
                return;
            }

            Notification::send($users, new ProfessionalRegistrationReviewed($professional, $approved, $rejectionReason));
            Log::info("Sent professional registration reviewed notification for professional #{$professional->id} to " . $users->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send professional registration reviewed notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for a professional contract linking.
     *
     * @param Professional $professional
     * @param Contract $contract
     * @param array $procedures
     * @return void
     */
    public function notifyProfessionalContractLinked(Professional $professional, Contract $contract, array $procedures): void
    {
        try {
            // Notify the professional's user
            if ($professional->user && $professional->user->is_active) {
                $professional->user->notify(new ProfessionalContractLinked($professional, $contract, $procedures));
                Log::info("Sent professional contract linked notification for professional #{$professional->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send professional contract linked notification: " . $e->getMessage());
        }
    }

    /**
     * Create a notification in the database for a user
     *
     * @param int $userId
     * @param string $title
     * @param string $body
     * @param string $type
     * @param array $data
     * @return \App\Models\Notification
     */
    public function create(int $userId, string $title, string $body, string $type, array $data = [])
    {
        // Make sure type is included in data as well
        if (!isset($data['type'])) {
            $data['type'] = $type;
        }
        
        return \App\Models\Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'data' => $data,
            'read_at' => null,
        ]);
    }

    /**
     * Notifica sobre a criação de uma nova negociação
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationCreated(Negotiation $negotiation): void
    {
        try {
            // Determinar os usuários a serem notificados com base no tipo de entidade
            $entityType = $negotiation->negotiable_type;
            $entityId = $negotiation->negotiable_id;
            
            // Buscar usuários associados à entidade
            $recipients = User::where(function($query) use ($entityType, $entityId) {
                    $query->where('entity_type', $entityType)
                          ->where('entity_id', $entityId);
                })
                ->where('is_active', true)
                ->get();
            
            if ($recipients->isEmpty()) {
                Log::info('No recipients found for negotiation creation notification', [
                    'negotiation_id' => $negotiation->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                ]);
                return;
            }
            
            $usersToNotify = collect();
            
            foreach ($recipients as $recipient) {
                // Enviar somente para usuários com os papéis relevantes
                if ($entityType === HealthPlan::class && !$recipient->hasRole('plan_admin')) {
                    continue;
                } elseif ($entityType === Professional::class && !$recipient->hasRole('professional')) {
                    continue;
                } elseif ($entityType === Clinic::class && !$recipient->hasRole('clinic_admin')) {
                    continue;
                }
                
                $usersToNotify->push($recipient);
            }
            
            if (class_exists('App\\Notifications\\NegotiationCreated') && !$usersToNotify->isEmpty()) {
                Notification::send($usersToNotify, new \App\Notifications\NegotiationCreated($negotiation));
                Log::info("Sent negotiation created notification for negotiation #{$negotiation->id} to " . $usersToNotify->count() . " users");
            } else {
                // Fallback para o método antigo caso a classe não exista
                foreach ($usersToNotify as $recipient) {
                    $this->create(
                        userId: $recipient->id,
                        title: 'Nova Negociação Criada',
                        body: "Uma nova negociação foi criada: {$negotiation->title}",
                        type: 'negotiation_created',
                        data: [
                            'negotiation_id' => $negotiation->id,
                            'title' => $negotiation->title,
                            'created_by' => $negotiation->creator->name,
                        ]
                    );
                }
                Log::info("Created negotiation created notification records for negotiation #{$negotiation->id}");
            }
        } catch (\Exception $e) {
            Log::error('Error sending negotiation created notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notifica sobre uma negociação submetida para revisão
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationSubmitted(Negotiation $negotiation): void
    {
        try {
            // Determinar os usuários a serem notificados com base no tipo de entidade
            $entityType = $negotiation->negotiable_type;
            $entityId = $negotiation->negotiable_id;
            
            // Buscar usuários associados à entidade
            $recipients = User::where(function($query) use ($entityType, $entityId) {
                    $query->where('entity_type', $entityType)
                          ->where('entity_id', $entityId);
                })
                ->where('is_active', true)
                ->get();
            
            if ($recipients->isEmpty()) {
                Log::info('No recipients found for negotiation submission notification', [
                    'negotiation_id' => $negotiation->id,
                ]);
                return;
            }
            
            // Contar os itens na negociação
            $itemCount = $negotiation->items()->count();
            $usersToNotify = collect();
            
            foreach ($recipients as $recipient) {
                // Enviar somente para usuários com os papéis relevantes
                if ($entityType === HealthPlan::class && !$recipient->hasRole('plan_admin')) {
                    continue;
                } elseif ($entityType === Professional::class && !$recipient->hasRole('professional')) {
                    continue;
                } elseif ($entityType === Clinic::class && !$recipient->hasRole('clinic_admin')) {
                    continue;
                }
                
                $usersToNotify->push($recipient);
            }
            
            if (class_exists('App\\Notifications\\NegotiationSubmitted') && !$usersToNotify->isEmpty()) {
                Notification::send($usersToNotify, new \App\Notifications\NegotiationSubmitted($negotiation));
                Log::info("Sent negotiation submitted notification for negotiation #{$negotiation->id} to " . $usersToNotify->count() . " users");
            } else {
                // Fallback para o método antigo caso a classe não exista
                foreach ($usersToNotify as $recipient) {
                    $this->create(
                        userId: $recipient->id,
                        title: 'Negociação Submetida para Revisão',
                        body: "A negociação '{$negotiation->title}' foi submetida para sua revisão, com {$itemCount} procedimentos.",
                        type: 'negotiation_submitted',
                        data: [
                            'negotiation_id' => $negotiation->id,
                            'title' => $negotiation->title,
                            'item_count' => $itemCount,
                            'submitted_by' => $negotiation->creator->name,
                        ]
                    );
                }
                Log::info("Created negotiation submitted notification records for negotiation #{$negotiation->id}");
            }
        } catch (\Exception $e) {
            Log::error('Error sending negotiation submitted notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notifica sobre o cancelamento de uma negociação
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationCancelled(Negotiation $negotiation): void
    {
        try {
            // Notificar todas as partes envolvidas
            $usersToNotify = collect();

            // 1. Notificar o criador da negociação (se não for o usuário atual)
            $currentUserId = Auth::id();
            $creator = $negotiation->creator;
            
            if ($creator->id !== $currentUserId && $creator->is_active) {
                $usersToNotify->push($creator);
            }
            
            // 2. Notificar os representantes da entidade
            $entityType = $negotiation->negotiable_type;
            $entityId = $negotiation->negotiable_id;
            
            $entityUsers = User::where(function($query) use ($entityType, $entityId) {
                    $query->where('entity_type', $entityType)
                          ->where('entity_id', $entityId);
                })
                ->where('is_active', true)
                ->where('id', '!=', $currentUserId) // Não notificar o usuário atual
                ->get();
            
            foreach ($entityUsers as $user) {
                // Enviar somente para usuários com os papéis relevantes
                if ($entityType === HealthPlan::class && !$user->hasRole('plan_admin')) {
                    continue;
                } elseif ($entityType === Professional::class && !$user->hasRole('professional')) {
                    continue;
                } elseif ($entityType === Clinic::class && !$user->hasRole('clinic_admin')) {
                    continue;
                }
                
                $usersToNotify->push($user);
            }
            
            if (class_exists('App\\Notifications\\NegotiationCancelled') && !$usersToNotify->isEmpty()) {
                Notification::send($usersToNotify, new \App\Notifications\NegotiationCancelled($negotiation));
                Log::info("Sent negotiation cancelled notification for negotiation #{$negotiation->id} to " . $usersToNotify->count() . " users");
            } else {
                // Fallback para o método antigo caso a classe não exista
                foreach ($usersToNotify as $user) {
                    $this->create(
                        userId: $user->id,
                        title: 'Negociação Cancelada',
                        body: "A negociação '{$negotiation->title}' foi cancelada.",
                        type: 'negotiation_cancelled',
                        data: [
                            'negotiation_id' => $negotiation->id,
                            'title' => $negotiation->title,
                            'cancelled_by' => Auth::user()->name,
                        ]
                    );
                }
                Log::info("Created negotiation cancelled notification records for negotiation #{$negotiation->id}");
            }
        } catch (\Exception $e) {
            Log::error('Error sending negotiation cancelled notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notifica sobre a resposta a um item de negociação
     *
     * @param NegotiationItem $item
     * @return void
     */
    public function notifyItemResponse(NegotiationItem $item): void
    {
        try {
            $negotiation = $item->negotiation;
            $creator = $negotiation->creator;
            $currentUserId = Auth::id();
            
            // Não enviar notificação se o criador é quem está respondendo
            if ($creator->id === $currentUserId || !$creator->is_active) {
                return;
            }
            
            // Obter informação do item e do procedimento TUSS
            $tuss = $item->tuss;
            $tussName = $tuss ? $tuss->name : 'Procedimento';
            
            if (class_exists('App\\Notifications\\NegotiationItemResponse')) {
                $creator->notify(new \App\Notifications\NegotiationItemResponse($item));
                Log::info("Sent negotiation item response notification for item #{$item->id} to user #{$creator->id}");
            } else {
                // Fallback para o método antigo caso a classe não exista
                $statusText = match($item->status) {
                    'approved' => 'aprovado',
                    'rejected' => 'rejeitado',
                    default => 'respondido'
                };
                
                // Mensagem personalizada baseada no status
                $body = "O procedimento '{$tussName}' foi {$statusText}";
                if ($item->status === 'approved' && $item->approved_value) {
                    $formattedValue = 'R$ ' . number_format($item->approved_value, 2, ',', '.');
                    $body .= " com valor de {$formattedValue}";
                }
                if ($item->notes) {
                    $body .= ". Observação: {$item->notes}";
                }
                
                // Enviar notificação para o criador da negociação
                $this->create(
                    userId: $creator->id,
                    title: 'Resposta em Item da Negociação',
                    body: $body,
                    type: 'item_response',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'item_id' => $item->id,
                        'tuss_id' => $item->tuss_id,
                        'tuss_name' => $tussName,
                        'status' => $item->status,
                        'approved_value' => $item->approved_value,
                        'negotiation_title' => $negotiation->title,
                    ]
                );
                Log::info("Created negotiation item response notification for item #{$item->id}");
            }
        } catch (\Exception $e) {
            Log::error('Error sending item response notification', [
                'error' => $e->getMessage(),
                'item_id' => $item->id,
            ]);
        }
    }

    /**
     * Notifica sobre uma contra-oferta em um item de negociação
     *
     * @param NegotiationItem $item
     * @return void
     */
    public function notifyCounterOffer(NegotiationItem $item): void
    {
        try {
            $negotiation = $item->negotiation;
            $creator = $negotiation->creator;
            $currentUserId = Auth::id();
            
            // Não enviar notificação se o criador é quem está enviando a contra-oferta
            if ($creator->id === $currentUserId || !$creator->is_active) {
                return;
            }
            
            // Obter informação do item e do procedimento TUSS
            $tuss = $item->tuss;
            $tussName = $tuss ? $tuss->name : 'Procedimento';
            
            if (class_exists('App\\Notifications\\NegotiationCounterOffer')) {
                $creator->notify(new \App\Notifications\NegotiationCounterOffer($item));
                Log::info("Sent negotiation counter offer notification for item #{$item->id} to user #{$creator->id}");
            } else {
                // Fallback para o método antigo caso a classe não exista
                $formattedValue = 'R$ ' . number_format($item->approved_value, 2, ',', '.');
                
                // Enviar notificação para o criador da negociação
                $this->create(
                    userId: $creator->id,
                    title: 'Contra-proposta Recebida',
                    body: "Uma contra-proposta foi feita para o procedimento '{$tussName}' com valor de {$formattedValue}.",
                    type: 'counter_offer',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'item_id' => $item->id,
                        'tuss_id' => $item->tuss_id,
                        'tuss_name' => $tussName,
                        'counter_value' => $item->approved_value,
                        'negotiation_title' => $negotiation->title,
                    ]
                );
                Log::info("Created negotiation counter offer notification for item #{$item->id}");
            }
        } catch (\Exception $e) {
            Log::error('Error sending counter offer notification', [
                'error' => $e->getMessage(),
                'item_id' => $item->id,
            ]);
        }
    }

    /**
     * Notifica sobre uma aprovação parcial de negociação
     * 
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyPartialApproval(Negotiation $negotiation): void
    {
        try {
            $creator = $negotiation->creator;
            $currentUserId = Auth::id();
            
            // Não enviar notificação se o criador é quem está avaliando
            if ($creator->id === $currentUserId || !$creator->is_active) {
                return;
            }
            
            if (class_exists('App\\Notifications\\NegotiationPartiallyApproved')) {
                $creator->notify(new \App\Notifications\NegotiationPartiallyApproved($negotiation));
                Log::info("Sent negotiation partially approved notification for negotiation #{$negotiation->id} to user #{$creator->id}");
            } else {
                // Fallback para o método antigo caso a classe não exista
                // Calcular estatísticas da negociação
                $totalItems = $negotiation->items()->count();
                $approvedItems = $negotiation->items()->where('status', 'approved')->count();
                $rejectedItems = $negotiation->items()->where('status', 'rejected')->count();
                
                // Calcular valores financeiros
                $totalProposed = $negotiation->items()->sum('proposed_value');
                $totalApproved = $negotiation->items()->where('status', 'approved')->sum('approved_value');
                
                $formattedProposed = 'R$ ' . number_format($totalProposed, 2, ',', '.');
                $formattedApproved = 'R$ ' . number_format($totalApproved, 2, ',', '.');
                
                // Criar mensagem detalhada
                $body = "A negociação '{$negotiation->title}' foi parcialmente aprovada. ";
                $body .= "De {$totalItems} itens, {$approvedItems} foram aprovados e {$rejectedItems} foram rejeitados. ";
                $body .= "Valor total proposto: {$formattedProposed}. Valor total aprovado: {$formattedApproved}.";
                
                // Enviar notificação para o criador da negociação
                $this->create(
                    userId: $creator->id,
                    title: 'Negociação Parcialmente Aprovada',
                    body: $body,
                    type: 'negotiation_partially_approved',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                        'total_items' => $totalItems,
                        'approved_items' => $approvedItems,
                        'rejected_items' => $rejectedItems,
                        'total_proposed' => $totalProposed,
                        'total_approved' => $totalApproved
                    ]
                );
                Log::info("Created negotiation partially approved notification for negotiation #{$negotiation->id}");
            }
        } catch (\Exception $e) {
            Log::error('Error sending partial approval notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }
} 