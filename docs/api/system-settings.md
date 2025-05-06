# API de Configurações do Sistema (System Settings)

Esta API permite gerenciar as configurações do sistema de forma centralizada. Todas as configurações são armazenadas
no banco de dados e podem ser acessadas por diferentes partes da aplicação.

## Modelos de dados

### SystemSetting

| Campo       | Tipo    | Descrição                                                  |
|-------------|---------|-----------------------------------------------------------|
| id          | integer | ID único da configuração                                   |
| key         | string  | Chave única para identificar a configuração                |
| value       | text    | Valor da configuração (sempre armazenado como texto)       |
| group       | string  | Grupo da configuração (ex: 'scheduling', 'payment')        |
| description | text    | Descrição da configuração                                  |
| is_public   | boolean | Se a configuração é pública ou privada                     |
| data_type   | string  | Tipo de dado (string, boolean, integer, float, array, json)|
| updated_by  | integer | ID do usuário que atualizou a configuração pela última vez |
| created_at  | datetime| Data de criação                                            |
| updated_at  | datetime| Data da última atualização                                 |

## Endpoints

### Listar configurações

Retorna todas as configurações que o usuário tem permissão para visualizar.

```
GET /api/system-settings
```

**Parâmetros de consulta:**

| Parâmetro | Tipo    | Descrição                                      |
|-----------|---------|------------------------------------------------|
| group     | string  | Filtrar configurações por grupo (opcional)     |
| public    | boolean | Filtrar por configurações públicas (opcional)  |

**Resposta:**

```json
{
  "settings": [
    {
      "id": 1,
      "key": "scheduling_enabled",
      "value": "true",
      "group": "scheduling",
      "description": "Enable or disable automatic scheduling",
      "is_public": true,
      "data_type": "boolean",
      "updated_by": null,
      "created_at": "2023-07-01T12:00:00.000000Z",
      "updated_at": "2023-07-01T12:00:00.000000Z"
    },
    // ... outras configurações
  ]
}
```

### Obter configuração específica

Retorna uma configuração específica com base na chave.

```
GET /api/system-settings/{key}
```

**Resposta:**

```json
{
  "setting": {
    "key": "scheduling_enabled",
    "value": true,
    "group": "scheduling",
    "description": "Enable or disable automatic scheduling",
    "is_public": true,
    "data_type": "boolean"
  }
}
```

Observe que o valor é convertido para o tipo de dado apropriado (boolean, integer, etc.).

### Obter configurações por grupo

Retorna todas as configurações de um grupo específico.

```
GET /api/system-settings/group/{group}
```

**Resposta:**

```json
{
  "group": "scheduling",
  "settings": {
    "scheduling_enabled": true,
    "scheduling_priority": "balanced",
    "scheduling_min_days": 2,
    "scheduling_allow_manual_override": true
  }
}
```

### Criar configuração

Cria uma nova configuração no sistema.

```
POST /api/system-settings/create
```

**Corpo da requisição:**

```json
{
  "key": "new_setting",
  "value": "new_value",
  "group": "general",
  "description": "A new setting",
  "is_public": false,
  "data_type": "string"
}
```

**Resposta (201 Created):**

```json
{
  "message": "Setting created successfully",
  "setting": {
    "key": "new_setting",
    "value": "new_value",
    "group": "general",
    "description": "A new setting",
    "is_public": false,
    "data_type": "string"
  }
}
```

### Atualizar configuração

Atualiza uma configuração existente.

```
PUT /api/system-settings/{key}
```

**Corpo da requisição:**

```json
{
  "value": "updated_value",
  "is_public": true,
  "description": "Updated description"
}
```

**Resposta:**

```json
{
  "message": "Setting updated successfully",
  "setting": {
    "key": "scheduling_enabled",
    "value": "updated_value",
    "group": "scheduling",
    "description": "Updated description",
    "is_public": true,
    "data_type": "boolean"
  }
}
```

### Atualizar múltiplas configurações

Atualiza várias configurações de uma só vez.

```
POST /api/system-settings
```

**Corpo da requisição:**

```json
{
  "settings": {
    "scheduling_enabled": false,
    "scheduling_priority": "cost",
    "scheduling_min_days": 3
  }
}
```

**Resposta:**

```json
{
  "updated": [
    "scheduling_enabled",
    "scheduling_priority",
    "scheduling_min_days"
  ]
}
```

### Excluir configuração

Remove uma configuração do sistema. Configurações críticas do sistema não podem ser excluídas.

```
DELETE /api/system-settings/{key}
```

**Resposta:**

```json
{
  "message": "Setting deleted successfully"
}
```

## Tipos de dados

Os valores das configurações são armazenados como strings no banco de dados, mas são convertidos para os tipos de dados apropriados nas respostas da API:

- **string**: Nenhuma conversão especial
- **boolean**: "true"/"false" → true/false
- **integer**: "123" → 123
- **float**: "123.45" → 123.45
- **array/json**: String JSON → Array/objeto JavaScript

## Permissões

Os endpoints da API requerem as seguintes permissões:

- `view settings`: Para visualizar todas as configurações (inclusive privadas)
- `edit settings`: Para criar, atualizar ou excluir configurações

Usuários sem a permissão `view settings` só podem ver configurações públicas (`is_public = true`).

## Códigos de Erro

- **401 Unauthorized**: Autenticação necessária
- **403 Forbidden**: Falta de permissão
- **404 Not Found**: Configuração não encontrada
- **422 Unprocessable Entity**: Validação de dados falhou 