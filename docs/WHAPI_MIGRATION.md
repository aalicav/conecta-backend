# Migração do Twilio para Whapi API

## Visão Geral

Este documento descreve a migração completa do sistema de WhatsApp do Twilio para a API Whapi. A migração foi projetada para manter a compatibilidade com o código existente enquanto aproveita os recursos da nova API.

## Mudanças Implementadas

### 1. Dependências

**Removido:**
- `twilio/sdk` - SDK oficial do Twilio

**Adicionado:**
- `guzzlehttp/guzzle` - Cliente HTTP para comunicação com a API Whapi

### 2. Arquivos Criados/Modificados

#### Novos Arquivos:
- `app/Services/WhapiWhatsAppService.php` - Novo serviço principal
- `config/whapi.php` - Configuração específica do Whapi

#### Arquivos Modificados:
- `composer.json` - Dependências atualizadas
- `config/services.php` - Configuração do Twilio removida
- `app/Providers/WhatsAppServiceProvider.php` - Registro do novo serviço
- `app/Notifications/Channels/WhatsAppChannel.php` - Uso do novo serviço
- `app/Http/Controllers/Api/WhatsappController.php` - Controller atualizado
- `app/Http/Controllers/Api/BidirectionalMessageController.php` - Controller bidirecional atualizado

### 3. Configuração

#### Variáveis de Ambiente Necessárias:

```env
# Whapi WhatsApp API Configuration
WHAPI_API_KEY=your_whapi_api_key_here
WHAPI_BASE_URL=https://gate.whapi.cloud
WHAPI_WEBHOOK_URL=https://your-domain.com/api/whatsapp/webhook
WHAPI_FROM_NUMBER=system
WHAPI_DEFAULT_PREVIEW_URL=true
WHAPI_MESSAGE_TIMEOUT=30
WHAPI_RETRY_ATTEMPTS=3
WHAPI_MAX_FILE_SIZE=16777216
WHAPI_DEFAULT_LANGUAGE=pt_BR
WHAPI_TEMPLATE_NAMESPACE=
WHAPI_WEBHOOK_VERIFY_TOKEN=your_webhook_verify_token
WHAPI_WEBHOOK_SECRET=your_webhook_secret
WHAPI_RATE_LIMIT_MESSAGES_PER_MINUTE=60
WHAPI_RATE_LIMIT_MESSAGES_PER_HOUR=1000
WHAPI_RATE_LIMIT_MESSAGES_PER_DAY=10000
WHAPI_LOG_LEVEL=info
WHAPI_LOG_REQUESTS=true
WHAPI_LOG_RESPONSES=true
WHAPI_LOG_WEBHOOKS=true
```

## Funcionalidades Implementadas

### 1. Envio de Mensagens

#### Texto Simples:
```php
$result = $whatsappService->sendTextMessage($phone, $message);
```

#### Mídia:
```php
$result = $whatsappService->sendMediaMessage($phone, $mediaUrl, $mediaType, $caption);
```

#### Templates:
```php
$result = $whatsappService->sendTemplateMessage($phone, $templateName, $parameters);
```

### 2. Recebimento de Mensagens

O sistema processa webhooks da API Whapi e salva as mensagens recebidas na tabela `messages`.

### 3. Histórico de Conversas

- `getConversations()` - Lista todas as conversas
- `getConversationHistory()` - Histórico de uma conversa específica

### 4. Identificação de Entidades

O sistema identifica automaticamente pacientes, profissionais e clínicas baseado no número de telefone.

## Diferenças da API Twilio

### 1. Arquitetura

**Twilio:**
- Usa sistema de conversas (Conversations)
- Requer criação e gerenciamento de conversas
- Sistema mais complexo para mensagens bidirecionais

**Whapi:**
- API mais direta e simples
- Não requer gerenciamento de conversas
- Mensagens são enviadas diretamente para números

### 2. Webhooks

**Twilio:**
- Webhooks específicos para conversas
- Formato de dados complexo

**Whapi:**
- Webhooks padronizados para mensagens
- Formato de dados mais simples e direto

