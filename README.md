<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# Conecta Backend - Healthcare Referral System

A B2B platform for health plans to access a network of third-party healthcare providers for procedures outside their main network.

## Overview

Conecta is an API-first backend system that connects health plans with clinics and healthcare professionals. When a health plan lacks capacity or infrastructure to serve a patient, they can request a procedure (via TUSS code), and the system automatically finds the best provider based on:

- Valid contracts
- Best cost-benefit ratio
- Geographic proximity
- Availability (no scheduling conflicts)

## Core Entities

- **Health Plans**: Register patients, initiate procedure requests, manage contracts
- **Clinics**: Service providers with CNPJ, can have multiple branches and professionals
- **Professionals**: Healthcare providers (doctors, psychologists, etc.) with council registrations
- **Patients**: Linked to health plans, geolocated (with consent)
- **Solicitations**: Requests for procedures within a date range
- **Appointments**: Automatically or manually scheduled with providers
- **Contracts**: Legal agreements between entities with digital signatures
- **Payments**: Managed through Stripe integration with financial audit capabilities

## Technical Architecture

- API-only backend in Laravel 11
- Authentication: Laravel Sanctum
- Authorization: Spatie Permissions + Policies
- PDF generation: Barryvdh/Laravel-DomPDF
- Notifications: Laravel + Twilio (WhatsApp) + Email
- Queues: Redis + Horizon
- Geolocation: Mapbox
- Payments: Stripe via Laravel Cashier
- System Settings: Dynamic configuration via database + API

## Access Control

| Role           | Access                                                    |
|----------------|-----------------------------------------------------------|
| `super_admin`  | Full system access, configuration, approvals              |
| `plan_admin`   | Manage patients, solicitations, view own appointments     |
| `clinic_admin` | Manage professionals and appointments for their clinic    |
| `professional` | View and manage their own appointments                    |

## System Settings

The system includes a powerful configuration system that allows administrators to manage application settings without code changes:

- Centralized storage in the database with caching
- Type-safe settings (boolean, string, integer, array, etc.)
- Role-based access control (public vs. private settings)
- Organized by functional groups (scheduling, payment, etc.)
- Full API for managing settings

Examples of configurable settings:

- Automatic scheduling parameters (enabled/disabled, prioritization method)
- Payment processing options (advance payments, grace periods)
- Notification settings (when, how, and to whom)

For more details, see the [system settings documentation](docs/api/system-settings.md).

## Automatic Scheduling

The system includes a configurable automatic scheduling engine that considers:

- Cost optimization
- Geographic proximity
- Provider availability
- Balance between factors

Administrators can configure scheduling priority, advance notice requirements, and other parameters.

## Novas funcionalidades do Fluxo de Negociação

### Ciclos de Renegociação
Esta funcionalidade permite múltiplos ciclos de negociação, limitando o número máximo de tentativas para evitar negociações intermináveis.

**Endpoints**:
- `POST /api/negotiations/{negotiation}/cycles` - Inicia um novo ciclo de negociação

**Parâmetros**:
- Não requer parâmetros adicionais

**Restrições**:
- Apenas negociações com status 'rejected' ou 'partially_approved' podem iniciar um novo ciclo
- Existe um limite máximo de ciclos (configurado como 3 por padrão)

### Rollback no Fluxo
Permite reverter o status de uma negociação para um estado anterior em situações específicas.

**Endpoints**:
- `POST /api/negotiations/{negotiation}/rollback` - Reverte o status da negociação

**Parâmetros**:
- `target_status` - Status alvo para o rollback (draft, submitted, pending)
- `reason` - Motivo da reversão

**Restrições**:
- Apenas certos rollbacks são permitidos:
  - De 'pending' para 'submitted' ou 'draft'
  - De 'approved' para 'pending' ou 'submitted'
  - De 'partially_approved' para 'submitted'
  
### Bifurcação de Fluxo
Permite dividir uma negociação em múltiplas sub-negociações quando há grandes divergências.

**Endpoints**:
- `POST /api/negotiations/{negotiation}/fork` - Bifurca a negociação em múltiplas

**Parâmetros**:
- `item_groups` - Array de grupos de itens para criar novas negociações
  - `items` - IDs dos itens para incluir no grupo
  - `title` - Título da nova negociação
  - `notes` - Notas adicionais (opcional)

**Restrições**:
- Mínimo de 2 grupos para bifurcar
- Apenas negociações com status 'submitted' ou 'partially_approved' podem ser bifurcadas

## Como usar

Para implementar estas funcionalidades no frontend:

1. **Ciclos de Negociação**:
   - Adicione um botão "Iniciar Novo Ciclo" para negociações rejeitadas ou parcialmente aprovadas
   - Exiba o contador de ciclos atualmente utilizado e o máximo permitido

2. **Rollback**:
   - Adicione uma opção de menu "Reverter Status" em negociações pendentes ou aprovadas
   - Solicite o motivo da reversão através de um modal

3. **Bifurcação**:
   - Adicione um botão "Dividir Negociação" em negociações em andamento
   - Implemente uma interface para agrupar itens em diferentes grupos

## Modelos de Banco de Dados

Foram adicionados novos campos e tabelas:

1. **Na tabela `negotiations`**:
   - `negotiation_cycle` - Ciclo atual da negociação
   - `max_cycles_allowed` - Número máximo de ciclos permitidos
   - `previous_cycles_data` - Histórico dos ciclos anteriores (JSON)
   - `is_fork` - Indica se é uma bifurcação
   - `parent_negotiation_id` - ID da negociação original (quando bifurcada)
   - `forked_at` - Data da bifurcação
   - `fork_count` - Número de bifurcações

2. **Nova tabela `negotiation_status_history`**:
   - Registra mudanças de status, incluindo o motivo da mudança

## Notificações

Novas notificações foram implementadas para:
- Início de novo ciclo de negociação
- Rollback de status
- Bifurcação de negociação

## Installation

```bash
# Clone the repository
git clone https://github.com/your-username/conecta-backend.git
cd conecta-backend

# Install dependencies
composer install

# Copy environment file and configure
cp .env.example .env
php artisan key:generate

# Run migrations and seeders
php artisan migrate
php artisan db:seed

# Start development server
php artisan serve
```

## API Documentation

API documentation is available at `/api/documentation` when running in development mode.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development/)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
