# Usando Configurações do Sistema

Este documento explica como usar o sistema de configurações em sua aplicação.

## Classe Utilitária `Settings`

A classe `App\Services\Settings` fornece uma interface simples para acessar e modificar as configurações do sistema. Essa classe adiciona uma camada de cache para melhorar o desempenho, evitando consultas desnecessárias ao banco de dados.

## Métodos Disponíveis

### Obter uma Configuração

```php
use App\Services\Settings;

// Obter uma configuração genérica (tipo inferido)
$value = Settings::get('chave_da_configuracao');

// Com valor padrão caso a configuração não exista
$value = Settings::get('chave_da_configuracao', 'valor_padrao');

// Obter configuração específica por tipo
$boolValue = Settings::getBool('configuracao_booleana');
$intValue = Settings::getInt('configuracao_inteira');
$floatValue = Settings::getFloat('configuracao_decimal');
$arrayValue = Settings::getArray('configuracao_array');
```

### Verificar se uma Configuração Existe

```php
if (Settings::has('chave_da_configuracao')) {
    // A configuração existe
}
```

### Definir uma Configuração

```php
// Definir um valor (retorna true/false indicando sucesso)
$success = Settings::set('chave_da_configuracao', 'novo_valor');

// Definir com ID do usuário para rastreamento de alterações
$userId = auth()->id();
Settings::set('chave_da_configuracao', 'novo_valor', $userId);
```

### Obter um Grupo de Configurações

```php
// Obter todas as configurações do grupo 'pagamentos'
$paymentSettings = Settings::getGroup('pagamentos');

// Acessar valores individuais
$paymentEnabled = $paymentSettings['pagamento_ativado'];
$paymentMethods = $paymentSettings['metodos_pagamento'];
```

## Exemplos de Uso

### Em Controllers

```php
namespace App\Http\Controllers;

use App\Services\Settings;
use Illuminate\Http\Request;

class SchedulingController extends Controller
{
    public function index()
    {
        // Verificar se o agendamento automático está ativado
        if (!Settings::getBool('scheduling_enabled')) {
            return response()->json(['message' => 'O agendamento automático está desativado'], 403);
        }
        
        // Usar outras configurações
        $minDays = Settings::getInt('scheduling_min_days');
        $priority = Settings::get('scheduling_priority');
        
        // ...lógica de agendamento
    }
}
```

### Em Services

```php
namespace App\Services;

class AppointmentScheduler
{
    public function scheduleAppointment($solicitation)
    {
        // Verificar se o agendamento automático está ativado
        if (!Settings::getBool('scheduling_enabled')) {
            return false;
        }
        
        // Utilizar configurações para determinar o algoritmo
        $priority = Settings::get('scheduling_priority', 'balanced');
        
        switch ($priority) {
            case 'cost':
                return $this->scheduleByCost($solicitation);
            case 'distance':
                return $this->scheduleByDistance($solicitation);
            case 'availability':
                return $this->scheduleByAvailability($solicitation);
            default:
                return $this->scheduleBalanced($solicitation);
        }
    }
    
    // ... métodos de agendamento
}
```

### Em Jobs em Fila

```php
namespace App\Jobs;

use App\Services\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPaymentReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function handle()
    {
        // Obter configurações relevantes para o processo
        $reminderDays = Settings::getInt('payment_reminder_days', 3);
        $gracePeriod = Settings::getInt('payment_grace_period', 5);
        
        // ... lógica para enviar lembretes de pagamento
    }
}
```

### Em Blade Views (se necessário)

```php
@if(App\Services\Settings::getBool('feature_nova_ativada'))
    <div class="new-feature">
        <!-- Conteúdo do novo recurso -->
    </div>
@endif
```

## Melhores Práticas

1. **Agrupar configurações relacionadas**: Use grupos lógicos como 'pagamentos', 'agendamento', 'notificacoes', etc.

2. **Nomear configurações adequadamente**: Use nomes descritivos como `scheduling_enabled` em vez de `se`.

3. **Definir valores padrão**: Sempre forneça um valor padrão sensato ao usar o método `get()`.

4. **Definir restrições de tipos**: Ao criar novas configurações via API, especifique o tipo de dados correto.

5. **Manter configurações críticas protegidas**: Não permita a exclusão de configurações essenciais para o funcionamento do sistema.

## Cache

A classe `Settings` implementa caching automático para reduzir consultas ao banco de dados:

- Cada configuração é armazenada no cache por 1 hora
- O cache é automaticamente invalidado quando uma configuração é atualizada
- As configurações são agrupadas no cache para facilitar o acesso

Para limpar manualmente o cache de configurações (em caso de atualizações diretas no banco de dados), você pode usar:

```php
\Illuminate\Support\Facades\Cache::flush();
```

ou mais especificamente:

```php
\Illuminate\Support\Facades\Cache::forget('app_setting_' . $key);
``` 