### 3. Limites de Taxa

**Twilio:**
- Limites baseados no plano contratado
- Sistema de filas integrado

**Whapi:**
- Limites configuráveis
- Sistema de retry configurável

## Compatibilidade

### 1. Métodos Legacy

O novo serviço mantém compatibilidade com métodos existentes:

```php
// Métodos antigos (Twilio) - Funcionam como aliases
$service->sendConversationMessage($phone, $content, $author);
$service->sendMessageViaConversations($phone, $content);
$service->getOrCreateConversation($phone);
```

### 2. Notificações

O sistema de notificações existente continua funcionando sem modificações.

### 3. Controllers

Todos os endpoints da API continuam funcionando com a mesma interface.

## Configuração da API Whapi

### 1. Obter API Key

1. Acesse [Whapi Cloud](https://whapi.readme.io/)
2. Crie uma conta ou faça login
3. Obtenha sua API key no dashboard

### 2. Configurar Webhook

1. Configure a URL do webhook: `https://seu-dominio.com/api/whatsapp/webhook`
2. Configure os eventos que deseja receber
3. Configure o token de verificação

### 3. Testar Integração

Use os endpoints de teste para verificar se a integração está funcionando:

```bash
# Teste de mensagem simples
POST /api/whatsapp/test/simple
{
    "recipient": "5511999999999",
    "template_key": "test_message"
}

# Teste de template
POST /api/whatsapp/test/template
{
    "recipient": "5511999999999",
    "template_key": "appointment_confirmation",
    "values": ["João Silva", "15/01/2024", "14:00"]
}
```

## Monitoramento e Logs

### 1. Logs de Envio

```php
Log::info('Sending WhatsApp message via Whapi', [
    'phone' => $phone,
    'message' => $message,
    'options' => $options,
]);
```

### 2. Logs de Resposta

```php
Log::info('Whapi API response', [
    'phone' => $phone,
    'response' => $responseData,
    'status_code' => $response->getStatusCode(),
]);
```

### 3. Logs de Erro

```php
Log::error('Failed to send WhatsApp message via Whapi', [
    'phone' => $phone,
    'message' => $message,
    'error' => $e->getMessage(),
]);
```

## Troubleshooting

### 1. Erro de Autenticação

- Verifique se `WHAPI_API_KEY` está configurado corretamente
- Verifique se a API key tem permissões adequadas

### 2. Erro de Webhook

- Verifique se a URL do webhook está acessível publicamente
- Verifique se o token de verificação está configurado
- Verifique os logs para identificar problemas específicos

### 3. Mensagens Não Enviadas

- Verifique se o número de telefone está no formato correto
- Verifique se há limites de taxa sendo excedidos
- Verifique os logs de erro para detalhes

### 4. Mensagens Não Recebidas

- Verifique se o webhook está configurado corretamente
- Verifique se os eventos estão habilitados
- Verifique se a URL do webhook está acessível

## Próximos Passos

### 1. Testes

- Execute testes unitários para verificar a funcionalidade
- Teste o envio de mensagens para números reais
- Teste o recebimento de webhooks

### 2. Monitoramento

- Configure alertas para falhas de envio
- Monitore o uso da API e limites de taxa
- Configure dashboards para métricas de uso

### 3. Otimizações

- Implemente cache para mensagens frequentes
- Configure filas para mensagens em lote
- Implemente retry automático para falhas

## Suporte

Para suporte técnico:

1. Consulte a [documentação oficial da Whapi](https://whapi.readme.io/)
2. Verifique os logs do sistema para erros específicos
3. Entre em contato com a equipe de desenvolvimento

## Conclusão

A migração para a API Whapi oferece:

- **Simplicidade**: API mais direta e fácil de usar
- **Flexibilidade**: Configurações personalizáveis
- **Compatibilidade**: Mantém funcionalidades existentes
- **Escalabilidade**: Melhor controle sobre limites e taxas
- **Custo**: Potencialmente mais econômico que o Twilio

O sistema está preparado para funcionar com a nova API mantendo toda a funcionalidade existente.
