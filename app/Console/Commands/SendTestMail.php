<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Mail\TestMail;

class SendTestMail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:send-test {email} {--message=} {--subject=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envia um email de teste para verificar as configurações de SMTP';

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
        $email = $this->argument('email');
        $message = $this->option('message') ?: 'Este é um email de teste enviado pelo comando artisan mail:send-test';
        $subject = $this->option('subject') ?: 'Teste de Email via Artisan - ' . config('app.name');
        
        $this->info("Enviando email de teste para: $email");
        $this->info("Assunto: $subject");
        
        try {
            $this->info("Tentando enviar email...");
            
            Mail::to($email)->send(new TestMail($message, $subject));
            
            $this->info("Email de teste enviado com sucesso!");
            $this->info("Verifique a caixa de entrada (e possivelmente a pasta de spam) de $email.");
            
            return 0;
        } catch (\Swift_TransportException $e) {
            $this->error("Erro de conexão SMTP: " . $e->getMessage());
            $this->warn("Verifique suas configurações SMTP no arquivo .env");
            $this->warn("Execute php artisan mail:check-config para verificar suas configurações atuais");
            return 1;
        } catch (\Exception $e) {
            $this->error("Erro ao enviar email: " . $e->getMessage());
            $this->error("Tipo de exceção: " . get_class($e));
            return 1;
        }
    }
} 