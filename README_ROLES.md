# Configuração de Perfis de Acesso (Roles)

Este documento explica como foi implementado o sistema de perfis de acesso conforme especificado por Dr. Ítalo, e como gerenciar usuários e perfis através dos comandos disponíveis.

## Perfis Implementados

O sistema conta com os seguintes perfis de acesso:

1. **Administrador do Sistema (super_admin)**
   - Acesso completo a todas as funcionalidades do sistema
   - Atribuído a Alisson e Anderson

2. **Direção (director)**
   - Alçada máxima de aprovação em fluxos críticos
   - Acesso a relatórios gerenciais e de desempenho
   - Aprovação final de contratos e exceções
   - Atribuído a Dr. Ítalo

3. **Equipe Comercial (commercial)**
   - Gestão de cadastros (Planos de Saúde, Estabelecimentos, Profissionais)
   - Negociação e gestão de contratos
   - Elaboração de contratos
   - Gestão de exceções (Negociação Extemporânea)
   - Atribuído a Mirelle e equipe

4. **Equipe Jurídica (legal)**
   - Análise e revisão de contratos
   - Gestão de templates de contratos
   - Acesso a documentação legal

5. **Equipe Operacional (operational)**
   - Gestão de cadastros de pacientes
   - Solicitações de atendimento
   - Agendamentos e confirmações
   - Atribuído a Lorena e equipe

6. **Equipe Financeira (financial)**
   - Gestão de faturamento
   - Pagamentos e comprovantes
   - Relatórios financeiros
   - Gestão de saldos
   - Atribuído a Aline e Paula

7. **Portal da Operadora (health_plan_portal)**
   - Portal restrito para as operadoras de saúde
   - Visualização de agendamentos e status
   - Assinatura de contratos

8. **Portal do Prestador (provider_portal)**
   - Portal restrito para estabelecimentos/profissionais
   - Confirmação de disponibilidade
   - Acesso a guias de atendimento
   - Assinatura de contratos

## Comandos para Gerenciamento

### Criação de Usuários

Para criar um novo usuário com um perfil específico:

```bash
php artisan make:user "Nome do Usuário" email@exemplo.com senha perfil
```

Exemplo:

```bash
php artisan make:user "Novo Comercial" comercial@exemplo.com minhasenha commercial
```

### Listagem de Usuários

Para listar todos os usuários cadastrados com seus perfis e permissões:

```bash
php artisan user:list
```

### Listagem de Perfis

Para listar todos os perfis disponíveis e suas permissões:

```bash
php artisan roles:list
```

### Atualização de Perfis

Para atualizar/recarregar os perfis de acesso e permissões (caso haja alterações nos seeders):

```bash
php artisan roles:refresh
```

## Instalação/Atualização

Para aplicar todas as definições de perfis e permissões em um novo ambiente:

```bash
php artisan migrate
php artisan db:seed
```

Se desejar aplicar apenas os seeders de perfis em um sistema existente:

```bash
php artisan db:seed --class=EnhancedRolesAndPermissionsSeeder
```

Para criar usuários de teste em ambiente de desenvolvimento:

```bash
php artisan db:seed --class=SampleUsersSeeder
```

## Fluxo de Aprovação de Contratos

O sistema implementa o fluxo de aprovação de contratos conforme especificado, seguindo as seguintes etapas:

1. **Submissão** (Equipe Comercial)
2. **Análise Jurídica** (Equipe Jurídica)
3. **Liberação Comercial** (Equipe Comercial)
4. **Aprovação Final** (Dr. Ítalo/Direção)
5. **Contrato Aprovado**

Cada etapa possui permissões específicas e requer usuários com o perfil apropriado para aprovação.

## Gestão de Exceções (Negociação Extemporânea)

O sistema também implementa o fluxo de aprovação para exceções na negociação de procedimentos, que requerem:

1. Solicitação pela Equipe Operacional/Comercial
2. Encaminhamento para Adla na Equipe Comercial
3. Aprovação pela Direção (Dr. Ítalo)
4. Registro formal no sistema e em aditivo contratual, quando necessário

## Dupla Checagem

Para operações sensíveis, como inserção de valores em contratos, o sistema implementa o conceito de dupla checagem, onde um membro da equipe insere os dados e outro membro com alçada superior (Viviane ou Dr. Ítalo) aprova.

## Considerações de Segurança

Os perfis foram cuidadosamente definidos seguindo o princípio do menor privilégio, garantindo que cada usuário tenha acesso apenas às funcionalidades necessárias para suas atribuições.

Todas as ações críticas são registradas em logs de auditoria, mantendo rastreabilidade completa das operações. 