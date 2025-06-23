<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportGenerationFailed extends Notification implements ShouldQueue
{
    use Queueable;

    protected $type;
    protected $error;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $type, string $error)
    {
        $this->type = $type;
        $this->error = $error;
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
        $reportTypes = config('reports.types');
        $reportName = $reportTypes[$this->type]['name'] ?? 'Relatório';

        return (new MailMessage)
            ->error()
            ->subject("{$reportName} - Falha na Geração")
            ->greeting("Olá {$notifiable->name}!")
            ->line("Houve um erro ao gerar seu {$reportName}.")
            ->line("Erro: {$this->error}")
            ->line('Por favor, tente novamente ou entre em contato com o suporte se o problema persistir.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $reportTypes = config('reports.types');
        $reportName = $reportTypes[$this->type]['name'] ?? 'Relatório';

        return [
            'title' => "{$reportName} - Falha na Geração",
            'message' => "Houve um erro ao gerar seu {$reportName}.",
            'error' => $this->error,
            'type' => 'report_generation_failed',
            'report_type' => $this->type
        ];
    }
} 