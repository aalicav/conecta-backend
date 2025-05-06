<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Contract;

class ContractSignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $contract;

    /**
     * Create a new notification instance.
     *
     * @param Contract $contract
     * @return void
     */
    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $contractEntity = $this->contract->contractable ? $this->contract->contractable->name : 'Unknown';
        $contractType = ucfirst($this->contract->type);
        
        return (new MailMessage)
            ->subject("Contrato {$this->contract->contract_number} foi assinado")
            ->greeting("Olá {$notifiable->name},")
            ->line("O contrato {$this->contract->contract_number} com {$contractEntity} ({$contractType}) foi assinado por todas as partes.")
            ->line("O contrato agora está ativo e o documento assinado está disponível no sistema.")
            ->action('Ver Contrato', url("/dashboard/contracts/{$this->contract->id}"))
            ->line('Obrigado por usar nosso aplicativo!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $contractEntity = $this->contract->contractable ? $this->contract->contractable->name : 'Unknown';
        
        return [
            'contract_id' => $this->contract->id,
            'contract_number' => $this->contract->contract_number,
            'entity_name' => $contractEntity,
            'entity_type' => $this->contract->type,
            'message' => "Contrato {$this->contract->contract_number} com {$contractEntity} foi assinado",
            'signed_at' => $this->contract->signed_at->format('Y-m-d H:i:s'),
        ];
    }
} 