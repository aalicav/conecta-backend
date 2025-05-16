<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class CheckMailConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:check-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica e exibe as configurações de email do sistema';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Verificando configurações de email...');
        
        $mailConfig = config('mail');
        $driver = $mailConfig['default'];
        
        $this->info("Driver de email: " . $driver);
        
        // Informações do mailer
        if (isset($mailConfig['mailers'][$driver])) {
            $mailer = $mailConfig['mailers'][$driver];
            
            $this->info("Detalhes do mailer:");
            
            // Host e porta
            if (isset($mailer['host'])) {
                $this->info("  Host: " . $mailer['host']);
            }
            
            if (isset($mailer['port'])) {
                $this->info("  Porta: " . $mailer['port']);
            }
            
            // Username (sem exibir a senha)
            if (isset($mailer['username'])) {
                $this->info("  Username: " . $mailer['username']);
                $this->info("  Senha: " . (empty($mailer['password']) ? "Não definida" : "[Definida]"));
            }
            
            // Criptografia
            if (isset($mailer['encryption'])) {
                $this->info("  Criptografia: " . ($mailer['encryption'] ?: "Nenhuma"));
            }
            
            // Timeout
            if (isset($mailer['timeout'])) {
                $this->info("  Timeout: " . $mailer['timeout']);
            }
            
            // Verificação SSL
            if (isset($mailer['verify_peer'])) {
                $this->info("  Verificação SSL: " . ($mailer['verify_peer'] ? "Ativada" : "Desativada"));
            }
        }
        
        // Informações do remetente
        if (isset($mailConfig['from'])) {
            $this->info("\nRemetente padrão:");
            $this->info("  Nome: " . ($mailConfig['from']['name'] ?? "Não definido"));
            $this->info("  Email: " . ($mailConfig['from']['address'] ?? "Não definido"));
        }
        
        // Verificar variáveis de ambiente
        $this->info("\nVerificando variáveis de ambiente:");
        $envVars = [
            'MAIL_MAILER' => env('MAIL_MAILER'),
            'MAIL_HOST' => env('MAIL_HOST'),
            'MAIL_PORT' => env('MAIL_PORT'),
            'MAIL_USERNAME' => env('MAIL_USERNAME'),
            'MAIL_PASSWORD' => env('MAIL_PASSWORD') ? '[Definida]' : 'Não definida',
            'MAIL_ENCRYPTION' => env('MAIL_ENCRYPTION'),
            'MAIL_FROM_ADDRESS' => env('MAIL_FROM_ADDRESS'),
            'MAIL_FROM_NAME' => env('MAIL_FROM_NAME'),
        ];
        
        foreach ($envVars as $key => $value) {
            $this->info("  $key: " . ($value ?: "Não definido"));
        }
        
        $this->info("\nPara testar o envio de email, execute: php artisan mail:send-test example@example.com");
        
        return 0;
    }
} 