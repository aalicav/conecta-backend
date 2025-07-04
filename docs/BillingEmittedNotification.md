# Notificação de Cobrança Emitida

Esta documentação descreve como usar a notificação `BillingEmitted` que é enviada quando uma cobrança é emitida.

## Visão Geral

A notificação `BillingEmitted` é enviada automaticamente quando um lote de cobrança é criado, informando aos usuários relevantes sobre a nova cobrança emitida. A notificação inclui:

- **WhatsApp**: Usando o template `HX309e812864946c1b082a9b0b4ff58956`
- **Email**: Notificação por email com detalhes da cobrança
- **Database**: Notificação interna no sistema

## Template do WhatsApp

### SID do Template
```
HX309e812864946c1b082a9b0b4ff58956
```

### Corpo da Mensagem
```
Olá, {{1}}! Uma nova cobrança foi emitida para o agendamento do paciente {{2}} realizado no em {{3}}. Clique no botão abaixo e confira as informações.
```

### Link
```
https://medlarsaude.com.br/conecta/health-plans/billing/{{4}}
```

### Variáveis
- `{{1}}`: Nome do destinatário
- `{{2}}`: Nome do paciente
- `{{3}}`: Data do agendamento (formato dd/mm/yyyy)
- `{{4}}`: ID da cobrança

## Como Usar

### 1. Via API

```php
// Enviar notificação via API
$response = Http::post('/api/billing/billing-emitted-notification', [
    'billing_batch_id' => 123,
    'recipient_ids' => [1, 2, 3] // IDs dos usuários que receberão a notificação
]);
```

### 2. Via Controller

```php
use App\Notifications\BillingEmitted;
use App\Models\BillingBatch;
use App\Models\User;

// Buscar o lote de cobrança
$billingBatch = BillingBatch::with(['items.appointment.solicitation.patient'])->find(123);

// Buscar o primeiro agendamento do lote
$firstItem = $billingBatch->items->first();
$appointment = $firstItem ? $firstItem->appointment : null;

// Buscar destinatários
$recipients = User::role('plan_admin')->get();

// Enviar notificação
foreach ($recipients as $recipient) {
    $recipient->notify(new BillingEmitted($billingBatch, $appointment));
}
```

### 3. Via Comando Artisan

```bash
# Enviar para todos os usuários com papel plan_admin
php artisan billing:send-emitted-notification 123

# Enviar para usuários específicos
php artisan billing:send-emitted-notification 123 --recipients=1,2,3
```

## Estrutura da Notificação

### Classe
```php
App\Notifications\BillingEmitted
```

### Construtor
```php
public function __construct(BillingBatch $billingBatch, Appointment $appointment = null)
```

### Parâmetros
- `$billingBatch`: O lote de cobrança que foi emitido
- `$appointment`: O agendamento relacionado (opcional)

## Canais de Entrega

A notificação é enviada através dos seguintes canais:

1. **Database**: Sempre enviada para notificações internas
2. **Email**: Se o usuário tiver email habilitado
3. **WhatsApp**: Se o usuário tiver WhatsApp habilitado

## Configuração

### Habilitar/Desabilitar Canais

Os usuários podem configurar quais canais de notificação desejam receber através do método `notificationChannelEnabled()`:

```php
// Verificar se email está habilitado
if ($notifiable->notificationChannelEnabled('email')) {
    // Enviar email
}

// Verificar se WhatsApp está habilitado
if ($notifiable->notificationChannelEnabled('whatsapp')) {
    // Enviar WhatsApp
}
```

### Template Builder

A notificação usa o `WhatsAppTemplateBuilder` para construir as variáveis do template:

```php
$variables = $this->templateBuilder->buildBillingEmitted(
    $recipientName,
    $patientName,
    $appointmentDate,
    $billingId
);
```

## Exemplo de Uso Completo

```php
<?php

namespace App\Http\Controllers;

use App\Models\BillingBatch;
use App\Models\User;
use App\Notifications\BillingEmitted;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function generateBatch(Request $request)
    {
        // ... lógica para gerar o lote de cobrança ...
        
        $billingBatch = BillingBatch::create([
            'entity_type' => 'health_plan',
            'entity_id' => $request->operator_id,
            'reference_period_start' => $request->reference_period_start,
            'reference_period_end' => $request->reference_period_end,
            'total_amount' => $totalAmount,
            'status' => 'pending',
            'created_by' => auth()->id()
        ]);
        
        // ... adicionar itens ao lote ...
        
        // Enviar notificação
        $this->sendBillingEmittedNotification($billingBatch);
        
        return response()->json([
            'message' => 'Lote de cobrança gerado com sucesso',
            'data' => $billingBatch
        ]);
    }
    
    private function sendBillingEmittedNotification(BillingBatch $billingBatch)
    {
        // Buscar destinatários (usuários com papel plan_admin)
        $recipients = User::role('plan_admin')->get();
        
        // Buscar o primeiro agendamento do lote
        $firstItem = $billingBatch->items->first();
        $appointment = $firstItem ? $firstItem->appointment : null;
        
        // Enviar notificação para cada destinatário
        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new BillingEmitted($billingBatch, $appointment));
            } catch (\Exception $e) {
                \Log::error('Error sending billing emitted notification', [
                    'recipient_id' => $recipient->id,
                    'billing_batch_id' => $billingBatch->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
```

## Tratamento de Erros

A notificação inclui tratamento de erros robusto:

1. **Logs de erro**: Todos os erros são registrados no log
2. **Fallback**: Se o WhatsApp falhar, a notificação ainda é enviada por email e database
3. **Validação**: Verifica se o usuário tem telefone antes de tentar enviar WhatsApp

## Monitoramento

Para monitorar o envio das notificações, verifique:

1. **Logs do Laravel**: `storage/logs/laravel.log`
2. **Tabela de notificações**: `notifications`
3. **Logs do WhatsApp**: Verificar se as mensagens foram entregues

## Personalização

Para personalizar a notificação:

1. **Template do WhatsApp**: Atualizar o SID do template no método `toWhatsApp()`
2. **Email**: Modificar o método `toMail()` para alterar o conteúdo do email
3. **Database**: Ajustar o método `toArray()` para modificar os dados armazenados 