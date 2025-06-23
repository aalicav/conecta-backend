<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportGenerated extends Notification implements ShouldQueue
{
    use Queueable;

    protected $type;
    protected $filePath;
    protected $downloadUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $type, string $filePath, string $downloadUrl)
    {
        $this->type = $type;
        $this->filePath = $filePath;
        $this->downloadUrl = $downloadUrl;
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
            ->subject("{$reportName} - Gerado com Sucesso")
            ->greeting("Olá {$notifiable->name}!")
            ->line("Seu {$reportName} foi gerado com sucesso.")
            ->action('Baixar Relatório', $this->downloadUrl)
            ->line('O link para download ficará disponível por ' . config('reports.storage.retention_days') . ' dias.');
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
            'title' => "{$reportName} - Gerado com Sucesso",
            'message' => "Seu {$reportName} está pronto para download.",
            'action' => [
                'label' => 'Baixar Relatório',
                'url' => $this->downloadUrl
            ],
            'type' => 'report_generated',
            'report_type' => $this->type,
            'file_path' => $this->filePath
        ];
    }
} 