<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo ao Sistema</title>
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
        .welcome-card {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #6610f2;
        }
        .feature-item {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
        }
        .feature-icon {
            width: 24px;
            margin-right: 10px;
            display: inline-block;
        }
        .feature-text {
            flex: 1;
        }
        .button {
            display: inline-block;
            background-color: #6610f2;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 15px;
        }
        .button:hover {
            background-color: #520dc2;
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
            .welcome-card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="Logo" class="logo">
        <h1>Bem-vindo(a) ao {{ $companyName }}!</h1>
    </div>

    <div class="content">
        <p>Olá, {{ $user->name }}!</p>
        
        <p>É com grande satisfação que damos as boas-vindas ao nosso sistema de saúde. Sua conta foi criada com sucesso e você já pode começar a utilizar nossa plataforma.</p>

        <div class="welcome-card">
            <h2>Informações da sua conta</h2>
            <p><strong>Email:</strong> {{ $user->email }}</p>
            <p><strong>Perfil:</strong> {{ $user->role }}</p>
            
            @if($tempPassword)
            <p><strong>Senha temporária:</strong> {{ $tempPassword }}</p>
            <p><em>Importante: Recomendamos que você altere sua senha temporária no primeiro acesso.</em></p>
            @endif
        </div>

        <h3>O que você pode fazer na nossa plataforma:</h3>
        
        <div class="feature-item">
            <span class="feature-icon">✅</span>
            <div class="feature-text">
                <strong>Agendar consultas</strong> com profissionais de saúde de forma rápida e eficiente
            </div>
        </div>
        
        <div class="feature-item">
            <span class="feature-icon">✅</span>
            <div class="feature-text">
                <strong>Acessar seu histórico médico</strong> e acompanhar seus atendimentos anteriores
            </div>
        </div>
        
        <div class="feature-item">
            <span class="feature-icon">✅</span>
            <div class="feature-text">
                <strong>Verificar resultados de exames</strong> diretamente pela plataforma
            </div>
        </div>
        
        <div class="feature-item">
            <span class="feature-icon">✅</span>
            <div class="feature-text">
                <strong>Receber lembretes</strong> de consultas e procedimentos
            </div>
        </div>

        <p>Para começar a utilizar nossos serviços, clique no botão abaixo para acessar a plataforma:</p>

        <a href="{{ $loginUrl }}" class="button">Acessar o Sistema</a>
        
        <p style="margin-top: 30px;">Se você tiver qualquer dúvida ou precisar de assistência, nossa equipe de suporte está disponível para ajudar:</p>
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