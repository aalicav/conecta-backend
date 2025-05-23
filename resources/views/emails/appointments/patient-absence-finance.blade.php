<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Paciente Ausente - Possível Estorno</title>
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
        .alert {
            background-color: #ffecec;
            border-left: 5px solid #f44336;
            padding: 10px;
            margin: 15px 0;
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
            <h1>Paciente Ausente - Possível Estorno</h1>
        </div>
        <div class="content">
            <p>Olá {{ $user->name }},</p>
            
            <p>Informamos que o paciente <strong>{{ $appointment->solicitation->patient->name }}</strong> não compareceu ao agendamento.</p>
            
            @if($isPaid)
            <div class="alert">
                <p><strong>Atenção:</strong> Este atendimento já foi pago ao prestador. Pode ser necessário solicitar estorno do pagamento.</p>
            </div>
            @endif
            
            <p><strong>Detalhes do Agendamento:</strong></p>
            <ul>
                <li><strong>Paciente:</strong> {{ $appointment->solicitation->patient->name }}</li>
                <li><strong>Data:</strong> {{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y') }}</li>
                <li><strong>Hora:</strong> {{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('H:i') }}</li>
                <li><strong>Procedimento:</strong> {{ $appointment->solicitation->tuss->name }}</li>
                <li><strong>Prestador:</strong> {{ $appointment->provider->name }}</li>
                <li><strong>Status do Pagamento:</strong> {{ $isPaid ? 'Já pago' : 'Não pago' }}</li>
            </ul>
            
            <p>Para mais detalhes sobre este agendamento, <a href="{{ $actionUrl }}">clique aqui</a>.</p>
            
            @if($isPaid)
            <p>Por favor, verifique as políticas da empresa em relação a ausências para determinar se é necessário solicitar o estorno.</p>
            @else
            <p>Como o pagamento ainda não foi realizado, não é necessário processá-lo.</p>
            @endif
        </div>
        <div class="footer">
            <p>Este é um e-mail automático. Por favor, não responda.</p>
            <p>&copy; {{ date('Y') }} Conecta Saúde. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html> 