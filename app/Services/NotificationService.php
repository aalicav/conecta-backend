<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Solicitation;
use App\Models\SolicitationInvite;
use App\Models\User;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\ProfessionalAvailability;
use App\Models\Negotiation;
use App\Models\NegotiationItem;
use App\Models\Contract;
use App\Models\HealthPlan;
use App\Models\Clinic;
use App\Models\Notification as ModelNotification;
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
use App\Notifications\SolicitationInviteCreated;
use App\Notifications\AvailabilitySelected;
use App\Notifications\AvailabilityRejected;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class NotificationService
{
    /**
     * The WhatsApp service instance.
     *
     * @var \App\Services\WhatsAppService
     */
    protected $whatsAppService;

    /**
     * The WhatsApp template builder instance.
     *
     * @var \App\Services\WhatsAppTemplateBuilder
     */
    protected $whatsAppTemplateBuilder;

    /**
     * Create a new service instance.
     *
     * @param  \App\Services\WhatsAppService  $whatsAppService
     * @param  \App\Services\WhatsAppTemplateBuilder  $whatsAppTemplateBuilder
     * @return void
     */
    public function __construct()
    {
        $this->whatsAppService = new WhatsAppService(new WhatsAppTemplateBuilder());
        $this->whatsAppTemplateBuilder = new WhatsAppTemplateBuilder();
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
            $superAdmins = User::role('super_admin')->get();
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
     * Send notifications when an appointment is scheduled.
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyAppointmentScheduled(Appointment $appointment): void
    {
        try {
            // Load all necessary relationships to prevent "property on string" errors
            $appointment->load([
                'solicitation.patient',
                'solicitation.healthPlan',
                'solicitation.tuss',
                'provider',
                'address'
            ]);
            
            // Enviar notificação do sistema
            $users = $this->getUsersToNotifyForAppointment($appointment);
            
            if (!$users->isEmpty()) {
                // Send system notification
                Notification::send($users, new AppointmentScheduled($appointment));
                Log::info("Sent appointment scheduled notification for appointment #{$appointment->id} to " . $users->count() . " users");
            }
            
            // Send WhatsApp notification
            $this->sendWhatsAppAppointmentScheduled($appointment);
            
        } catch (\Exception $e) {
            Log::error("Failed to send appointment scheduled notifications: " . $e->getMessage(), [
                'appointment_id' => $appointment->id
            ]);
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
        try {
            // Load all necessary relationships to prevent "property on string" errors
            $appointment->load([
                'solicitation.patient',
                'solicitation.healthPlan',
                'solicitation.tuss',
                'provider',
                'address'
            ]);
            
            // Enviar notificação do sistema
            $users = $this->getUsersToNotifyForAppointment($appointment);
            
            if (!$users->isEmpty()) {
                // Send system notification
                Notification::send($users, new AppointmentConfirmed($appointment));
                Log::info("Sent appointment confirmed notification for appointment #{$appointment->id} to " . $users->count() . " users");
            }
            
            // Send WhatsApp notification
            $this->sendWhatsAppAppointmentConfirmed($appointment);
            
        } catch (\Exception $e) {
            Log::error("Failed to send appointment confirmed notifications: " . $e->getMessage(), [
                'appointment_id' => $appointment->id
            ]);
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
            // Notificar usuários relevantes sobre conclusão do agendamento
            $users = $this->getUsersToNotifyForAppointment($appointment);
            
            if (!$users->isEmpty()) {
                // Send system notification
                Notification::send($users, new AppointmentCompleted($appointment));
                Log::info("Sent appointment completed notification for appointment #{$appointment->id} to " . $users->count() . " users");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send appointment completed system notification: " . $e->getMessage());
        }
        
        // Enviar pesquisa NPS via WhatsApp após conclusão
        try {
            $this->sendWhatsAppNpsSurvey($appointment);
        } catch (\Exception $e) {
            Log::error("Failed to send NPS survey after appointment completion: " . $e->getMessage());
        }
    }

    /**
     * Send appointment reminder notification.
     *
     * @param Appointment $appointment
     * @param int $hoursRemaining
     * @return void
     */
    public function notifyAppointmentReminder(Appointment $appointment, int $hoursRemaining = 24): void
    {
        $this->sendAppointmentReminder($appointment, $hoursRemaining);
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
        try {
            // Load all necessary relationships to prevent "property on string" errors
            $appointment->load([
                'solicitation.patient',
                'solicitation.healthPlan',
                'solicitation.tuss',
                'provider',
                'address'
            ]);
            
            // Enviar notificação do sistema
            $users = $this->getPatientsToNotify($appointment);
            
            if (!$users->isEmpty()) {
                // Send system notification
                Notification::send($users, new AppointmentReminder($appointment, $hoursRemaining));
                Log::info("Sent appointment reminder notification for appointment #{$appointment->id} to " . $users->count() . " users");
            }
            
            // Send WhatsApp notification using the same template as scheduled notification
            $patient = $appointment->solicitation->patient;
            
            if ($patient) {
                $result = $this->whatsAppService->sendAppointmentNotificationToPatient(
                    $patient,
                    $appointment
                );
                
                if ($result) {
                    Log::info("Sent WhatsApp appointment reminder to patient #{$patient->id} for appointment #{$appointment->id}");
                } else {
                    Log::warning("Failed to send WhatsApp appointment reminder to patient #{$patient->id} for appointment #{$appointment->id}");
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to send appointment reminder notifications: " . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
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
            
            Log::info("Attempting to send WhatsApp appointment reminder", [
                'appointment_id' => $appointment->id,
                'patient_id' => $patient->id ?? 'null',
                'provider_id' => $appointment->provider_id,
                'provider_type' => $appointment->provider_type
            ]);
            
            // Only proceed if a valid provider is found
            if (!$provider) {
                Log::warning("No provider found for appointment #{$appointment->id}");
                return;
            }
            
            // Get clinic address from the provider
            $clinicAddress = $this->getClinicAddress($appointment);
            
            // Log address check
            if (!$clinicAddress) {
                Log::warning("No clinic address found for appointment #{$appointment->id}", [
                    'provider_type' => $appointment->provider_type,
                    'provider_id' => $appointment->provider_id
                ]);
            }
            
            $professional = null;
            if ($appointment->provider_type === 'App\\Models\\Professional') {
                $professional = Professional::find($appointment->provider_id);
                Log::info("Found professional for appointment #{$appointment->id}", [
                    'professional_id' => $professional->id ?? 'null',
                    'professional_name' => $professional->name ?? 'null',
                    'professional_specialty_type' => $professional ? gettype($professional->specialty) : 'null',
                    'professional_specialty_value' => $professional ? $professional->specialty : 'null'
                ]);
                
                if ($professional && $patient) {
                    // Check if $professional is a Collection and extract the first item
                    if ($professional instanceof \Illuminate\Database\Eloquent\Collection) {
                        Log::warning("Professional returned as Collection, extracting first item", [
                            'appointment_id' => $appointment->id,
                            'collection_count' => $professional->count()
                        ]);
                        $professional = $professional->first();
                    }
                    
                    // Validate professional object structure
                    if (!$professional) {
                        Log::error("Professional is null after collection check", [
                            'appointment_id' => $appointment->id
                        ]);
                        return;
                    }
                    
                    Log::info("Professional validation", [
                        'appointment_id' => $appointment->id,
                        'professional_class' => get_class($professional),
                        'professional_id' => $professional->id ?? 'no_id',
                        'professional_name' => $professional->name ?? 'no_name',
                        'has_specialty' => isset($professional->specialty),
                        'specialty_type' => $professional->specialty ? gettype($professional->specialty) : 'null'
                    ]);
                    
                    // Send template message if we have clinic address, otherwise send simple text message
                    if ($clinicAddress) {
                        try {
                            Log::info("Attempting to send WhatsApp template message", [
                                'appointment_id' => $appointment->id,
                                'patient_name' => $patient->name ?? 'no_name',
                                'professional_name' => $professional->name ?? 'no_name',
                                'clinic_address' => substr($clinicAddress, 0, 50) . '...'
                            ]);
                            
                            $this->whatsAppService->sendAppointmentReminderToPatient(
                                $patient,
                                $professional,
                                $appointment,
                                $clinicAddress
                            );
                            
                            Log::info("Sent WhatsApp appointment reminder template for appointment #{$appointment->id} to patient #{$patient->id}");
                        } catch (\Exception $templateError) {
                            Log::error("Failed to send WhatsApp template message", [
                                'appointment_id' => $appointment->id,
                                'error' => $templateError->getMessage(),
                                'file' => $templateError->getFile(),
                                'line' => $templateError->getLine(),
                                'trace' => $templateError->getTraceAsString()
                            ]);
                            
                            // Fallback to simple text message
                            $this->sendSimpleWhatsAppMessage($appointment, $patient, $professional);
                        }
                    } else {
                        // Send a simplified text message if we don't have clinic address
                        $this->sendSimpleWhatsAppMessage($appointment, $patient, $professional);
                    }
                } else {
                    Log::warning("Missing professional or patient for appointment #{$appointment->id}", [
                        'professional_exists' => $professional !== null,
                        'patient_exists' => $patient !== null
                    ]);
                }
            } else {
                Log::info("Provider is not a professional for appointment #{$appointment->id}, trying alternative approach");
                
                // Try to send a simplified WhatsApp message for clinics
                if ($patient && $patient->phone) {
                    try {
                        $providerName = $provider->name ?? 'Prestador';
                        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
                        
                        $message = "📅 *Agendamento Confirmado*\n\n";
                        $message .= "Olá {$patient->name}!\n\n";
                        $message .= "Seu agendamento foi confirmado:\n";
                        $message .= "📍 Local: {$providerName}\n";
                        $message .= "📅 Data: {$appointmentDate}\n\n";
                        $message .= "Em caso de dúvidas, entre em contato conosco.";
                        
                        $this->whatsAppService->sendTextMessage(
                            $patient->phone,
                            $message,
                            'App\\Models\\Appointment',
                            $appointment->id
                        );
                        
                        Log::info("Sent simplified WhatsApp appointment notification for appointment #{$appointment->id} to patient #{$patient->id}");
                    } catch (\Exception $e) {
                        Log::error("Failed to send simplified WhatsApp message for clinic", [
                            'appointment_id' => $appointment->id,
                            'error' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp appointment reminder", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            // Just log the error, don't rethrow
        }
    }
    
    /**
     * Send simple WhatsApp message when template fails or no clinic address.
     *
     * @param Appointment $appointment
     * @param Patient $patient
     * @param Professional $professional
     * @return void
     */
    protected function sendSimpleWhatsAppMessage(Appointment $appointment, $patient, $professional): void
    {
        if ($patient && $patient->phone) {
            try {
                $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
                
                // Safely get specialty name
                $specialty = 'Especialista'; // Default fallback
                if ($professional && isset($professional->specialty)) {
                    if (is_object($professional->specialty) && isset($professional->specialty->name)) {
                        $specialty = $professional->specialty->name;
                    } elseif (is_string($professional->specialty)) {
                        $specialty = $professional->specialty;
                    }
                }
                
                // Safely get professional name
                $professionalName = 'Profissional';
                if ($professional && isset($professional->name)) {
                    $professionalName = $professional->name;
                }
                
                Log::info("Sending simple WhatsApp message", [
                    'appointment_id' => $appointment->id,
                    'patient_name' => $patient->name ?? 'no_name',
                    'professional_name' => $professionalName,
                    'specialty' => $specialty
                ]);
                
                $message = "📅 *Agendamento Confirmado*\n\n";
                $message .= "Olá {$patient->name}!\n\n";
                $message .= "Seu agendamento foi confirmado:\n";
                $message .= "👨‍⚕️ Profissional: {$professionalName}\n";
                $message .= "🩺 Especialidade: {$specialty}\n";
                $message .= "📅 Data: {$appointmentDate}\n\n";
                $message .= "Em caso de dúvidas, entre em contato conosco.";
                
                $this->whatsAppService->sendTextMessage(
                    $patient->phone,
                    $message,
                    'App\\Models\\Appointment',
                    $appointment->id
                );
                
                Log::info("Sent simple WhatsApp appointment notification for appointment #{$appointment->id} to patient #{$patient->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send simple WhatsApp message", [
                    'appointment_id' => $appointment->id,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
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
            $patient = $appointment->solicitation->patient;
            
            if (!$patient) {
                Log::warning("No patient found for appointment #{$appointment->id}");
                return;
            }
            
            // Use the same template as verification message to ensure consistency
            $result = $this->whatsAppService->sendAppointmentNotificationToPatient(
                $patient,
                $appointment
            );
            
            if ($result) {
                Log::info("Sent WhatsApp appointment notification to patient #{$patient->id} for appointment #{$appointment->id}");
            } else {
                Log::warning("Failed to send WhatsApp appointment notification to patient #{$patient->id} for appointment #{$appointment->id}");
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp appointment scheduled notification: " . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
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
                // Use the same template as other appointment notifications
                $result = $this->whatsAppService->sendAppointmentNotificationToPatient(
                    $patient,
                    $appointment
                );
                
                if ($result) {
                    Log::info("Sent WhatsApp appointment confirmation to patient #{$patient->id} for appointment #{$appointment->id}");
                } else {
                    Log::warning("Failed to send WhatsApp appointment confirmation to patient #{$patient->id} for appointment #{$appointment->id}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp appointment confirmation: " . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
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
                $professional = Professional::find($appointment->provider_id);
            } else {
                // For clinics, we don't have a specific professional to rate
                return;
            }
            
            if ($professional && $patient) {
                // Check if $professional is a Collection and extract the first item
                if ($professional instanceof \Illuminate\Database\Eloquent\Collection) {
                    $professional = $professional->first();
                }
                
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
        return $appointment->address->address . ', ' . $appointment->address->number . ', ' . $appointment->address->neighborhood . ', ' . $appointment->address->city . ' - ' . $appointment->address->state;
    }

    /**
     * Get the users who should be notified about a solicitation.
     *
     * @param Solicitation $solicitation
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getUsersToNotifyForSolicitation(Solicitation $solicitation)
    {
        // Get health plan admins
        $healthPlanAdmins = User::role('plan_admin')
            ->where('entity_type', 'App\\Models\\HealthPlan')
            ->where('entity_id', $solicitation->health_plan_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get();
            
        // Get super admins
        $superAdmins = User::role('super_admin')
            ->where('is_active', true)
            ->whereNull('deleted_at')
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
        $healthPlanAdmins = User::role('plan_admin')
            ->where('entity_type', 'App\\Models\\HealthPlan')
            ->where('entity_id', $solicitation->health_plan_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get();
        $users = $users->merge($healthPlanAdmins);
        
        // Add provider users (clinic admin or professional)
        $providerType = $appointment->provider_type;
        
        if ($providerType === 'App\\Models\\Clinic') {
            $clinicAdmins = User::role('clinic_admin')
                ->where('entity_type', 'App\\Models\\Clinic')
                ->where('entity_id', $appointment->provider_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get();
            $users = $users->merge($clinicAdmins);
        } elseif ($providerType === 'App\\Models\\Professional') {
            $professional = User::role('professional')
                ->where('entity_type', 'App\\Models\\Professional')
                ->where('entity_id', $appointment->provider_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
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
     * Note: Patients don't have user accounts, they only receive WhatsApp notifications
     *
     * @param Appointment $appointment
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getPatientsToNotify(Appointment $appointment)
    {
        // Patients don't have user accounts for system notifications
        // They only receive WhatsApp notifications
        return collect();
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
            $superAdmins = User::role('super_admin')->get();
            
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
            $query = User::role($roleName);
            
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
            $validators = User::permission('approve professionals')->get();
            
            if ($validators->isEmpty()) {
                return;
            }
            
            Notification::send($validators, new ProfessionalRegistrationSubmitted($professional));
            
            // Send WhatsApp notifications
            foreach ($validators as $validator) {
                if ($validator->phone) {
                    try {
                        $this->whatsAppService->sendNewProfessionalNotification(
                            $validator,
                            $professional
                        );
                    } catch (\Exception $whatsappError) {
                        Log::error("Failed to send WhatsApp notification for new professional registration to {$validator->phone}", [
                            'error' => $whatsappError->getMessage(),
                            'professional_id' => $professional->id
                        ]);
                    }
                }
            }
            
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
                $commercialTeam = User::permission('create contracts')->get();
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
        
        return ModelNotification::create([
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
                
                ->get();
            
            // Buscar administradores do sistema
            $admins = User::role(['super_admin', 'director', 'commercial_manager', 'financial_manager', 'legal'])
                
                ->get();
            
            // Mesclar as listas de destinatários
            $recipients = $recipients->merge($admins);
            
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
                if ($entityType === HealthPlan::class && !$recipient->hasRole(['plan_admin', 'health_plan_portal', 'super_admin', 'director', 'commercial_manager'])) {
                    continue;
                } elseif ($entityType === Professional::class && !$recipient->hasRole(['professional', 'provider_portal', 'super_admin', 'director', 'commercial_manager'])) {
                    continue;
                } elseif ($entityType === Clinic::class && !$recipient->hasRole(['clinic_admin', 'provider_portal', 'super_admin', 'director', 'commercial_manager'])) {
                    continue;
                }
                
                $usersToNotify->push($recipient);
            }
            
            if (!$usersToNotify->isEmpty()) {
                // Enviar notificação do sistema
                if (class_exists('App\\Notifications\\NegotiationCreated')) {
                    Notification::send($usersToNotify, new \App\Notifications\NegotiationCreated($negotiation));
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
                }
                
                // Enviar e-mail para cada destinatário
                foreach ($usersToNotify as $recipient) {
                    try {
                        // Gerar URL da ação
                        $actionUrl = url("/negotiations/{$negotiation->id}");
                        
                        // Enviar email
                        \Mail::to($recipient->email)
                            ->send(new \App\Mail\NegotiationCreated(
                                $negotiation,
                                $recipient,
                                $actionUrl
                            ));

                        // Enviar notificação do WhatsApp
                        if ($recipient->phone) {
                            $this->whatsAppService->sendNegotiationCreatedNotification(
                                $recipient,
                                $negotiation
                            );
                        }
                    } catch (\Exception $emailError) {
                        Log::error("Failed to send email for negotiation created to {$recipient->email}", [
                            'error' => $emailError->getMessage(),
                            'negotiation_id' => $negotiation->id
                        ]);
                    }
                }
                
                Log::info("Sent negotiation created notification and email for negotiation #{$negotiation->id} to " . $usersToNotify->count() . " users");
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
                
                ->get();
            
            // Buscar aprovadores do sistema
            $approvers = User::role(['super_admin', 'director', 'commercial_manager', 'financial_manager', 'legal'])
                
                ->get();
                
            // Mesclar as listas de destinatários
            $recipients = $recipients->merge($approvers);
            
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
                if ($entityType === HealthPlan::class && !$recipient->hasRole(['plan_admin', 'health_plan_portal', 'super_admin', 'director', 'commercial_manager'])) {
                    continue;
                } elseif ($entityType === Professional::class && !$recipient->hasRole(['professional', 'provider_portal', 'super_admin', 'director', 'commercial_manager'])) {
                    continue;
                } elseif ($entityType === Clinic::class && !$recipient->hasRole(['clinic_admin', 'provider_portal', 'super_admin', 'director', 'commercial_manager'])) {
                    continue;
                }
                
                $usersToNotify->push($recipient);
            }
            
            if (!$usersToNotify->isEmpty()) {
                // Enviar notificação do sistema
                if (class_exists('App\\Notifications\\NegotiationSubmitted')) {
                    Notification::send($usersToNotify, new \App\Notifications\NegotiationSubmitted($negotiation));
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
                }
                
                // Enviar e-mail para cada destinatário
                foreach ($usersToNotify as $recipient) {
                    try {
                        // Gerar URL da ação
                        $actionUrl = url("/negotiations/{$negotiation->id}");
                        
                        // Enviar email
                        \Mail::to($recipient->email)
                            ->send(new \App\Mail\NegotiationSubmitted(
                                $negotiation,
                                $recipient,
                                $actionUrl
                            ));
                    } catch (\Exception $emailError) {
                        Log::error("Failed to send email for negotiation submitted to {$recipient->email}", [
                            'error' => $emailError->getMessage(),
                            'negotiation_id' => $negotiation->id
                        ]);
                    }
                }
                
                Log::info("Sent negotiation submitted notification and email for negotiation #{$negotiation->id} to " . $usersToNotify->count() . " users");
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
     * @param string|null $reason Motivo do cancelamento
     * @return void
     */
    public function notifyNegotiationCancelled(Negotiation $negotiation, ?string $reason = null): void
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
            
            if (!$usersToNotify->isEmpty()) {
                // Enviar notificação do sistema
                if (class_exists('App\\Notifications\\NegotiationCancelled')) {
                    Notification::send($usersToNotify, new \App\Notifications\NegotiationCancelled($negotiation));
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
                }
                
                // Enviar e-mail para cada destinatário
                foreach ($usersToNotify as $recipient) {
                    try {
                        // Gerar URL da ação
                        $actionUrl = url("/negotiations/{$negotiation->id}");
                        
                        // Enviar email
                        \Mail::to($recipient->email)
                            ->send(new \App\Mail\NegotiationCancelled(
                                $negotiation,
                                $recipient,
                                $actionUrl,
                                $reason
                            ));
                    } catch (\Exception $emailError) {
                        Log::error("Failed to send email for negotiation cancelled to {$recipient->email}", [
                            'error' => $emailError->getMessage(),
                            'negotiation_id' => $negotiation->id
                        ]);
                    }
                }
                
                Log::info("Sent negotiation cancelled notification and email for negotiation #{$negotiation->id} to " . $usersToNotify->count() . " users");
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
            
            // Enviar notificação do sistema
            if (class_exists('App\\Notifications\\NegotiationItemResponse')) {
                $creator->notify(new \App\Notifications\NegotiationItemResponse($item));
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
            }
            
            // Enviar e-mail
            try {
                // Gerar URL da ação
                $actionUrl = url("/negotiations/{$negotiation->id}");
                
                // Enviar email
                \Mail::to($creator->email)
                    ->send(new \App\Mail\ItemResponse(
                        $item,
                        $creator,
                        $actionUrl
                    ));
                
                Log::info("Sent item response notification and email for item #{$item->id} to user #{$creator->id}");
            } catch (\Exception $emailError) {
                Log::error("Failed to send email for item response to {$creator->email}", [
                    'error' => $emailError->getMessage(),
                    'item_id' => $item->id,
                    'negotiation_id' => $negotiation->id
                ]);
            }

            // Enviar notificação via WhatsApp
            $phone = $creator->phones()->first();
            if ($phone) {
                $this->whatsAppService->sendNegotiationItemResponse(
                    $creator->name,
                    $tussName,
                    number_format($item->amount, 2, ',', '.'),
                    $negotiation->name,
                    $statusText,
                    $negotiation->id,
                    $phone->number
                );
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
    public function notifyCounterOffer($item): void
    {
        try {
            // Handle both single items and collections
            if ($item instanceof \Illuminate\Database\Eloquent\Collection) {
                // If a collection was passed, use the first item
                if ($item->isEmpty()) {
                    return;
                }
                $item = $item->first();
            }
            
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
            
            // Enviar notificação do sistema
            if (class_exists('App\\Notifications\\NegotiationCounterOffer')) {
                $creator->notify(new \App\Notifications\NegotiationCounterOffer($item));
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
            }
            
            // Enviar e-mail
            try {
                // Gerar URL da ação
                $actionUrl = url("/negotiations/{$negotiation->id}");
                
                // Enviar email
                \Mail::to($creator->email)
                    ->send(new \App\Mail\CounterOffer(
                        $item,
                        $creator,
                        $actionUrl
                    ));
                
                Log::info("Sent counter offer notification and email for item #{$item->id} to user #{$creator->id}");
            } catch (\Exception $emailError) {
                Log::error("Failed to send email for counter offer to {$creator->email}", [
                    'error' => $emailError->getMessage(),
                    'item_id' => $item->id,
                    'negotiation_id' => $negotiation->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending counter offer notification', [
                'error' => $e->getMessage(),
                'item_id' => $item instanceof \Illuminate\Database\Eloquent\Collection ? 'collection' : $item->id,
            ]);
        }
    }

    /**
     * Notifica sobre a rejeição de uma negociação em um nível de aprovação
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyApprovalRejected(Negotiation $negotiation): void
    {
        try {
            // Notificar o criador da negociação
            $creator = $negotiation->creator;
            $currentUserId = Auth::id();
            
            // Não enviar notificação se o criador é quem está rejeitando
            if ($creator->id === $currentUserId || !$creator->is_active) {
                return;
            }
            
            // Enviar notificação do sistema
            if (class_exists('App\\Notifications\\NegotiationApprovalRejected')) {
                $creator->notify(new \App\Notifications\NegotiationApprovalRejected($negotiation));
            } else {
                // Fallback para o método antigo
                $rejectingUser = Auth::user();
                $rejectingUserName = $rejectingUser ? $rejectingUser->name : 'Sistema';
                $reasonText = $negotiation->rejection_reason ? ": {$negotiation->rejection_reason}" : '.';
                
                $this->create(
                    userId: $creator->id,
                    title: 'Aprovação da Negociação Rejeitada',
                    body: "A negociação '{$negotiation->title}' foi rejeitada por {$rejectingUserName}{$reasonText}",
                    type: 'negotiation_approval_rejected',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                        'rejected_by' => $rejectingUserName,
                        'rejection_reason' => $negotiation->rejection_reason,
                    ]
                );
            }
            
            // Enviar e-mail
            try {
                // Gerar URL da ação
                $actionUrl = url("/negotiations/{$negotiation->id}");
                
                // Enviar email
                \Mail::to($creator->email)
                    ->send(new \App\Mail\ApprovalRejected(
                        $negotiation,
                        $creator,
                        $actionUrl
                    ));
                
                Log::info("Sent approval rejected notification and email for negotiation #{$negotiation->id} to user #{$creator->id}");
            } catch (\Exception $emailError) {
                Log::error("Failed to send email for approval rejected to {$creator->email}", [
                    'error' => $emailError->getMessage(),
                    'negotiation_id' => $negotiation->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending approval rejected notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notifica sobre a conclusão de uma negociação
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationCompleted(Negotiation $negotiation): void
    {
        try {
            // Lista de destinatários
            $recipients = collect();
            
            // Incluir criador
            $creator = $negotiation->creator;
            if ($creator && $creator->is_active) {
                $recipients->push($creator);
            }
            
            // Incluir usuários associados à entidade
            $entityType = $negotiation->negotiable_type;
            $entityId = $negotiation->negotiable_id;
            
            $entityUsers = User::where(function($query) use ($entityType, $entityId) {
                    $query->where('entity_type', $entityType)
                          ->where('entity_id', $entityId);
                })
                
                ->get();
                
            foreach ($entityUsers as $user) {
                // Filtrar por papéis relevantes
                if ($entityType === HealthPlan::class && !$user->hasRole('plan_admin')) {
                    continue;
                } elseif ($entityType === Professional::class && !$user->hasRole('professional')) {
                    continue;
                } elseif ($entityType === Clinic::class && !$user->hasRole('clinic_admin')) {
                    continue;
                }
                
                if (!$recipients->contains('id', $user->id)) {
                    $recipients->push($user);
                }
            }
            
            // Incluir administradores relevantes
            $admins = User::role(['super_admin', 'commercial_manager', 'financial_manager'])
                
                ->get();
                
            foreach ($admins as $admin) {
                if (!$recipients->contains('id', $admin->id)) {
                    $recipients->push($admin);
                }
            }
            
            if ($recipients->isEmpty()) {
                Log::info('No recipients found for negotiation completion notification', [
                    'negotiation_id' => $negotiation->id
                ]);
                return;
            }
            
            // Enviar notificações e emails
            foreach ($recipients as $recipient) {
                // Enviar notificação do sistema
                if (class_exists('App\\Notifications\\NegotiationCompleted')) {
                    $recipient->notify(new \App\Notifications\NegotiationCompleted($negotiation));
                } else {
                    // Calcular total aprovado
                    $totalApproved = $negotiation->items()->where('status', 'approved')->sum('approved_value');
                    $formattedTotal = 'R$ ' . number_format($totalApproved, 2, ',', '.');
                    
                    // Fallback para o método antigo
                    $this->create(
                        userId: $recipient->id,
                        title: 'Negociação Concluída com Sucesso',
                        body: "A negociação '{$negotiation->title}' foi concluída com sucesso, com valor total aprovado de {$formattedTotal}.",
                        type: 'negotiation_completed',
                        data: [
                            'negotiation_id' => $negotiation->id,
                            'title' => $negotiation->title,
                            'total_approved_value' => $totalApproved,
                        ]
                    );
                }
                
                // Enviar e-mail
                try {
                    // Gerar URL da ação
                    $actionUrl = url("/negotiations/{$negotiation->id}");
                    
                    // Enviar email
                    \Mail::to($recipient->email)
                        ->send(new \App\Mail\NegotiationCompleted(
                            $negotiation,
                            $recipient,
                            $actionUrl
                        ));
                } catch (\Exception $emailError) {
                    Log::error("Failed to send email for negotiation completed to {$recipient->email}", [
                        'error' => $emailError->getMessage(),
                        'negotiation_id' => $negotiation->id
                    ]);
                }
            }
            
            Log::info("Sent negotiation completed notification and email for negotiation #{$negotiation->id} to " . $recipients->count() . " users");
        } catch (\Exception $e) {
            Log::error('Error sending negotiation completed notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notifica sobre a conclusão parcial de uma negociação
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationPartiallyCompleted(Negotiation $negotiation): void
    {
        try {
            // Lista de destinatários
            $recipients = collect();
            
            // Incluir criador
            $creator = $negotiation->creator;
            if ($creator && $creator->is_active) {
                $recipients->push($creator);
            }
            
            // Incluir usuários associados à entidade
            $entityType = $negotiation->negotiable_type;
            $entityId = $negotiation->negotiable_id;
            
            $entityUsers = User::where(function($query) use ($entityType, $entityId) {
                    $query->where('entity_type', $entityType)
                          ->where('entity_id', $entityId);
                })
                
                ->get();
                
            foreach ($entityUsers as $user) {
                // Filtrar por papéis relevantes
                if ($entityType === HealthPlan::class && !$user->hasRole('plan_admin')) {
                    continue;
                } elseif ($entityType === Professional::class && !$user->hasRole('professional')) {
                    continue;
                } elseif ($entityType === Clinic::class && !$user->hasRole('clinic_admin')) {
                    continue;
                }
                
                if (!$recipients->contains('id', $user->id)) {
                    $recipients->push($user);
                }
            }
            
            // Incluir administradores relevantes
            $admins = User::role(['super_admin', 'commercial_manager', 'financial_manager'])
                
                ->get();
                
            foreach ($admins as $admin) {
                if (!$recipients->contains('id', $admin->id)) {
                    $recipients->push($admin);
                }
            }
            
            if ($recipients->isEmpty()) {
                Log::info('No recipients found for negotiation partial completion notification', [
                    'negotiation_id' => $negotiation->id
                ]);
                return;
            }
            
            // Calcular estatísticas da negociação
            $totalItems = $negotiation->items()->count();
            $approvedItems = $negotiation->items()->where('status', 'approved')->count();
            $rejectedItems = $negotiation->items()->where('status', 'rejected')->count();
            $totalApproved = $negotiation->items()->where('status', 'approved')->sum('approved_value');
            $formattedTotal = 'R$ ' . number_format($totalApproved, 2, ',', '.');
            
            // Enviar notificações e emails
            foreach ($recipients as $recipient) {
                // Enviar notificação do sistema
                if (class_exists('App\\Notifications\\NegotiationPartiallyCompleted')) {
                    $recipient->notify(new \App\Notifications\NegotiationPartiallyCompleted($negotiation));
                } else {
                    // Fallback para o método antigo
                    $this->create(
                        userId: $recipient->id,
                        title: 'Negociação Parcialmente Concluída',
                        body: "A negociação '{$negotiation->title}' foi parcialmente concluída. {$approvedItems} de {$totalItems} itens foram aprovados, com valor total de {$formattedTotal}.",
                        type: 'negotiation_partially_completed',
                        data: [
                            'negotiation_id' => $negotiation->id,
                            'title' => $negotiation->title,
                            'total_items' => $totalItems,
                            'approved_items' => $approvedItems,
                            'rejected_items' => $rejectedItems,
                            'total_approved_value' => $totalApproved,
                        ]
                    );
                }
                
                // Enviar e-mail
                try {
                    // Gerar URL da ação
                    $actionUrl = url("/negotiations/{$negotiation->id}");
                    
                    // Enviar email
                    \Mail::to($recipient->email)
                        ->send(new \App\Mail\NegotiationPartiallyCompleted(
                            $negotiation,
                            $recipient,
                            $actionUrl
                        ));
                } catch (\Exception $emailError) {
                    Log::error("Failed to send email for negotiation partially completed to {$recipient->email}", [
                        'error' => $emailError->getMessage(),
                        'negotiation_id' => $negotiation->id
                    ]);
                }
            }
            
            Log::info("Sent negotiation partially completed notification and email for negotiation #{$negotiation->id} to " . $recipients->count() . " users");
        } catch (\Exception $e) {
            Log::error('Error sending negotiation partially completed notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notifica sobre o início de um novo ciclo de negociação
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNewNegotiationCycle(Negotiation $negotiation): void
    {
        try {
            // Determinar os usuários a serem notificados
            $recipients = collect();
            
            // Adicionar entidade envolvida
            $entityType = $negotiation->negotiable_type;
            $entityId = $negotiation->negotiable_id;
            
            $entityUsers = User::where(function($query) use ($entityType, $entityId) {
                    $query->where('entity_type', $entityType)
                          ->where('entity_id', $entityId);
                })
                
                ->get();
                
            foreach ($entityUsers as $user) {
                // Filtrar por papéis relevantes
                if ($entityType === HealthPlan::class && !$user->hasRole('plan_admin')) {
                    continue;
                } elseif ($entityType === Professional::class && !$user->hasRole('professional')) {
                    continue;
                } elseif ($entityType === Clinic::class && !$user->hasRole('clinic_admin')) {
                    continue;
                }
                
                $recipients->push($user);
            }
            
            // Adicionar criador (se não for da entidade)
            $creator = $negotiation->creator;
            if ($creator && $creator->is_active && !$recipients->contains('id', $creator->id)) {
                $recipients->push($creator);
            }
            
            if ($recipients->isEmpty()) {
                Log::info('No recipients found for new negotiation cycle notification', [
                    'negotiation_id' => $negotiation->id,
                    'cycle' => $negotiation->negotiation_cycle
                ]);
                return;
            }
            
            // Obter informações do ciclo anterior
            $previousCycles = $negotiation->previous_cycles_data ?? [];
            $previousCycle = !empty($previousCycles) ? end($previousCycles) : null;
            $previousStatus = $previousCycle ? ($previousCycle['status'] ?? 'unknown') : 'unknown';
            
            // Calcular itens pendentes
            $pendingItemsCount = $negotiation->items()->where('status', 'pending')->count();
            $totalItemsCount = $negotiation->items()->count();
            
            // Enviar notificações e emails
            foreach ($recipients as $recipient) {
                // Enviar notificação do sistema
                if (class_exists('App\\Notifications\\NewNegotiationCycle')) {
                    $recipient->notify(new \App\Notifications\NewNegotiationCycle($negotiation, $previousStatus));
                } else {
                    // Fallback para o método antigo
                    $this->create(
                        userId: $recipient->id,
                        title: 'Novo Ciclo de Negociação Iniciado',
                        body: "Um novo ciclo (#{$negotiation->negotiation_cycle}) foi iniciado para a negociação '{$negotiation->title}'.",
                        type: 'new_negotiation_cycle',
                        data: [
                            'negotiation_id' => $negotiation->id,
                            'title' => $negotiation->title,
                            'cycle_number' => $negotiation->negotiation_cycle,
                            'previous_status' => $previousStatus,
                            'pending_items' => $pendingItemsCount,
                            'total_items' => $totalItemsCount
                        ]
                    );
                }
                
                // Enviar e-mail
                try {
                    // Gerar URL da ação
                    $actionUrl = url("/negotiations/{$negotiation->id}");
                    
                    // Enviar email
                    \Mail::to($recipient->email)
                        ->send(new \App\Mail\NewNegotiationCycle(
                            $negotiation,
                            $recipient,
                            $actionUrl,
                            $previousStatus
                        ));
                } catch (\Exception $emailError) {
                    Log::error("Failed to send email for new negotiation cycle to {$recipient->email}", [
                        'error' => $emailError->getMessage(),
                        'negotiation_id' => $negotiation->id,
                        'cycle' => $negotiation->negotiation_cycle
                    ]);
                }
            }
            
            Log::info("Sent new negotiation cycle notification and email for negotiation #{$negotiation->id} cycle #{$negotiation->negotiation_cycle} to " . $recipients->count() . " users");
        } catch (\Exception $e) {
            Log::error('Error sending new negotiation cycle notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
                'cycle' => $negotiation->negotiation_cycle
            ]);
        }
    }

    /**
     * Notifica sobre a bifurcação de uma negociação
     *
     * @param Negotiation $originalNegotiation
     * @param array|\Illuminate\Database\Eloquent\Collection $forkedNegotiations
     * @return void
     */
    public function notifyNegotiationFork(Negotiation $originalNegotiation, $forkedNegotiations): void
    {
        try {
            // Determinar os usuários a serem notificados
            $recipients = collect();
            
            // Adicionar o criador da negociação original
            $creator = $originalNegotiation->creator;
            if ($creator && $creator->is_active) {
                $recipients->push($creator);
            }
            
            // Adicionar administradores e usuários com permissão para bifurcar
            $admins = User::role(['super_admin', 'commercial_manager', 'legal'])
                
                ->where('id', '!=', Auth::id()) // Não notificar quem fez a bifurcação
                ->get();
                
            foreach ($admins as $admin) {
                if (!$recipients->contains('id', $admin->id)) {
                    $recipients->push($admin);
                }
            }
            
            // Adicionar usuários da entidade
            $entityType = $originalNegotiation->negotiable_type;
            $entityId = $originalNegotiation->negotiable_id;
            
            $entityUsers = User::where(function($query) use ($entityType, $entityId) {
                    $query->where('entity_type', $entityType)
                          ->where('entity_id', $entityId);
                })
                
                ->get();
                
            foreach ($entityUsers as $user) {
                // Filtrar por papéis relevantes
                if ($entityType === HealthPlan::class && !$user->hasRole('plan_admin')) {
                    continue;
                } elseif ($entityType === Professional::class && !$user->hasRole('professional')) {
                    continue;
                } elseif ($entityType === Clinic::class && !$user->hasRole('clinic_admin')) {
                    continue;
                }
                
                if (!$recipients->contains('id', $user->id)) {
                    $recipients->push($user);
                }
            }
            
            if ($recipients->isEmpty()) {
                Log::info('No recipients found for negotiation fork notification', [
                    'original_negotiation_id' => $originalNegotiation->id,
                    'fork_count' => is_array($forkedNegotiations) ? count($forkedNegotiations) : $forkedNegotiations->count()
                ]);
                return;
            }
            
            // Converter para coleção se for array
            if (is_array($forkedNegotiations)) {
                $forkedNegotiations = collect($forkedNegotiations);
            }
            
            // Enviar notificações e emails
            foreach ($recipients as $recipient) {
                // Enviar notificação do sistema
                if (class_exists('App\\Notifications\\NegotiationFork')) {
                    $recipient->notify(new \App\Notifications\NegotiationFork($originalNegotiation, $forkedNegotiations));
                } else {
                    // Fallback para o método antigo
                    $forkCount = $forkedNegotiations->count();
                    
                    $this->create(
                        userId: $recipient->id,
                        title: 'Negociação Bifurcada',
                        body: "A negociação '{$originalNegotiation->title}' foi bifurcada em {$forkCount} novas negociações.",
                        type: 'negotiation_fork',
                        data: [
                            'original_negotiation_id' => $originalNegotiation->id,
                            'original_title' => $originalNegotiation->title,
                            'fork_count' => $forkCount,
                            'forked_negotiation_ids' => $forkedNegotiations->pluck('id')->toArray()
                        ]
                    );
                }
                
                // Enviar e-mail
                try {
                    // Gerar URL da ação (dashboard de negociações)
                    $actionUrl = url("/negotiations?parent_id={$originalNegotiation->id}");
                    
                    // Enviar email
                    \Mail::to($recipient->email)
                        ->send(new \App\Mail\NegotiationFork(
                            $originalNegotiation,
                            $recipient,
                            $actionUrl,
                            $forkedNegotiations
                        ));
                } catch (\Exception $emailError) {
                    Log::error("Failed to send email for negotiation fork to {$recipient->email}", [
                        'error' => $emailError->getMessage(),
                        'original_negotiation_id' => $originalNegotiation->id,
                        'fork_count' => $forkedNegotiations->count()
                    ]);
                }
            }
            
            Log::info("Sent negotiation fork notification and email for negotiation #{$originalNegotiation->id} to " . $recipients->count() . " users");
        } catch (\Exception $e) {
            Log::error('Error sending negotiation fork notification', [
                'error' => $e->getMessage(),
                'original_negotiation_id' => $originalNegotiation->id
            ]);
        }
    }

    /**
     * Notify finance department about upcoming appointment payment.
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyFinanceDepartment(Appointment $appointment): void
    {
        try {
            // Get finance department users
            $financeUsers = User::role(['financial_manager', 'finance_admin'])
                
                ->get();
            
            if ($financeUsers->isEmpty()) {
                Log::info("No finance users to notify about appointment #{$appointment->id}");
                return;
            }

            // Prepare notification data
            $data = [
                'title' => 'Pagamento de Prestador Pendente',
                'body' => "Um agendamento foi confirmado e está pronto para pagamento ao prestador.",
                'action_link' => "/appointments/{$appointment->id}",
                'action_text' => 'Ver Detalhes',
                'icon' => 'credit-card',
                'type' => 'payment_notification',
                'appointment_id' => $appointment->id,
                'provider_type' => class_basename($appointment->provider_type),
                'provider_id' => $appointment->provider_id,
                'provider_name' => $appointment->provider->name ?? 'N/A',
                'scheduled_date' => $appointment->scheduled_date,
            ];

            // Send to all finance department users
            foreach ($financeUsers as $user) {
                $user->notify(new \Illuminate\Notifications\DatabaseNotification($data));
                
                // Send email
                if ($user->email) {
                    try {
                        Mail::to($user->email)
                            ->send(new \App\Mail\PaymentNeeded(
                                $appointment,
                                $user,
                                url("/appointments/{$appointment->id}")
                            ));
                    } catch (\Exception $emailError) {
                        Log::error("Failed to send payment email to {$user->email}", [
                            'error' => $emailError->getMessage(),
                            'appointment_id' => $appointment->id
                        ]);
                    }
                }
            }
            
            Log::info("Sent finance department notification for appointment #{$appointment->id} to " . $financeUsers->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send finance department notification: " . $e->getMessage());
        }
    }

    /**
     * Notify health plan about patient absence.
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyHealthPlanAboutAbsence(Appointment $appointment): void
    {
        try {
            // Get health plan admins
            $healthPlanId = $appointment->solicitation->health_plan_id;
            $healthPlanAdmins = User::role('plan_admin')
                ->where('health_plan_id', $healthPlanId)
                
                ->get();
            
            if ($healthPlanAdmins->isEmpty()) {
                Log::info("No health plan admins to notify about absence for appointment #{$appointment->id}");
                return;
            }

            // Prepare notification data
            $patientName = $appointment->solicitation->patient->name ?? 'Paciente';
            $data = [
                'title' => 'Paciente Ausente em Agendamento',
                'body' => "O paciente {$patientName} não compareceu ao agendamento.",
                'action_link' => "/appointments/{$appointment->id}",
                'action_text' => 'Ver Detalhes',
                'icon' => 'alert-circle',
                'type' => 'absence_notification',
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->solicitation->patient_id,
                'patient_name' => $patientName,
                'scheduled_date' => $appointment->scheduled_date,
            ];

            // Send to all health plan admins
            foreach ($healthPlanAdmins as $admin) {
                $admin->notify(new \Illuminate\Notifications\DatabaseNotification($data));
                
                // Send email
                if ($admin->email) {
                    try {
                        Mail::to($admin->email)
                            ->send(new \App\Mail\PatientAbsence(
                                $appointment,
                                $admin,
                                url("/appointments/{$appointment->id}")
                            ));
                    } catch (\Exception $emailError) {
                        Log::error("Failed to send absence email to {$admin->email}", [
                            'error' => $emailError->getMessage(),
                            'appointment_id' => $appointment->id
                        ]);
                    }
                }
            }
            
            Log::info("Sent health plan notification about absence for appointment #{$appointment->id} to " . $healthPlanAdmins->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send health plan notification about absence: " . $e->getMessage());
        }
    }

    /**
     * Notify finance department about patient absence.
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyFinanceDepartmentAboutAbsence(Appointment $appointment): void
    {
        try {
            // Get finance department users
            $financeUsers = User::role(['financial_manager', 'finance_admin'])
                
                ->get();
            
            if ($financeUsers->isEmpty()) {
                Log::info("No finance users to notify about absence for appointment #{$appointment->id}");
                return;
            }

            // Check if payment was already made
            $isPaid = false; // Determine this based on your payment tracking mechanism
            
            // Prepare notification data
            $patientName = $appointment->solicitation->patient->name ?? 'Paciente';
            $data = [
                'title' => 'Paciente Ausente - Possível Estorno',
                'body' => "O paciente {$patientName} não compareceu ao agendamento" . 
                    ($isPaid ? " que já foi pago ao prestador" : ""),
                'action_link' => "/appointments/{$appointment->id}",
                'action_text' => 'Ver Detalhes',
                'icon' => 'credit-card',
                'type' => 'absence_payment_notification',
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->solicitation->patient_id,
                'patient_name' => $patientName,
                'scheduled_date' => $appointment->scheduled_date,
                'is_paid' => $isPaid,
            ];

            // Send to all finance department users
            foreach ($financeUsers as $user) {
                $user->notify(new \Illuminate\Notifications\DatabaseNotification($data));
                
                // Send email
                if ($user->email) {
                    try {
                        Mail::to($user->email)
                            ->send(new \App\Mail\PatientAbsenceFinance(
                                $appointment,
                                $user,
                                $isPaid,
                                url("/appointments/{$appointment->id}")
                            ));
                    } catch (\Exception $emailError) {
                        Log::error("Failed to send absence finance email to {$user->email}", [
                            'error' => $emailError->getMessage(),
                            'appointment_id' => $appointment->id
                        ]);
                    }
                }
            }
            
            Log::info("Sent finance department notification about absence for appointment #{$appointment->id} to " . $financeUsers->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send finance department notification about absence: " . $e->getMessage());
        }
    }

    /**
     * Send appointment verification link to patient.
     *
     * @param Patient $patient
     * @param string $verificationUrl
     * @param Appointment $appointment
     * @return void
     */
    public function sendAppointmentVerificationToPatient(Patient $patient, string $verificationUrl, Appointment $appointment): void
    {
        try {
            // Send via email if available
            if ($patient->email) {
                Mail::to($patient->email)
                    ->send(new \App\Mail\AppointmentVerification(
                        $appointment,
                        $patient,
                        $verificationUrl,
                        'patient'
                    ));
            }
            
            // Send via WhatsApp if available
            if ($patient->phone) {
                $this->whatsAppService->sendAppointmentVerificationToPatient(
                    $patient,
                    $verificationUrl,
                    $appointment
                );
            }
            
            Log::info("Sent appointment verification to patient #{$patient->id} for appointment #{$appointment->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send appointment verification to patient: " . $e->getMessage());
        }
    }

    /**
     * Send appointment verification link to provider.
     *
     * @param mixed $provider
     * @param string $verificationUrl
     * @param Appointment $appointment
     * @return void
     */
    public function sendAppointmentVerificationToProvider($provider, string $verificationUrl, Appointment $appointment): void
    {
        try {
            // Send via email if available
            if ($provider->email) {
                Mail::to($provider->email)
                    ->send(new \App\Mail\AppointmentVerification(
                        $appointment,
                        $provider,
                        $verificationUrl,
                        'provider'
                    ));
            }
            
            // Send via WhatsApp if available
            if ($provider->phone) {
                $this->whatsAppService->sendAppointmentVerificationToProvider(
                    $provider,
                    $verificationUrl,
                    $appointment
                );
            }
            
            $providerType = get_class($provider);
            $providerId = $provider->id;
            
            Log::info("Sent appointment verification to provider {$providerType} #{$providerId} for appointment #{$appointment->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send appointment verification to provider: " . $e->getMessage());
        }
    }

    /**
     * Send guide to provider.
     *
     * @param Appointment $appointment
     * @param string $guidePath
     * @return void
     */
    public function sendGuideToProvider(Appointment $appointment, string $guidePath): void
    {
        try {
            $provider = $appointment->provider;
            
            if (!$provider) {
                Log::warning("No provider found for appointment #{$appointment->id}");
                return;
            }
            
            // Determinar email do prestador
            $providerEmail = null;
            
            if ($appointment->provider_type === 'App\\Models\\Clinic') {
                $providerEmail = $provider->email;
            } elseif ($appointment->provider_type === 'App\\Models\\Professional') {
                $providerEmail = $provider->email;
            }
            
            // Enviar guia por email se tiver endereço
            if ($providerEmail) {
                try {
                    Mail::to($providerEmail)
                        ->send(new \App\Mail\AppointmentGuide(
                            $appointment,
                            $guidePath
                        ));
                    
                    Log::info("Appointment guide sent to provider via email for appointment #{$appointment->id}");
                } catch (\Exception $emailError) {
                    Log::error("Failed to send guide email to provider: " . $emailError->getMessage(), [
                        'appointment_id' => $appointment->id,
                        'provider_email' => $providerEmail
                    ]);
                }
            }
            
            // Enviar mensagem WhatsApp se tiver número de telefone
            if ($provider->phone) {
                try {
                    $message = "A guia de atendimento para o paciente {$appointment->solicitation->patient->name} está disponível. Por favor, acesse o sistema para visualizá-la.";
                    
                    $this->whatsAppService->sendTextMessage(
                        $provider->phone,
                        $message,
                        get_class($appointment),
                        $appointment->id
                    );
                    
                    Log::info("Appointment guide notification sent to provider via WhatsApp for appointment #{$appointment->id}");
                } catch (\Exception $whatsappError) {
                    Log::error("Failed to send guide WhatsApp to provider: " . $whatsappError->getMessage(), [
                        'appointment_id' => $appointment->id,
                        'provider_phone' => $provider->phone
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to send guide to provider: " . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Generate and send verification token for appointment.
     *
     * @param Appointment $appointment
     * @return string|null The verification token
     */
    public function generateAppointmentVerificationToken(Appointment $appointment): ?string
    {
        try {
            // Gerar token único
            $token = Str::random(32);
            
            // Salvar token no agendamento
            $appointment->verification_token = $token;
            $appointment->verification_token_expires_at = Carbon::now()->addHours(24);
            $appointment->save();
            
            Log::info("Generated verification token for appointment #{$appointment->id}");
            
            return $token;
        } catch (\Exception $e) {
            Log::error("Failed to generate verification token: " . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
            
            return null;
        }
    }

    /**
     * Notify relevant users when a contract is submitted for approval.
     *
     * @param \App\Models\Contract $contract
     * @return void
     */
    public function notifyContractSubmittedForApproval($contract): void
    {
        try {
            // Notify legal team
            $legalTeam = User::role('legal')->get();
            
            if ($legalTeam->isNotEmpty()) {
                $data = [
                    'title' => 'Novo Contrato para Aprovação',
                    'body' => "Um novo contrato ({$contract->contract_number}) foi submetido para aprovação e requer revisão legal.",
                    'action_link' => "/contracts/{$contract->id}/review",
                    'action_text' => 'Revisar Contrato',
                    'icon' => 'file-text',
                    'type' => 'contract_approval'
                ];
                
                $this->sendToRole('legal', $data);
                
                Log::info("Sent contract submitted for approval notification for contract #{$contract->id} to legal team");
            }
            
            // Notify contract creator
            if ($contract->created_by) {
                $creator = User::find($contract->created_by);
                
                if ($creator && $creator->is_active) {
                    $creator->notify(new \Illuminate\Notifications\DatabaseNotification([
                        'title' => 'Contrato Submetido para Aprovação',
                        'body' => "Seu contrato ({$contract->contract_number}) foi submetido para aprovação e está aguardando revisão legal.",
                        'action_url' => "/contracts/{$contract->id}",
                        'action_text' => 'Ver Detalhes',
                        'icon' => 'file-text',
                        'type' => 'contract_submitted'
                    ]));
                    
                    Log::info("Sent contract submitted notification to creator #{$creator->id} for contract #{$contract->id}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to send contract submitted for approval notification: " . $e->getMessage(), [
                'contract_id' => $contract->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Notify relevant users when a contract status changes.
     *
     * @param \App\Models\Contract $contract
     * @param string $previousStatus
     * @param string|null $notes
     * @return void
     */
    public function notifyContractStatusChanged($contract, string $previousStatus, ?string $notes = null): void
    {
        try {
            $statusMap = [
                'draft' => 'Rascunho',
                'pending_approval' => 'Aguardando Aprovação',
                'legal_review' => 'Em Revisão Legal',
                'commercial_review' => 'Em Revisão Comercial',
                'pending_director_approval' => 'Aguardando Aprovação do Diretor',
                'approved' => 'Aprovado',
                'rejected' => 'Rejeitado',
                'expired' => 'Expirado',
                'cancelled' => 'Cancelado'
            ];
            
            $currentStatusText = $statusMap[$contract->status] ?? $contract->status;
            $previousStatusText = $statusMap[$previousStatus] ?? $previousStatus;
            
            // Determine roles to notify based on current status
            $roles = [];
            
            switch ($contract->status) {
                case 'legal_review':
                    $roles[] = 'legal';
                    break;
                case 'commercial_review':
                    $roles[] = 'commercial_manager';
                    break;
                case 'pending_director_approval':
                    $roles[] = 'director';
                    break;
                case 'approved':
                    $roles = ['legal', 'commercial_manager', 'director'];
                    break;
            }
            
            // Always notify the contract creator
            $creator = User::find($contract->created_by);
            
            if ($creator && $creator->is_active) {
                $notesText = $notes ? " Observações: {$notes}" : '';
                
                $creator->notify(new \Illuminate\Notifications\DatabaseNotification([
                    'title' => 'Status do Contrato Alterado',
                    'body' => "O status do contrato ({$contract->contract_number}) foi alterado de '{$previousStatusText}' para '{$currentStatusText}'.{$notesText}",
                    'action_url' => "/contracts/{$contract->id}",
                    'action_text' => 'Ver Detalhes',
                    'icon' => 'file-text',
                    'type' => 'contract_status_changed'
                ]));
                
                Log::info("Sent contract status changed notification to creator #{$creator->id} for contract #{$contract->id}");
            }
            
            // Notify relevant roles
            foreach ($roles as $role) {
                $data = [
                    'title' => 'Contrato Requer Sua Atenção',
                    'body' => "Um contrato ({$contract->contract_number}) requer sua revisão. Status atual: {$currentStatusText}.",
                    'action_link' => "/contracts/{$contract->id}/review",
                    'action_text' => 'Revisar Contrato',
                    'icon' => 'file-text',
                    'type' => 'contract_needs_review'
                ];
                
                $this->sendToRole($role, $data, $contract->created_by);
                
                Log::info("Sent contract needs review notification for contract #{$contract->id} to {$role} role");
            }
            
            // If contract is fully approved, notify the contractable entity
            if ($contract->status === 'approved') {
                $this->notifyContractableEntityAboutApproval($contract);
            }
        } catch (\Exception $e) {
            Log::error("Failed to send contract status changed notification: " . $e->getMessage(), [
                'contract_id' => $contract->id,
                'previous_status' => $previousStatus,
                'current_status' => $contract->status,
                'exception' => $e
            ]);
        }
    }
    
    /**
     * Notify the contractable entity (professional, clinic, etc.) when their contract is approved.
     *
     * @param \App\Models\Contract $contract
     * @return void
     */
    protected function notifyContractableEntityAboutApproval($contract): void
    {
        try {
            $contractable = $contract->contractable;
            
            if (!$contractable) {
                return;
            }
            
            // Determine entity type and get associated users
            $entityUsers = collect();
            
            if ($contractable instanceof \App\Models\Professional) {
                // Professional contract
                $user = User::where('professional_id', $contractable->id)
                    
                    ->first();
                    
                if ($user) {
                    $entityUsers->push($user);
                }
            } elseif ($contractable instanceof \App\Models\Clinic) {
                // Clinic contract
                $users = User::where('clinic_id', $contractable->id)
                    
                    ->get();
                    
                $entityUsers = $entityUsers->merge($users);
            } elseif ($contractable instanceof \App\Models\HealthPlan) {
                // Health plan contract
                $users = User::where('health_plan_id', $contractable->id)
                    
                    ->get();
                    
                $entityUsers = $entityUsers->merge($users);
            }
            
            // Notify each entity user
            foreach ($entityUsers as $user) {
                $user->notify(new \Illuminate\Notifications\DatabaseNotification([
                    'title' => 'Contrato Aprovado',
                    'body' => "Seu contrato ({$contract->contract_number}) foi aprovado e está agora ativo.",
                    'action_url' => "/contracts/{$contract->id}",
                    'action_text' => 'Ver Contrato',
                    'icon' => 'check-circle',
                    'type' => 'contract_approved'
                ]));
                
                Log::info("Sent contract approved notification to entity user #{$user->id} for contract #{$contract->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to notify contractable entity about approval: " . $e->getMessage(), [
                'contract_id' => $contract->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Send notification to a specific user.
     *
     * @param int $userId The ID of the user to notify
     * @param array $data The notification data (title, body, action_link, icon, etc.)
     * @return void
     */
    public function sendToUser(int $userId, array $data): void
    {
        try {
            // Find the user
            $user = User::where('id', $userId)
                
                ->first();
            
            if (!$user) {
                Log::warning("User #{$userId} not found or inactive for notification");
                return;
            }
            
            // Create a generic notification
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
            
            // Handle additional channels if specified
            if (isset($data['channels']) && is_array($data['channels'])) {
                // If WhatsApp is requested and user has a phone number
                if (in_array('whatsapp', $data['channels']) && $user->phone) {
                    try {
                        $this->whatsAppService->sendTextMessage(
                            $user->phone,
                            $data['body'],
                            'App\\Models\\User',
                            $user->id
                        );
                    } catch (\Exception $e) {
                        Log::error("Failed to send WhatsApp notification to user #{$userId}: " . $e->getMessage());
                    }
                }
                
                // If email is requested and user has an email
                if (in_array('email', $data['channels']) && $user->email) {
                    try {
                        \Illuminate\Support\Facades\Mail::to($user->email)
                            ->send(new \App\Mail\GeneralNotification(
                                $data['title'],
                                $data['body'],
                                $data['action_link'] ?? null
                            ));
                    } catch (\Exception $e) {
                        Log::error("Failed to send email notification to user #{$userId}: " . $e->getMessage());
                    }
                }
            }
            
            Log::info("Sent notification to user #{$userId}");
        } catch (\Exception $e) {
            Log::error("Failed to send notification to user #{$userId}: " . $e->getMessage());
        }
    }

    /**
     * Notify about approval required for a negotiation.
     *
     * @param Negotiation $negotiation
     * @param string $level
     * @return void
     */
    public function notifyApprovalRequired(Negotiation $negotiation, string $level): void
    {
        try {
            // Determine users to notify based on approval level
            $usersToNotify = collect();
            
            // Different notifications based on level
            switch ($level) {
                case 'team':
                    // Notify all team members who can approve
                    $usersToNotify = User::role(['commercial_manager', 'financial_manager', 'legal'])
                        
                        ->get();
                    break;
                    
                case 'director':
                    // Notify directors
                    $usersToNotify = User::role(['director', 'super_admin'])
                        
                        ->get();
                    break;
                    
                default:
                    // Default approval level - notify commercial and financial teams
                    $usersToNotify = User::role(['commercial_manager', 'financial_manager', 'super_admin'])
                        
                        ->get();
            }
            
            if ($usersToNotify->isEmpty()) {
                Log::info("No users found to notify about approval required for negotiation #{$negotiation->id}");
                return;
            }
            
            // Negotiation details for notification
            $entityName = $negotiation->negotiable ? $negotiation->negotiable->name : 'Entidade';
            $itemCount = $negotiation->items()->count();
            
            // Send notifications to each user
            foreach ($usersToNotify as $user) {
                // Database notification
                $this->create(
                    userId: $user->id,
                    title: 'Aprovação de Negociação Necessária',
                    body: "A negociação '{$negotiation->title}' com {$entityName} requer sua aprovação. Contém {$itemCount} itens para análise.",
                    type: 'approval_required',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                        'level' => $level,
                        'entity_name' => $entityName,
                        'item_count' => $itemCount
                    ]
                );
                
                // Email notification
                try {
                    $actionUrl = url("/negotiations/{$negotiation->id}/review");
                    
                    Mail::to($user->email)
                        ->send(new \App\Mail\GeneralNotification(
                            "Aprovação de Negociação Necessária",
                            "A negociação '{$negotiation->title}' com {$entityName} requer sua aprovação.\n\nDetalhes:\n- Número de itens: {$itemCount}\n- Nível de aprovação: " . ucfirst($level),
                            $actionUrl
                        ));
                } catch (\Exception $emailError) {
                    Log::error("Failed to send approval required email to {$user->email}", [
                        'error' => $emailError->getMessage(),
                        'negotiation_id' => $negotiation->id
                    ]);
                }
            }
            
            Log::info("Sent approval required notification for negotiation #{$negotiation->id} to " . $usersToNotify->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send approval required notification: " . $e->getMessage(), [
                'negotiation_id' => $negotiation->id,
                'level' => $level
            ]);
        }
    }

    /**
     * Notify about partial approval of a negotiation.
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyPartialApproval(Negotiation $negotiation): void
    {
        try {
            // Notify the creator of the negotiation
            $creator = $negotiation->creator;
            if (!$creator || !$creator->is_active) {
                return;
            }
            
            // Get entity name for the notification
            $entityName = $negotiation->negotiable ? $negotiation->negotiable->name : 'Entidade';
            
            // Gather statistics about the negotiation
            $totalItems = $negotiation->items()->count();
            $approvedItems = $negotiation->items()->where('status', 'approved')->count();
            
            // Database notification
            $this->create(
                userId: $creator->id,
                title: 'Negociação Parcialmente Aprovada',
                body: "Sua negociação '{$negotiation->title}' com {$entityName} foi parcialmente aprovada. {$approvedItems} de {$totalItems} itens foram aprovados.",
                type: 'partial_approval',
                data: [
                    'negotiation_id' => $negotiation->id,
                    'title' => $negotiation->title,
                    'total_items' => $totalItems,
                    'approved_items' => $approvedItems,
                    'entity_name' => $entityName
                ]
            );
            
            // Email notification
            try {
                $actionUrl = url("/negotiations/{$negotiation->id}");
                
                Mail::to($creator->email)
                    ->send(new \App\Mail\GeneralNotification(
                        "Negociação Parcialmente Aprovada",
                        "Sua negociação '{$negotiation->title}' com {$entityName} foi parcialmente aprovada.\n\nDetalhes:\n- Total de itens: {$totalItems}\n- Itens aprovados: {$approvedItems}\n\nPróximos passos: A negociação agora precisa de aprovação final.",
                        $actionUrl
                    ));
            } catch (\Exception $emailError) {
                Log::error("Failed to send partial approval email to {$creator->email}", [
                    'error' => $emailError->getMessage(),
                    'negotiation_id' => $negotiation->id
                ]);
            }
            
            Log::info("Sent partial approval notification for negotiation #{$negotiation->id} to user #{$creator->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send partial approval notification: " . $e->getMessage(), [
                'negotiation_id' => $negotiation->id
            ]);
        }
    }

    /**
     * Notify about full approval of a negotiation.
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationApproved(Negotiation $negotiation): void
    {
        try {
            // Notificar o criador da negociação
            $creator = $negotiation->creator;
            if ($creator && $creator->is_active) {
                $this->create(
                    userId: $creator->id,
                    title: 'Negociação Aprovada',
                    body: "A negociação '{$negotiation->title}' foi aprovada e está pronta para ser enviada à entidade.",
                    type: 'negotiation_approved',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                        'approved_by' => Auth::user()->name,
                    ]
                );
            }
            
            // Notificar equipe comercial
            $this->sendToRole('commercial_manager', [
                'title' => 'Negociação Aprovada',
                'body' => "Negociação #{$negotiation->id} foi aprovada e está pronta para envio à entidade.",
                'action_link' => "/negotiations/{$negotiation->id}",
                'priority' => 'high'
            ]);
            
            Log::info("Sent negotiation approved notification for negotiation #{$negotiation->id}");
        } catch (\Exception $e) {
            Log::error('Error sending negotiation approved notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notify about status rollback of a negotiation.
     *
     * @param Negotiation $negotiation
     * @param string $previousStatus
     * @param string $newStatus
     * @param string $reason
     * @return void
     */
    public function notifyStatusRollback(Negotiation $negotiation, string $previousStatus, string $newStatus, string $reason): void
    {
        try {
            // Map status codes to readable text
            $statusMap = [
                'draft' => 'Rascunho',
                'submitted' => 'Submetido',
                'pending' => 'Em Análise',
                'pending_approval' => 'Aguardando Aprovação',
                'pending_director_approval' => 'Aguardando Aprovação do Diretor',
                'approved' => 'Aprovado',
                'rejected' => 'Rejeitado',
                'complete' => 'Completo',
                'partially_complete' => 'Parcialmente Completo',
                'cancelled' => 'Cancelado',
                'forked' => 'Bifurcado'
            ];
            
            $previousStatusText = $statusMap[$previousStatus] ?? $previousStatus;
            $newStatusText = $statusMap[$newStatus] ?? $newStatus;
            
            // Determine who to notify
            $usersToNotify = collect();
            
            // Add creator if not current user
            $currentUserId = Auth::id();
            $creator = $negotiation->creator;
            
            if ($creator && $creator->id !== $currentUserId && $creator->is_active) {
                $usersToNotify->push($creator);
            }
            
            // Add relevant administrators
            $admins = User::role(['super_admin', 'commercial_manager', 'financial_manager', 'legal'])
                
                ->where('id', '!=', $currentUserId)
                ->get();
                
            foreach ($admins as $admin) {
                if (!$usersToNotify->contains('id', $admin->id)) {
                    $usersToNotify->push($admin);
                }
            }
            
            if ($usersToNotify->isEmpty()) {
                Log::info("No users to notify about status rollback for negotiation #{$negotiation->id}");
                return;
            }
            
            // Current user for the notification
            $currentUser = Auth::user();
            $currentUserName = $currentUser ? $currentUser->name : 'Sistema';
            
            // Send notifications to each user
            foreach ($usersToNotify as $user) {
                // Database notification
                $this->create(
                    userId: $user->id,
                    title: 'Status da Negociação Revertido',
                    body: "O status da negociação '{$negotiation->title}' foi revertido de '{$previousStatusText}' para '{$newStatusText}' por {$currentUserName}. Motivo: {$reason}",
                    type: 'status_rollback',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                        'previous_status' => $previousStatus,
                        'new_status' => $newStatus,
                        'reason' => $reason,
                        'rolled_back_by' => $currentUserName
                    ]
                );
                
                // Email notification
                try {
                    $actionUrl = url("/negotiations/{$negotiation->id}");
                    
                    Mail::to($user->email)
                        ->send(new \App\Mail\GeneralNotification(
                            "Status da Negociação Revertido",
                            "O status da negociação '{$negotiation->title}' foi revertido de '{$previousStatusText}' para '{$newStatusText}' por {$currentUserName}.\n\nMotivo: {$reason}\n\nPor favor, verifique a negociação para mais detalhes.",
                            $actionUrl
                        ));
                } catch (\Exception $emailError) {
                    Log::error("Failed to send status rollback email to {$user->email}", [
                        'error' => $emailError->getMessage(),
                        'negotiation_id' => $negotiation->id
                    ]);
                }
            }
            
            Log::info("Sent status rollback notification for negotiation #{$negotiation->id} to " . $usersToNotify->count() . " users");
        } catch (\Exception $e) {
            Log::error("Failed to send status rollback notification: " . $e->getMessage(), [
                'negotiation_id' => $negotiation->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus
            ]);
        }
    }

    /**
     * Notify when a new account is created
     *
     * @param User $user
     * @return void
     */
    public function notifyAccountCreated(User $user): void
    {
        $phone = $user->phones()->first();
        if ($phone) {
            $this->whatsAppService->sendAccountCreatedNotification(
                $user->name,
                $phone->number
            );
        }
    }

    /**
     * Notify when negotiation requires internal approval
     *
     * @param Negotiation $negotiation
     * @param string $approvalLevel
     * @return void
     */
    public function notifyNegotiationInternalApprovalRequired(Negotiation $negotiation, string $approvalLevel): void
    {
        $approvers = $this->getUsersToNotifyForNegotiation($negotiation, $approvalLevel);
        foreach ($approvers as $approver) {
            $phone = $approver->phones()->first();
            if ($phone) {
                $this->whatsAppService->sendNegotiationInternalApprovalRequired(
                    $approver->name,
                    $negotiation->name,
                    $negotiation->entity->name,
                    $negotiation->items()->count(),
                    $approvalLevel,
                    $negotiation->id,
                    $phone->number
                );
            }
        }
    }

    /**
     * Notify when a counter offer is received
     *
     * @param NegotiationItem $item
     * @return void
     */
    public function notifyCounterOfferReceived(NegotiationItem $item): void
    {
        $negotiation = $item->negotiation;
        $user = $negotiation->user;
        $phone = $user->phones()->first();
        
        if ($phone) {
            $this->whatsAppService->sendNegotiationCounterOfferReceived(
                $user->name,
                number_format($item->counter_offer_amount, 2, ',', '.'),
                $item->name,
                $negotiation->name,
                $negotiation->id,
                $phone->number
            );
        }
    }

    /**
     * Notify when a negotiation is submitted to an entity
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationSubmittedToEntity(Negotiation $negotiation): void
    {
        $entity = $negotiation->entity;
        $contact = $entity->contacts()->first();
        
        if ($contact && $contact->phone) {
            $this->whatsAppService->sendNegotiationSubmittedToEntity(
                $entity->name,
                $negotiation->name,
                $negotiation->id,
                $contact->phone
            );
        }
    }

    /**
     * Send NPS survey to patient
     *
     * @param Appointment $appointment
     * @return void
     */
    public function sendNpsSurvey(Appointment $appointment): void
    {
        $patient = $appointment->patient;
        $professional = $appointment->professional;
        $phone = $patient->phones()->first();
        
        if ($phone) {
            $this->whatsAppService->sendNpsSurvey(
                $patient->name,
                $appointment->scheduled_date->format('d/m/Y'),
                $professional->name,
                $professional->specialty,
                $appointment->id,
                $phone->number
            );
        }
    }

    /**
     * Send NPS provider survey to patient
     *
     * @param Appointment $appointment
     * @return void
     */
    public function sendNpsProviderSurvey(Appointment $appointment): void
    {
        $patient = $appointment->patient;
        $professional = $appointment->professional;
        $phone = $patient->phones()->first();
        
        if ($phone) {
            $this->whatsAppService->sendNpsProviderSurvey(
                $patient->name,
                $professional->name,
                $appointment->scheduled_date->format('d/m/Y'),
                $appointment->id,
                $phone->number
            );
        }
    }

    /**
     * Send NPS question to patient
     *
     * @param Appointment $appointment
     * @return void
     */
    public function sendNpsQuestion(Appointment $appointment): void
    {
        $patient = $appointment->patient;
        $phone = $patient->phones()->first();
        
        if ($phone) {
            $this->whatsAppService->sendNpsQuestion(
                $appointment->id,
                $phone->number
            );
        }
    }

    public function notifyNegotiationRejected(Negotiation $negotiation): void
    {
        $this->sendToUser($negotiation->creator_id, [
            'title' => 'Negociação Rejeitada',
            'body' => "A negociação #{$negotiation->id} foi rejeitada.",
            'action_link' => "/negotiations/{$negotiation->id}",
            'priority' => 'high'
        ]);
    }

    /**
     * Send solicitation invite notification to a provider.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @param  \App\Models\SolicitationInvite  $invite
     * @param  \App\Models\User  $provider
     * @return void
     */
    public function sendSolicitationInviteNotification(Solicitation $solicitation, SolicitationInvite $invite, User $provider)
    {
        try {
            // Create and send the notification
            $provider->notify(new SolicitationInviteCreated($solicitation, $invite));

            Log::info('Solicitation invite notification sent successfully', [
                'solicitation_id' => $solicitation->id,
                'invite_id' => $invite->id,
                'provider_id' => $provider->id,
                'provider_name' => $provider->name
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send solicitation invite notification', [
                'solicitation_id' => $solicitation->id,
                'invite_id' => $invite->id,
                'provider_id' => $provider->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Notify provider when their availability is selected and appointment is created.
     *
     * @param  \App\Models\ProfessionalAvailability  $availability
     * @param  \App\Models\Appointment  $appointment
     * @return void
     */
    public function notifyAvailabilitySelected(ProfessionalAvailability $availability, Appointment $appointment): void
    {
        try {
            // Get the provider user
            $providerUser = null;
            
            if ($availability->professional_id) {
                $professional = $availability->professional;
                $providerUser = User::where('entity_type', 'App\\Models\\Professional')
                    ->where('entity_id', $professional->id)
                    ->where('is_active', true)
                    ->first();
            } elseif ($availability->clinic_id) {
                $clinic = $availability->clinic;
                $providerUser = User::where('entity_type', 'App\\Models\\Clinic')
                    ->where('entity_id', $clinic->id)
                    ->where('is_active', true)
                    ->first();
            }
            
            if ($providerUser) {
                // Send system notification
                $providerUser->notify(new AvailabilitySelected($availability, $appointment));
                
                // Send WhatsApp notification if available
                if ($providerUser->phone) {
                    try {
                        $solicitation = $availability->solicitation;
                        $patient = $solicitation->patient;
                        $procedure = $solicitation->tuss;
                        $scheduledDate = \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
                        
                        $this->whatsAppService->sendAvailabilitySelectedNotification(
                            $providerUser->name,
                            $patient->name,
                            $procedure->description,
                            $scheduledDate,
                            $appointment->id,
                            $providerUser->phone
                        );
                    } catch (\Exception $whatsappError) {
                        Log::error("Failed to send WhatsApp notification for availability selected", [
                            'error' => $whatsappError->getMessage(),
                            'availability_id' => $availability->id,
                            'appointment_id' => $appointment->id
                        ]);
                    }
                }
                
                Log::info("Sent availability selected notification for availability #{$availability->id} to provider #{$providerUser->id}");
            }
            
            // Notify patient
            $this->notifyPatientAboutAvailabilitySelected($availability, $appointment);
            
            // Notify health plan
            $this->notifyHealthPlanAboutAvailabilitySelected($availability, $appointment);
            
        } catch (\Exception $e) {
            Log::error("Failed to send availability selected notification: " . $e->getMessage(), [
                'availability_id' => $availability->id,
                'appointment_id' => $appointment->id
            ]);
        }
    }

    /**
     * Notify patient about availability selected
     */
    protected function notifyPatientAboutAvailabilitySelected(ProfessionalAvailability $availability, Appointment $appointment): void
    {
        try {
            $solicitation = $availability->solicitation;
            $patient = $solicitation->patient;
            
            if (!$patient) {
                return;
            }
            
            // Patients don't have user accounts for system notifications
            // They only receive WhatsApp notifications
            
            // Send WhatsApp notification
            try {
                $this->sendWhatsAppAppointmentScheduled($appointment);
            } catch (\Exception $whatsappError) {
                Log::error("Failed to send WhatsApp notification to patient for availability selected", [
                    'error' => $whatsappError->getMessage(),
                    'availability_id' => $availability->id,
                    'appointment_id' => $appointment->id,
                    'patient_id' => $patient->id
                ]);
            }
            
            Log::info("Sent availability selected WhatsApp notification to patient #{$patient->id} for appointment #{$appointment->id}");
            
        } catch (\Exception $e) {
            Log::error("Failed to notify patient about availability selected: " . $e->getMessage(), [
                'availability_id' => $availability->id,
                'appointment_id' => $appointment->id
            ]);
        }
    }

    /**
     * Notify health plan about availability selected
     */
    protected function notifyHealthPlanAboutAvailabilitySelected(ProfessionalAvailability $availability, Appointment $appointment): void
    {
        try {
            $solicitation = $availability->solicitation;
            $healthPlan = $solicitation->healthPlan;
            
            if (!$healthPlan) {
                return;
            }
            
            // Get health plan admin users
            $healthPlanAdmins = User::role('plan_admin')
                ->where('entity_type', 'App\\Models\\HealthPlan')
                ->where('entity_id', $healthPlan->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get();
            
            if ($healthPlanAdmins->isEmpty()) {
                return;
            }
            
            // Send system notification to health plan admins
            Notification::send($healthPlanAdmins, new AppointmentScheduled($appointment));
            
            // Send WhatsApp notification to health plan admins
            foreach ($healthPlanAdmins as $admin) {
                if ($admin->phone) {
                    try {
                        $solicitation = $availability->solicitation;
                        $patient = $solicitation->patient;
                        $procedure = $solicitation->tuss;
                        $provider = $availability->professional ?? $availability->clinic;
                        $scheduledDate = \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
                        
                        $this->whatsAppService->sendHealthPlanAvailabilitySelectedNotification(
                            $admin->name,
                            $patient->name,
                            $provider->name,
                            $procedure->description,
                            $scheduledDate,
                            $appointment->id,
                            $admin->phone
                        );
                    } catch (\Exception $whatsappError) {
                        Log::error("Failed to send WhatsApp notification to health plan admin for availability selected", [
                            'error' => $whatsappError->getMessage(),
                            'availability_id' => $availability->id,
                            'appointment_id' => $appointment->id,
                            'admin_id' => $admin->id
                        ]);
                    }
                }
            }
            
            Log::info("Sent availability selected notification to health plan #{$healthPlan->id} admins for appointment #{$appointment->id}");
            
        } catch (\Exception $e) {
            Log::error("Failed to notify health plan about availability selected: " . $e->getMessage(), [
                'availability_id' => $availability->id,
                'appointment_id' => $appointment->id
            ]);
        }
    }

    /**
     * Notify providers when their availabilities are rejected.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $rejectedAvailabilities
     * @return void
     */
    public function notifyAvailabilitiesRejected($rejectedAvailabilities): void
    {
        try {
            foreach ($rejectedAvailabilities as $availability) {
                // Get the provider user
                $providerUser = null;
                
                if ($availability->professional_id) {
                    $professional = $availability->professional;
                    $providerUser = User::where('entity_type', 'App\\Models\\Professional')
                        ->where('entity_id', $professional->id)
                        ->where('is_active', true)
                        ->first();
                } elseif ($availability->clinic_id) {
                    $clinic = $availability->clinic;
                    $providerUser = User::where('entity_type', 'App\\Models\\Clinic')
                        ->where('entity_id', $clinic->id)
                        ->where('is_active', true)
                        ->first();
                }
                
                if ($providerUser) {
                    // Send system notification
                    $providerUser->notify(new AvailabilityRejected($availability));
                    
                    // Send WhatsApp notification if available
                    if ($providerUser->phone) {
                        try {
                            $solicitation = $availability->solicitation;
                            $patient = $solicitation->patient;
                            $procedure = $solicitation->tuss;
                            $availableDate = \Carbon\Carbon::parse($availability->available_date)->format('d/m/Y');
                            
                            $this->whatsAppService->sendAvailabilityRejectedNotification(
                                $providerUser->name,
                                $patient->name,
                                $procedure->description,
                                $availableDate,
                                $availability->available_time,
                                $providerUser->phone
                            );
                        } catch (\Exception $whatsappError) {
                            Log::error("Failed to send WhatsApp notification for availability rejected", [
                                'error' => $whatsappError->getMessage(),
                                'availability_id' => $availability->id
                            ]);
                        }
                    }
                    
                    Log::info("Sent availability rejected notification for availability #{$availability->id} to provider #{$providerUser->id}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to send availability rejected notifications: " . $e->getMessage());
        }
    }

    /**
     * Notifica sobre a atualização de uma negociação
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationUpdated(Negotiation $negotiation): void
    {
        try {
            // Notificar o criador da negociação
            $creator = $negotiation->creator;
            if ($creator && $creator->is_active) {
                $this->create(
                    userId: $creator->id,
                    title: 'Negociação Atualizada',
                    body: "A negociação '{$negotiation->title}' foi atualizada com sucesso.",
                    type: 'negotiation_updated',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                        'updated_by' => Auth::user()->name,
                    ]
                );
            }
            
            Log::info("Sent negotiation updated notification for negotiation #{$negotiation->id}");
        } catch (\Exception $e) {
            Log::error('Error sending negotiation updated notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notifica sobre a geração de contrato
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyContractGenerated(Negotiation $negotiation): void
    {
        try {
            // Notificar o criador da negociação
            $creator = $negotiation->creator;
            if ($creator && $creator->is_active) {
                $this->create(
                    userId: $creator->id,
                    title: 'Contrato Gerado',
                    body: "O contrato para a negociação '{$negotiation->title}' foi gerado com sucesso.",
                    type: 'contract_generated',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                    ]
                );
            }
            
            // Notificar equipe comercial
            $this->sendToRole('commercial_manager', [
                'title' => 'Contrato Gerado',
                'body' => "Contrato gerado para a negociação #{$negotiation->id}",
                'action_link' => "/negotiations/{$negotiation->id}",
                'priority' => 'medium'
            ]);
            
            Log::info("Sent contract generated notification for negotiation #{$negotiation->id}");
        } catch (\Exception $e) {
            Log::error('Error sending contract generated notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Notifica sobre a formalização de uma negociação
     *
     * @param Negotiation $negotiation
     * @return void
     */
    public function notifyNegotiationFormalized(Negotiation $negotiation): void
    {
        try {
            // Notificar o criador da negociação
            $creator = $negotiation->creator;
            if ($creator && $creator->is_active) {
                $this->create(
                    userId: $creator->id,
                    title: 'Negociação Formalizada',
                    body: "A negociação '{$negotiation->title}' foi formalizada com sucesso.",
                    type: 'negotiation_formalized',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                    ]
                );
            }
            
            // Notificar entidade envolvida
            $entityType = $negotiation->negotiable_type;
            $entityId = $negotiation->negotiable_id;
            
            $entityUsers = User::where(function($query) use ($entityType, $entityId) {
                    $query->where('entity_type', $entityType)
                          ->where('entity_id', $entityId);
                })
                
                ->get();
                
            foreach ($entityUsers as $user) {
                // Filtrar por papéis relevantes
                if ($entityType === HealthPlan::class && !$user->hasRole('plan_admin')) {
                    continue;
                } elseif ($entityType === Professional::class && !$user->hasRole('professional')) {
                    continue;
                } elseif ($entityType === Clinic::class && !$user->hasRole('clinic_admin')) {
                    continue;
                }
                
                $this->create(
                    userId: $user->id,
                    title: 'Negociação Formalizada',
                    body: "A negociação '{$negotiation->title}' foi formalizada e está pronta para execução.",
                    type: 'negotiation_formalized',
                    data: [
                        'negotiation_id' => $negotiation->id,
                        'title' => $negotiation->title,
                    ]
                );
            }
            
            Log::info("Sent negotiation formalized notification for negotiation #{$negotiation->id}");
        } catch (\Exception $e) {
            Log::error('Error sending negotiation formalized notification', [
                'error' => $e->getMessage(),
                'negotiation_id' => $negotiation->id,
            ]);
        }
    }

    /**
     * Resend appointment notification to patient using the same template as initial notification.
     *
     * @param Appointment $appointment
     * @return bool
     */
    public function resendAppointmentNotificationToPatient(Appointment $appointment): bool
    {
        try {
            $patient = $appointment->solicitation->patient;
            
            if (!$patient) {
                Log::warning("No patient found for appointment #{$appointment->id}");
                return false;
            }
            
            // Use the same method as initial appointment notification
            $result = $this->whatsAppService->sendAppointmentNotificationToPatient(
                $patient,
                $appointment
            );
            
            if ($result) {
                Log::info("Resent WhatsApp appointment notification to patient #{$patient->id} for appointment #{$appointment->id}");
                return true;
            } else {
                Log::warning("Failed to resend WhatsApp appointment notification to patient #{$patient->id} for appointment #{$appointment->id}");
                return false;
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to resend appointment notification: " . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return false;
        }
    }
}
