<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentAnalysisRequired extends Notification implements ShouldQueue
{
    use Queueable;

    protected $entity;
    protected $entityType;

    public function __construct($entity, string $entityType)
    {
        $this->entity = $entity;
        $this->entityType = $entityType;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entityName = $this->entityType === 'professional' ? 'professional' : 'clinic';
        $name = $this->entity->name;
        $route = $this->entityType === 'professional' ? 'professionals' : 'clinics';
        
        return (new MailMessage)
            ->subject("Análise de Documentos Necessária - {$name}")
            ->greeting('Olá!')
            ->line("Novos documentos de {$entityName} requerem análise jurídica.")
            ->line("Nome: {$name}")
            ->action('Analisar Documentos', url("/{$route}/{$this->entity->id}/documents"))
            ->line('Por favor, analise os documentos submetidos.');
    }

    public function toArray(object $notifiable): array
    {
        $entityName = $this->entityType === 'professional' ? 'profissional' : 'clínica';
        $route = $this->entityType === 'professional' ? 'professionals' : 'clinics';
        
        return [
            'title' => 'Análise de Documentos Necessária',
            'message' => "Os documentos do(a) {$entityName} {$this->entity->name} requerem análise",
            'action_link' => "/{$route}/{$this->entity->id}/documents",
            'entity_id' => $this->entity->id,
            'entity_type' => $this->entityType,
            'entity_name' => $this->entity->name,
            'icon' => 'file-text'
        ];
    }
} 