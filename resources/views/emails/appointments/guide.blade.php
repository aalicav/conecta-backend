<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Guia de Atendimento</title>
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
            <h1>Guia de Atendimento</h1>
        </div>
        <div class="content">
            <p>Prezado(a) prestador(a),</p>
            
            <p>Segue anexa a guia de atendimento para o paciente 
            <strong>{{ $appointment->solicitation->patient->name }}</strong>, 
            agendado para <strong>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y') }}</strong> 
            às <strong>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('H:i') }}</strong>.</p>
            
            <p>Por favor, imprima esta guia para que seja assinada pelo paciente e pelo profissional 
            responsável pelo atendimento.</p>
            
            <p>Após o atendimento, a guia assinada deve ser anexada no sistema para confirmação do serviço.</p>
            
            <p><strong>Detalhes do Atendimento:</strong></p>
            <ul>
                <li><strong>Paciente:</strong> {{ $appointment->solicitation->patient->name }}</li>
                <li><strong>Data:</strong> {{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y') }}</li>
                <li><strong>Hora:</strong> {{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('H:i') }}</li>
                <li><strong>Procedimento:</strong> {{ $appointment->solicitation->tuss->name }}</li>
                <li><strong>Plano de Saúde:</strong> {{ $appointment->solicitation->healthPlan->name }}</li>
            </ul>
            
            <p>Em caso de dúvidas, entre em contato com nossa central de atendimento.</p>
        </div>
        <div class="footer">
            <p>Este é um e-mail automático. Por favor, não responda.</p>
            <p>&copy; {{ date('Y') }} Conecta Saúde. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html>
