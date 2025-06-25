<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ReportGenerationFailed extends Notification
{
    use Queueable;

    protected $reportType;
    protected $errorMessage;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $reportType, string $errorMessage)
    {
        $this->reportType = $reportType;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('Falha na Geração do Relatório')
            ->line('Houve um erro ao gerar o relatório solicitado.')
            ->line('Tipo do relatório: ' . $this->reportType)
            ->line('Erro: ' . $this->errorMessage)
            ->line('Por favor, tente novamente ou entre em contato com o suporte se o problema persistir.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'report_type' => $this->reportType,
            'error_message' => $this->errorMessage,
            'type' => 'report_generation_failed'
        ];
    }
} 