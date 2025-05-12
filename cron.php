<?php

/**
 * Laravel Queue Worker for Shared Hosting
 *
 * Este arquivo permite processar as filas do Laravel em ambientes de hospedagem compartilhada
 * como a Hostinger, onde não é possível rodar comandos artisan diretamente no cron.
 *
 * Uso no cPanel/Hostinger: 
 * * * * * * php /home/USUARIO/public_html/cron.php
 * 
 * Ou em ambientes XAMPP:
 * * * * * * php /opt/lampp/htdocs/conecta-backend/cron.php
 */

// Defina o caminho para o diretório do seu projeto Laravel
$basePath = __DIR__;

// Carregar o autoloader
require $basePath . '/vendor/autoload.php';

// Carregar variáveis de ambiente
$app = require_once $basePath . '/bootstrap/app.php';

// Iniciar o kernel do Laravel
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Obter a data e hora atual para o log
$dateTime = date('Y-m-d H:i:s');

// Registrar início da execução
file_put_contents(
    $basePath . '/storage/logs/queue.log', 
    "[{$dateTime}] Iniciando processamento de filas...\n", 
    FILE_APPEND
);

try {
    // Executar o comando queue:work com os parâmetros desejados
    $status = $kernel->call('queue:work', [
        '--stop-when-empty' => true,
        '--max-jobs' => 50,
        '--max-time' => 55,
        '--timeout' => 60,
        '--memory' => 128,
        '--sleep' => 3,
        '--tries' => 3
    ]);
    
    // Registrar sucesso no log
    $message = "[{$dateTime}] Processamento de filas concluído (código: {$status})\n";
    file_put_contents($basePath . '/storage/logs/queue.log', $message, FILE_APPEND);
} catch (Exception $e) {
    // Registrar erro no log
    $message = "[{$dateTime}] Erro no processamento de filas: " . $e->getMessage() . "\n";
    file_put_contents($basePath . '/storage/logs/queue.log', $message, FILE_APPEND);
}

// Destruir a aplicação
$kernel->terminate(
    Illuminate\Http\Request::capture(),
    new Illuminate\Http\Response()
); 