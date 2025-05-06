<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados de Exames Disponíveis</title>
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
        .exam-card {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #17a2b8;
        }
        .exam-item {
            padding: 10px 0;
            border-bottom: 1px solid #eaeaea;
        }
        .exam-item:last-child {
            border-bottom: none;
        }
        .exam-date {
            color: #666;
            font-size: 14px;
        }
        .button {
            display: inline-block;
            background-color: #17a2b8;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 15px;
        }
        .button:hover {
            background-color: #138496;
        }
        .notice {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
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
            .exam-card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="Logo" class="logo">
        <h1>Resultados de Exames Disponíveis</h1>
    </div>

    <div class="content">
        <p>Olá, {{ $patient->name }}!</p>
        
        <p>Informamos que os resultados dos seus exames já estão disponíveis para visualização em nosso sistema.</p>

        <div class="exam-card">
            <h2>Exames disponíveis:</h2>
            
            @foreach($exams as $exam)
            <div class="exam-item">
                <h3>{{ $exam->name }}</h3>
                <div class="exam-date">Realizado em: {{ $exam->formatted_date }}</div>
                <div>Solicitado por: Dr(a). {{ $exam->doctor->name }}</div>
                @if($exam->has_critical_results)
                <div style="color: #dc3545; font-weight: bold; margin-top: 5px;">* Requer atenção especial</div>
                @endif
            </div>
            @endforeach
        </div>

        <div class="notice">
            <p><strong>Importante:</strong> Os resultados dos seus exames já foram encaminhados para o médico solicitante. Recomendamos que você consulte seu médico para a interpretação adequada dos resultados.</p>
        </div>

        <p>Para acessar seus resultados completos, clique no botão abaixo:</p>

        <a href="{{ $resultsUrl }}" class="button">Ver Resultados Completos</a>
        
        <p style="margin-top: 20px;">Se preferir, você também pode retirar uma cópia impressa dos resultados em nossa unidade:</p>
        <p>{{ $clinic->name }}<br>
        {{ $clinic->address }}, {{ $clinic->city }}/{{ $clinic->state }}</p>
        
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