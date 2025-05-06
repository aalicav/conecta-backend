<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinição de Senha</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            padding: 20px 0;
            border-bottom: 1px solid #eaeaea;
        }
        .logo {
            max-width: 150px;
            margin-bottom: 15px;
        }
        .content {
            padding: 20px 0;
        }
        .reset-card {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #ffc107;
        }
        .button {
            display: inline-block;
            background-color: #ffc107;
            color: #212529 !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 15px;
        }
        .button:hover {
            background-color: #e0a800;
        }
        .code {
            font-family: monospace;
            font-size: 18px;
            background-color: #eee;
            padding: 10px 15px;
            border-radius: 4px;
            letter-spacing: 2px;
            display: inline-block;
            margin: 10px 0;
        }
        .warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eaeaea;
            text-align: center;
            font-size: 12px;
            color: #777;
        }
        .address {
            margin-top: 10px;
        }
        .social-media {
            margin-top: 15px;
        }
        .social-media a {
            margin: 0 10px;
            text-decoration: none;
        }
        @media only screen and (max-width: 480px) {
            body {
                padding: 10px;
            }
            .reset-card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="Logo" class="logo">
        <h1>Redefinição de Senha</h1>
    </div>

    <div class="content">
        <p>Olá, {{ $user->name }}!</p>
        
        <p>Recebemos uma solicitação para redefinir a senha da sua conta. Se você não solicitou a alteração de senha, por favor, ignore este email ou entre em contato com nosso suporte.</p>

        <div class="reset-card">
            <h2>Redefinição de senha</h2>
            <p>Para criar uma nova senha, clique no botão abaixo:</p>
            
            <a href="{{ $resetUrl }}" class="button">Redefinir Minha Senha</a>
            
            <p style="margin-top: 20px;">Ou use o seguinte código de verificação:</p>
            <div class="code">{{ $resetCode }}</div>
            
            <p>Este código expira em <strong>{{ $expirationTime }}</strong>.</p>
        </div>

        <div class="warning">
            <p><strong>Atenção:</strong> Se você não solicitou esta redefinição de senha, recomendamos que altere imediatamente sua senha atual para garantir a segurança da sua conta.</p>
        </div>
        
        <p>Se você tiver qualquer dúvida ou precisar de assistência, nossa equipe de suporte está disponível para ajudar:</p>
        <ul>
            <li>Email: {{ $supportEmail }}</li>
            <li>Telefone: {{ $supportPhone }}</li>
        </ul>
    </div>

    <div class="footer">
        <p>Esta mensagem foi enviada automaticamente. Por favor, não responda a este email.</p>
        <div class="address">
            {{ $companyName }} &bull; {{ $companyAddress }} &bull; {{ $companyCity }}/{{ $companyState }}
        </div>
        <div class="social-media">
            @if(isset($socialMedia))
                @foreach($socialMedia as $name => $url)
                    <a href="{{ $url }}">{{ $name }}</a>
                @endforeach
            @endif
        </div>
        <p>&copy; {{ date('Y') }} {{ $companyName }}. Todos os direitos reservados.</p>
    </div>
</body>
</html> 