<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Confirmação de Atendimento</title>
    <style>
        body {
            font-family: Arial, sans-serif;
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
            background-color: #0066cc;
            color: white;
            padding: 15px;
            text-align: center;
        }
        .content {
            padding: 20px;
            background-color: #f9f9f9;
        }
        .button {
            display: inline-block;
            background-color: #0066cc;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            padding: 10px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Confirmação de Atendimento</h1>
        </div>
        <div class="content">
            @if($recipientType == 'patient')
                <p>Olá {{ $recipient->name }},</p>
                <p>Seu atendimento está agendado para <strong>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y') }}</strong> às <strong>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('H:i') }}</strong>.</p>
                <p>Por favor, confirme sua presença clicando no botão abaixo:</p>
            @else
                <p>Olá {{ $recipient->name }},</p>
                <p>Um atendimento está agendado para <strong>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y') }}</strong> às <strong>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('H:i') }}</strong>.</p>
                <p>Por favor, confirme a realização do atendimento clicando no botão abaixo:</p>
            @endif
            
            <div style="text-align: center;">
                <a href="{{ $verificationUrl }}" class="button">Confirmar Atendimento</a>
            </div>
            
            <p>Se você não conseguir clicar no botão acima, copie e cole o link abaixo em seu navegador:</p>
            <p>{{ $verificationUrl }}</p>
            
            <p>Detalhes do agendamento:</p>
            <ul>
                <li>Data: {{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y') }}</li>
                <li>Hora: {{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('H:i') }}</li>
                <li>Paciente: {{ $appointment->solicitation->patient->name }}</li>
                <li>Procedimento: {{ $appointment->solicitation->tuss->name }}</li>
                @if($appointment->provider)
                <li>Prestador: {{ $appointment->provider->name }}</li>
                @endif
            </ul>
        </div>
        <div class="footer">
            <p>Este é um e-mail automático. Por favor, não responda.</p>
            <p>&copy; {{ date('Y') }} Conecta Saúde. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html> 