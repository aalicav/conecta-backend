<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Clinic;

class NewClinicRegistered extends Notification implements ShouldQueue
{
    use Queueable;

    protected $clinic;

    public function __construct(Clinic $clinic)
    {
        $this->clinic = $clinic;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nova Clínica Cadastrada - Análise Necessária')
            ->greeting('Olá!')
            ->line('Uma nova clínica foi cadastrada e requer análise comercial.')
            ->line('Detalhes da clínica:')
            ->line("Nome: {$this->clinic->name}")
            ->line("CNPJ: {$this->clinic->cnpj}")
            ->line("Cidade: {$this->clinic->city}")
            ->action('Ver Clínica', url("/clinics/{$this->clinic->id}"))
            ->line('Por favor, analise os documentos e inicie o processo de negociação.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Nova Clínica Cadastrada',
            'message' => "A clínica {$this->clinic->name} foi cadastrada e requer análise",
            'action_link' => "/clinics/{$this->clinic->id}",
            'clinic_id' => $this->clinic->id,
            'clinic_name' => $this->clinic->name,
            'icon' => 'building'
        ];
    }
} 