<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinição de Senha</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .button {
            display: inline-block;
            background-color: #0066cc;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 25px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Redefinição de Senha</h1>
        </div>

        <p>Olá!</p>
        
        <p>Recebemos uma solicitação para redefinir a senha da sua conta. Para prosseguir com a redefinição, clique no botão abaixo:</p>

        <div style="text-align: center;">
            <a href="{{ $resetUrl }}" class="button">Redefinir Senha</a>
        </div>

        <p>Se você não solicitou a redefinição de senha, por favor ignore este e-mail.</p>

        <p>Este link expirará em {{ $expirationTime }}.</p>

        <div class="footer">
            <p>Esta mensagem foi enviada automaticamente. Por favor, não responda a este e-mail.</p>
            <p>&copy; {{ date('Y') }} {{ $companyName }}. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html> 