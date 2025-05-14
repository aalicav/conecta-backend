<?php

namespace App\Notifications;

use App\Models\Professional;
use App\Models\Contract;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProfessionalContractLinked extends Notification implements ShouldQueue
{
    use Queueable;

    protected $professional;
    protected $contract;
    protected $procedures;

    /**
     * Create a new notification instance.
     */
    public function __construct(Professional $professional, Contract $contract, array $procedures)
    {
        $this->professional = $professional;
        $this->contract = $contract;
        $this->procedures = $procedures;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
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
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Credenciamento Concluído - Bem-vindo à Rede Medilar')
            ->greeting("Olá {$this->professional->name}!")
            ->line('Temos o prazer de informar que seu credenciamento na Medilar foi concluído com sucesso.')
            ->line('Você agora está habilitado para atender pacientes através da nossa rede.')
            ->line('Detalhes do seu credenciamento:')
            ->line("- Tipo de Prestador: {$this->professional->professional_type}")
            ->line("- Especialidade Principal: {$this->professional->specialty}");

        // Adicionar informações sobre operadoras vinculadas
        $mail->line('Operadoras de Saúde vinculadas:');
        $healthPlans = $this->contract->healthPlans;
        foreach ($healthPlans as $healthPlan) {
            $mail->line("- {$healthPlan->name}");
        }

        // Adicionar informações sobre procedimentos habilitados
        $mail->line('Principais grupos de procedimentos habilitados:');
        $procedureGroups = collect($this->procedures)->groupBy('category')->take(5);
        foreach ($procedureGroups as $category => $procedures) {
            $mail->line("- {$category}");
        }

        // Adicionar informações sobre próximos passos
        $mail->line('')
            ->line('Próximos Passos:')
            ->line('1. Você receberá solicitações de agendamento através do nosso sistema')
            ->line('2. Confirme os agendamentos e realize os atendimentos')
            ->line('3. Registre a conclusão dos atendimentos no sistema')
            ->line('4. Emita a nota fiscal conforme orientações que serão enviadas')
            ->line('')
            ->line('Informações Importantes:')
            ->line('- Mantenha seus dados cadastrais sempre atualizados')
            ->line('- Em caso de dúvidas, nossa equipe de suporte está à disposição')
            ->line('')
            ->action('Acessar Sistema', url('/login'))
            ->line('Contatos:')
            ->line('Email: ' . config('app.support_email'))
            ->line('Telefone: ' . config('app.support_phone'));

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Credenciamento Concluído',
            'body' => "Seu credenciamento na Medilar foi concluído com sucesso. Bem-vindo à nossa rede!",
            'action_link' => "/professionals/{$this->professional->id}",
            'icon' => 'check-circle',
            'professional_id' => $this->professional->id,
            'professional_name' => $this->professional->name,
            'professional_type' => $this->professional->professional_type,
            'contract_id' => $this->contract->id,
            'health_plans' => $this->contract->healthPlans->pluck('name'),
            'procedure_count' => count($this->procedures)
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp(object $notifiable): array
    {
        $healthPlans = $this->contract->healthPlans->pluck('name')->implode(', ');
        
        return [
            'template' => 'professional_contract_linked',
            'params' => [
                'professional_name' => $this->professional->name,
                'professional_type' => $this->professional->professional_type,
                'health_plans' => $healthPlans,
                'procedure_count' => count($this->procedures),
                'action_url' => url('/login'),
                'support_email' => config('app.support_email'),
                'support_phone' => config('app.support_phone')
            ]
        ];
    }
} 