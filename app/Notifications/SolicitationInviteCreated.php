<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppTemplateBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SolicitationInviteCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The solicitation data.
     *
     * @var array
     */
    protected $solicitationData;

    /**
     * The invite data.
     *
     * @var array
     */
    protected $inviteData;

    /**
     * Create a new notification instance.
     *
     * @param  array  $solicitationData
     * @param  array  $inviteData
     * @return void
     */
    public function __construct(array $solicitationData, array $inviteData)
    {
        $this->solicitationData = $solicitationData;
        $this->inviteData = $inviteData;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        $channels = ['database'];
        
        if ($notifiable->notificationChannelEnabled('email')) {
            $channels[] = 'mail';
        }
        
        if ($notifiable->notificationChannelEnabled('whatsapp')) {
            $channels[] = WhatsAppChannel::class;
        }
        
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        if (!$notifiable) {
            Log::warning('Cannot send mail notification: notifiable is null');
            return null;
        }

        try {
            return (new MailMessage)
                ->subject('Nova Solicitação de Agendamento')
                ->greeting('Olá ' . ($notifiable->name ?? 'Profissional') . '!')
                ->line('Você recebeu uma nova solicitação de agendamento.')
                ->line("Procedimento: " . ($this->solicitationData['tuss_description'] ?? 'Não especificado'))
                ->line("Paciente: " . ($this->solicitationData['patient_name'] ?? 'Não especificado'))
                ->line("Data Preferencial: " . ($this->solicitationData['preferred_date_start'] ?? 'Não definida'))
                ->action('Ver Solicitação', url("/solicitations/{$this->solicitationData['id']}"))
                ->line('Por favor, acesse o sistema para responder a esta solicitação.');
        } catch (\Exception $e) {
            Log::error('Error creating mail notification: ' . $e->getMessage(), [
                'solicitation_id' => $this->solicitationData['id'] ?? 'unknown',
                'notifiable_id' => $notifiable->id ?? 'unknown'
            ]);
            return null;
        }
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \App\Notifications\Messages\WhatsAppMessage|null
     */
    public function toWhatsApp($notifiable)
    {
        try {
            // Check if notifiable has a phone number
            if (!$notifiable) {
                Log::warning('Cannot send WhatsApp notification: notifiable is null');
                return null;
            }
            
            if (!isset($notifiable->phone) || empty(trim($notifiable->phone))) {
                Log::warning('Cannot send WhatsApp notification: no phone number available', [
                    'notifiable_id' => $notifiable->id ?? 'unknown',
                    'notifiable_type' => get_class($notifiable),
                    'phone' => $notifiable->phone ?? 'null'
                ]);
                return null;
            }
            
            $phone = trim($notifiable->phone);
            if (empty($phone)) {
                Log::warning('Cannot send WhatsApp notification: phone number is empty after trimming', [
                    'notifiable_id' => $notifiable->id ?? 'unknown',
                    'notifiable_type' => get_class($notifiable)
                ]);
                return null;
            }
            
            $tuss = $this->solicitationData['tuss'];
            $patient = $this->solicitationData['patient'];
            $provider = $this->inviteData['provider'];
            
            if (!$provider || !$provider->user) {
                Log::warning('Cannot send WhatsApp notification: provider or provider user is null', [
                    'invite_id' => $this->inviteData['id'] ?? 'unknown',
                    'provider_id' => $provider->id ?? 'unknown'
                ]);
                return null;
            }

            if (!$patient) {
                Log::warning('Cannot send WhatsApp notification: patient is null', [
                    'solicitation_id' => $this->solicitationData['id'] ?? 'unknown'
                ]);
                return null;
            }
            
            $message = new WhatsAppMessage();
            $message->to($phone);
            $message->templateName = 'solicitation_invite';
            $message->variables = [
                '1' => $provider->user->name ?? 'Profissional',
                '2' => $tuss->description ?? 'Procedimento não especificado',
                '3' => $patient->name ?? 'Paciente não especificado',
                '4' => $this->solicitationData['preferred_date_start'] ?? 'Data não definida',
                '5' => $this->solicitationData['id']
            ];
            
            return $message;
        } catch (\Exception $e) {
            Log::error('Error creating WhatsApp notification: ' . $e->getMessage(), [
                'solicitation_id' => $this->solicitationData['id'] ?? 'unknown',
                'notifiable_id' => $notifiable->id ?? 'unknown'
            ]);
            return null;
        }
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        try {
            $tuss = $this->solicitationData['tuss'];
            $patient = $this->solicitationData['patient'];
            $preferredDateStart = $this->solicitationData['preferred_date_start'] 
                ? (new \DateTime($this->solicitationData['preferred_date_start']))->format('Y-m-d')
                : null;
            
            return [
                'title' => 'Nova Solicitação de Agendamento',
                'body' => "Você recebeu uma nova solicitação de agendamento para " . ($tuss->description ?? 'procedimento não especificado'),
                'action_link' => "/solicitations/{$this->solicitationData['id']}",
                'action_text' => 'Ver Solicitação',
                'icon' => 'calendar',
                'type' => 'solicitation_invite',
                'data' => [
                    'solicitation_id' => $this->solicitationData['id'],
                    'invite_id' => $this->inviteData['id'],
                    'procedure_name' => $tuss->description ?? 'Não especificado',
                    'patient_name' => $patient->name ?? 'Não especificado',
                    'preferred_date' => $preferredDateStart
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error creating array notification: ' . $e->getMessage(), [
                'solicitation_id' => $this->solicitationData['id'] ?? 'unknown',
                'notifiable_id' => $notifiable->id ?? 'unknown'
            ]);
            return [
                'title' => 'Nova Solicitação de Agendamento',
                'body' => 'Você recebeu uma nova solicitação de agendamento',
                'type' => 'solicitation_invite',
                'error' => true
            ];
        }
    }
} 