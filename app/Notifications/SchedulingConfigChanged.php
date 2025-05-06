<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SchedulingConfigChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The changed settings.
     *
     * @var array
     */
    protected $changes;

    /**
     * The user who made the changes.
     *
     * @var \App\Models\User
     */
    protected $changedBy;

    /**
     * Create a new notification instance.
     *
     * @param  array  $changes
     * @param  \App\Models\User  $changedBy
     * @return void
     */
    public function __construct(array $changes, $changedBy)
    {
        $this->changes = $changes;
        $this->changedBy = $changedBy;
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
        $mail = (new MailMessage)
            ->subject('Configurações de Agendamento Alteradas')
            ->greeting('Olá ' . $notifiable->name . ',')
            ->line('As configurações de agendamento automático foram alteradas por ' . $this->changedBy->name . '.');
        
        foreach ($this->changes as $setting => $value) {
            $label = $this->getSettingLabel($setting);
            $valueFormatted = $this->formatSettingValue($setting, $value);
            $mail->line($label . ': ' . $valueFormatted);
        }
        
        return $mail->action('Ver Configurações', url('/admin/scheduling'))
            ->line('Obrigado por usar nossa plataforma!');
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return string|array
     */
    public function toWhatsApp($notifiable)
    {
        $text = "Configurações de agendamento alteradas por {$this->changedBy->name}.\n\n";
        
        foreach ($this->changes as $setting => $value) {
            $label = $this->getSettingLabel($setting);
            $valueFormatted = $this->formatSettingValue($setting, $value);
            $text .= "{$label}: {$valueFormatted}\n";
        }
        
        return $text;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'title' => 'Configurações de Agendamento Alteradas',
            'icon' => 'cogs',
            'type' => 'scheduling_config_changed',
            'changed_by' => $this->changedBy->id,
            'changed_by_name' => $this->changedBy->name,
            'changes' => $this->changes,
            'message' => 'Configurações de agendamento alteradas por ' . $this->changedBy->name,
            'link' => '/admin/scheduling'
        ];
    }

    /**
     * Get the setting label.
     *
     * @param  string  $setting
     * @return string
     */
    protected function getSettingLabel(string $setting): string
    {
        $labels = [
            'scheduling_enabled' => 'Agendamento Automático',
            'scheduling_priority' => 'Prioridade de Agendamento',
            'scheduling_min_days' => 'Mínimo de Dias de Antecedência',
            'allow_manual_override' => 'Permitir Substituição Manual'
        ];

        return $labels[$setting] ?? $setting;
    }

    /**
     * Format the setting value for display.
     *
     * @param  string  $setting
     * @param  mixed  $value
     * @return string
     */
    protected function formatSettingValue(string $setting, $value): string
    {
        if ($setting === 'scheduling_enabled' || $setting === 'allow_manual_override') {
            return $value ? 'Ativado' : 'Desativado';
        }

        if ($setting === 'scheduling_priority') {
            $priorities = [
                'cost' => 'Custo',
                'distance' => 'Distância',
                'availability' => 'Disponibilidade',
                'balanced' => 'Balanceado'
            ];

            return $priorities[$value] ?? $value;
        }

        if ($setting === 'scheduling_min_days') {
            return $value . ' dias';
        }

        return (string) $value;
    }
} 