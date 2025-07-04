<?php

namespace App\Notifications;

use App\Models\BillingBatch;
use App\Models\Appointment;
use App\Notifications\Messages\WhatsAppMessage;
use App\Notifications\Channels\WhatsAppChannel;
use App\Services\WhatsAppTemplateBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class BillingEmitted extends Notification implements ShouldQueue
{
    use Queueable;

    protected $billingBatch;
    protected $appointment;
    protected $templateBuilder;

    /**
     * Create a new notification instance.
     */
    public function __construct(BillingBatch $billingBatch, Appointment $appointment = null)
    {
        $this->billingBatch = $billingBatch;
        $this->appointment = $appointment;
        $this->templateBuilder = app(WhatsAppTemplateBuilder::class);
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
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
     */
    public function toMail($notifiable): MailMessage
    {
        $patientName = $this->appointment?->solicitation?->patient?->name ?? 'Paciente';
        $appointmentDate = $this->appointment?->scheduled_date ? 
            \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y') : 'Data não especificada';
        $billingUrl = "https://medlarsaude.com.br/conecta/health-plans/billing/{$this->billingBatch->id}";

        return (new MailMessage)
            ->subject('Nova Cobrança Emitida')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Uma nova cobrança foi emitida para o agendamento do paciente {$patientName} realizado em {$appointmentDate}.")
            ->line("Valor da cobrança: R$ " . number_format($this->billingBatch->total_amount, 2, ',', '.'))
            ->line("Período de referência: {$this->billingBatch->reference_period_start} a {$this->billingBatch->reference_period_end}")
            ->action('Ver Cobrança', $billingUrl)
            ->line('Clique no botão acima e confira as informações detalhadas da cobrança.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $patientName = $this->appointment?->solicitation?->patient?->name ?? 'Paciente';
        $appointmentDate = $this->appointment?->scheduled_date ? 
            \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y') : 'Data não especificada';

        return [
            'title' => 'Nova Cobrança Emitida',
            'body' => "Uma nova cobrança foi emitida para o agendamento do paciente {$patientName} realizado em {$appointmentDate}.",
            'action_url' => "/health-plans/billing/{$this->billingBatch->id}",
            'action_text' => 'Ver Cobrança',
            'icon' => 'dollar-sign',
            'type' => 'billing_emitted',
            'priority' => 'normal',
            'billing_batch_id' => $this->billingBatch->id,
            'appointment_id' => $this->appointment?->id,
            'patient_name' => $patientName,
            'appointment_date' => $appointmentDate,
            'total_amount' => $this->billingBatch->total_amount,
            'reference_period_start' => $this->billingBatch->reference_period_start,
            'reference_period_end' => $this->billingBatch->reference_period_end,
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp($notifiable): WhatsAppMessage
    {
        try {
            $recipientName = $notifiable->name ?? 'Usuário';
            $patientName = $this->appointment?->solicitation?->patient?->name ?? 'Paciente';
            $appointmentDate = $this->appointment?->scheduled_date ? 
                \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y') : 'Data não especificada';
            $billingId = (string) $this->billingBatch->id;

            $variables = $this->templateBuilder->buildBillingEmitted(
                $recipientName,
                $patientName,
                $appointmentDate,
                $billingId
            );

            return (new WhatsAppMessage)
                ->template('HX309e812864946c1b082a9b0b4ff58956')
                ->to($notifiable->phone)
                ->parameters($variables);

        } catch (\Exception $e) {
            Log::error('Error creating WhatsApp notification for billing emitted: ' . $e->getMessage(), [
                'billing_batch_id' => $this->billingBatch->id ?? 'unknown',
                'notifiable_id' => $notifiable->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            // Return null to skip WhatsApp notification if there's an error
            return null;
        }
    }
} 