<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Pagamento de Prestador Pendente</title>
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
            <h1>Pagamento de Prestador Pendente</h1>
        </div>
        <div class="content">
            <p>Olá {{ $user->name }},</p>
            
            <p>Um agendamento foi confirmado e está pronto para pagamento ao prestador.</p>
            
            <p><strong>Detalhes do Agendamento:</strong></p>
            <ul>
                <li><strong>Paciente:</strong> {{ $appointment->solicitation->patient->name }}</li>
                <li><strong>Data:</strong> {{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y') }}</li>
                <li><strong>Hora:</strong> {{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('H:i') }}</li>
                <li><strong>Procedimento:</strong> {{ $appointment->solicitation->tuss->name }}</li>
                <li><strong>Prestador:</strong> {{ $appointment->provider->name }}</li>
            </ul>
            
            <p>Para mais detalhes e processar o pagamento, <a href="{{ $actionUrl }}">clique aqui</a>.</p>
            
            <p>Por favor, processe o pagamento para este prestador o quanto antes.</p>
        </div>
        <div class="footer">
            <p>Este é um e-mail automático. Por favor, não responda.</p>
            <p>&copy; {{ date('Y') }} Conecta Saúde. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html> 