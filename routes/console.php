<?php

use App\Console\Commands\SendAppointmentReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Agendamento de Comandos
|--------------------------------------------------------------------------
|
| Aqui você pode definir todos os comandos agendados para sua aplicação.
| Os comandos serão executados de acordo com a frequência definida.
|
*/

Schedule::command('appointments:send-reminders --hours=24')
    ->dailyAt('08:00')
    ->name('send-appointment-reminders-24h')
    ->withoutOverlapping()
    ->onSuccess(function () {
        info('Lembretes de 24h para agendamentos enviados com sucesso');
    })
    ->onFailure(function () {
        info('Falha ao enviar lembretes de 24h para agendamentos');
    });

Schedule::command('appointments:send-reminders --hours=2')
    ->hourly()
    ->name('send-appointment-reminders-2h')
    ->withoutOverlapping()
    ->onSuccess(function () {
        info('Lembretes de 2h para agendamentos enviados com sucesso');
    })
    ->onFailure(function () {
        info('Falha ao enviar lembretes de 2h para agendamentos');
    });

Schedule::command('appointments:send-reminders --hours=1')
    ->hourly()
    ->name('send-appointment-reminders-1h')
    ->withoutOverlapping()
    ->onSuccess(function () {
        info('Lembretes de 1h para agendamentos enviados com sucesso');
    })
    ->onFailure(function () {
        info('Falha ao enviar lembretes de 1h para agendamentos');
    });

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('make:user {name} {email} {password} {role}', function ($name, $email, $password, $role) {
    // Check if email already exists
    if (\App\Models\User::where('email', $email)->exists()) {
        $this->error("A user with email {$email} already exists!");
        return;
    }
    
    // Check if role exists
    if (!\Spatie\Permission\Models\Role::where('name', $role)->exists()) {
        $this->error("Role '{$role}' does not exist!");
        $this->info("Available roles:");
        \Spatie\Permission\Models\Role::all()->each(function ($role) {
            $this->line("- {$role->name}");
        });
        return;
    }
    
    // Create user
    $user = \App\Models\User::create([
        'name' => $name,
        'email' => $email,
        'password' => \Illuminate\Support\Facades\Hash::make($password),
    ]);
    
    // Assign role
    $user->assignRole($role);
    
    $this->info("User '{$name}' created with email '{$email}' and role '{$role}'");
})->describe('Create a new user with a specific role');

Artisan::command('user:list', function () {
    $this->table(
        ['ID', 'Name', 'Email', 'Roles', 'Permissions'],
        \App\Models\User::all()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->implode(', '),
                'permissions' => $user->getPermissionNames()->implode(', ')
            ];
        })
    );
})->describe('List all users with their roles and permissions');

Artisan::command('roles:list', function () {
    $this->table(
        ['Name', 'Permissions'],
        \Spatie\Permission\Models\Role::all()->map(function ($role) {
            return [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->implode(', ')
            ];
        })
    );
})->describe('List all roles with their permissions');

Artisan::command('roles:refresh', function () {
    $this->info('Refreshing roles and permissions...');
    
    // Call the seeders
    $this->call(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->call(\Database\Seeders\EnhancedRolesAndPermissionsSeeder::class);
    
    $this->info('Roles and permissions refreshed!');
})->describe('Refresh roles and permissions without running a full database migration');
