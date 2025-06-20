<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 40px 30px;
        }
        .content h2 {
            color: #2c3e50;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 600;
        }
        .content h3 {
            color: #34495e;
            margin-top: 30px;
            margin-bottom: 15px;
            font-size: 16px;
            font-weight: 600;
        }
        .content p {
            margin-bottom: 16px;
            color: #555555;
        }
        .content ul {
            margin-bottom: 20px;
            padding-left: 20px;
        }
        .content li {
            margin-bottom: 8px;
            color: #555555;
        }
        .content strong {
            color: #2c3e50;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
            transition: transform 0.2s ease;
        }
        .button:hover {
            transform: translateY(-1px);
            color: #ffffff;
            text-decoration: none;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px 20px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 0;
            color: #6c757d;
            font-size: 14px;
        }
        .highlight-box {
            background-color: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 0 6px 6px 0;
        }
        .status-confirmed {
            color: #28a745;
            font-weight: 600;
        }
        .status-cancelled {
            color: #dc3545;
            font-weight: 600;
        }
        .urgent {
            background-color: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        @media only screen and (max-width: 600px) {
            .container {
                width: 100% !important;
            }
            .content {
                padding: 20px !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ config('app.name', 'Conecta Saúde') }}</h1>
        </div>
        
        <div class="content">
            {!! $content !!}
            
            @if($actionUrl)
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{ $actionUrl }}" class="button">{{ $actionText }}</a>
                </div>
            @endif
            
            <div class="highlight-box">
                <p><strong>💡 Dica:</strong> Você pode acessar o sistema a qualquer momento para acompanhar o status dos seus agendamentos e solicitações.</p>
            </div>
        </div>
        
        <div class="footer">
            <p>
                Esta mensagem foi enviada automaticamente pelo sistema {{ config('app.name', 'Conecta Saúde') }}.<br>
                Para suporte, entre em contato: {{ config('app.support_email', 'suporte@conectasaude.com') }}
            </p>
            <p style="margin-top: 10px;">
                <small>© {{ date('Y') }} {{ config('app.name', 'Conecta Saúde') }}. Todos os direitos reservados.</small>
            </p>
        </div>
    </div>
</body>
</html> 