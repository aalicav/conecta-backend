<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Email de Teste</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #3490dc;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            padding: 20px;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 5px 5px;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Email de Teste - {{ config('app.name') }}</h1>
    </div>
    <div class="content">
        <p>{{ $content }}</p>
        <p>Este é um email de teste enviado em: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>
    <div class="footer">
        <p>Se você recebeu este email, a configuração do servidor de email está funcionando corretamente.</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
    </div>
</body>
</html> 