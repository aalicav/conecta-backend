<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo ao {{ $companyName }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #1e88e5;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #fff;
            border: 1px solid #ddd;
            border-top: none;
            padding: 20px;
            border-radius: 0 0 5px 5px;
        }
        .logo {
            max-width: 150px;
            margin-bottom: 15px;
        }
        .credentials {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .button {
            display: inline-block;
            background-color: #1e88e5;
            color: white;
            text-decoration: none;
            padding: 12px 25px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #777;
        }
        .social {
            margin-top: 15px;
        }
        .social a {
            display: inline-block;
            margin: 0 5px;
            color: #1e88e5;
            text-decoration: none;
        }
        .info {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Bem-vindo ao {{ $companyName }}</h1>
        </div>
        
        <div class="content">
            <h2>Olá, {{ $user->name }}!</h2>
            
            <p>Ficamos felizes em tê-lo conosco! Sua conta foi criada com sucesso no sistema {{ $companyName }}.</p>
            
            @isset($entityType)
                <p>Você foi registrado como <strong>{{ $entityType }}</strong> em nossa plataforma.</p>
            @endisset
            
            <div class="credentials">
                <h3>Suas informações de acesso:</h3>
                <p><strong>E-mail:</strong> {{ $user->email }}</p>
                <p><strong>Senha temporária:</strong> {{ $password }}</p>
                <p><strong>Importante:</strong> Por questões de segurança, recomendamos alterar sua senha após o primeiro acesso.</p>
            </div>
            
            <p>Para acessar a plataforma, clique no botão abaixo:</p>
            
            <div style="text-align: center;">
                <a href="{{ $loginUrl }}" class="button">Acessar o Sistema</a>
            </div>
            
            <p>Se você estiver tendo problemas com o botão acima, copie e cole o link abaixo em seu navegador:</p>
            <p style="word-break: break-all;">{{ $loginUrl }}</p>
            
            <div class="info">
                <p>Se você não solicitou esta conta ou acredita que recebeu este e-mail por engano, por favor entre em contato com nosso suporte.</p>
                
                <p>
                    <strong>Suporte:</strong><br>
                    E-mail: {{ $supportEmail }}<br>
                    Telefone: {{ $supportPhone }}
                </p>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ $companyName }}. Todos os direitos reservados.</p>
            <p>{{ $companyAddress }}, {{ $companyCity }} - {{ $companyState }}</p>
            
            <div class="social">
                @foreach($socialMedia as $platform => $url)
                    @if($url)
                        <a href="{{ $url }}">{{ $platform }}</a>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</body>
</html> 