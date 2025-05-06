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
