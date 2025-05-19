<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractExpirationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $contract;
    protected $isRecurring;

    /**
     * Create a new notification instance.
     */
    public function __construct(Contract $contract, bool $isRecurring = false)
    {
        $this->contract = $contract;
        $this->isRecurring = $isRecurring;
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
        $contractable = $this->contract->contractable;
        $expirationDate = $this->contract->end_date;
        $daysUntilExpiration = now()->diffInDays($expirationDate, false);
        
        $entityName = $contractable ? $contractable->name : 'Unknown Entity';
        $entityType = $this->contract->type;
        
        $subject = $this->isRecurring 
            ? 'URGENTE: Lembrete de Vencimento de Contrato' 
            : 'Alerta de Vencimento de Contrato';
            
        if ($daysUntilExpiration < 0) {
            $subject = 'CRÍTICO: Contrato Vencido';
        }

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Olá ' . $notifiable->name)
            ->line('Este é um ' . ($this->isRecurring ? 'lembrete' : 'aviso') . ' sobre um contrato ' . ($daysUntilExpiration < 0 ? 'vencido' : 'prestes a vencer') . '.')
            ->line('Contrato Nº: ' . $this->contract->contract_number)
            ->line('Entidade: ' . $entityName)
            ->line('Tipo: ' . ucfirst($entityType));
            
        if ($daysUntilExpiration < 0) {
            $message->line('Data de Vencimento: ' . $expirationDate->format('d/m/Y'))
                ->line('Dias desde o vencimento: ' . abs($daysUntilExpiration));
        } else {
            $message->line('Data de Vencimento: ' . $expirationDate->format('d/m/Y'))
                ->line('Dias até o vencimento: ' . $daysUntilExpiration);
        }

        if ($this->isRecurring) {
            $message->line('Este é um alerta recorrente porque o contrato ainda não foi renovado.');
        }

        $message->action('Visualizar Contrato', url('/contracts/' . $this->contract->id))
            ->line('Por favor, tome as medidas necessárias para renovar o contrato antes que ele expire.');

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $contractable = $this->contract->contractable;
        $expirationDate = $this->contract->end_date;
        $daysUntilExpiration = now()->diffInDays($expirationDate, false);
        
        $entityName = $contractable ? $contractable->name : 'Unknown Entity';
        
        return [
            'type' => 'contract_expiration',
            'contract_id' => $this->contract->id,
            'contract_number' => $this->contract->contract_number,
            'contractable_id' => $contractable ? $contractable->id : null,
            'contractable_type' => $this->contract->contractable_type,
            'entity_name' => $entityName,
            'entity_type' => $this->contract->type,
            'expiration_date' => $expirationDate->format('Y-m-d'),
            'days_until_expiration' => $daysUntilExpiration,
            'is_recurring' => $this->isRecurring,
            'is_expired' => $daysUntilExpiration < 0,
            'created_at' => now(),
        ];
    }
} 