<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Report;

class ScheduledReportAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    protected $report;

    /**
     * Create a new notification instance.
     */
    public function __construct(Report $report)
    {
        $this->report = $report;
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
        $reportName = $reportTypes[$this->report->type]['name'] ?? 'Relatório';

        return (new MailMessage)
            ->subject("{$reportName} Agendado - Disponível")
            ->greeting("Olá {$notifiable->name}!")
            ->line("Seu {$reportName} agendado '{$this->report->name}' está disponível.")
            ->line("Frequência: {$this->report->schedule_frequency}")
            ->line("Gerado em: " . $this->report->last_generated_at->format('d/m/Y H:i:s'))
            ->action('Baixar Relatório', url("/api/reports/{$this->report->id}/download"))
            ->line('O relatório ficará disponível por ' . config('reports.storage.retention_days') . ' dias.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $reportTypes = config('reports.types');
        $reportName = $reportTypes[$this->report->type]['name'] ?? 'Relatório';

        return [
            'title' => "{$reportName} Agendado - Disponível",
            'message' => "Seu {$reportName} agendado '{$this->report->name}' está disponível.",
            'action' => [
                'label' => 'Baixar Relatório',
                'url' => url("/api/reports/{$this->report->id}/download")
            ],
            'type' => 'scheduled_report_available',
            'report_id' => $this->report->id,
            'report_type' => $this->report->type,
            'report_name' => $this->report->name,
            'generated_at' => $this->report->last_generated_at->toIso8601String()
        ];
    }
} 