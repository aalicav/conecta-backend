<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paciente Não Compareceu</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .content {
            background-color: #fff;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .alert {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .info-table th,
        .info-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .info-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .footer {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>❌ Paciente Não Compareceu</h2>
        <p>Agendamento #{{ $appointment_id }}</p>
    </div>

    <div class="content">
        <div class="alert">
            <strong>Status Atualizado:</strong> Este agendamento foi automaticamente marcado como "não compareceu" após 2 horas sem confirmação de comparecimento.
        </div>

        <h3>Detalhes do Agendamento</h3>
        <table class="info-table">
            <tr>
                <th>ID do Agendamento</th>
                <td>{{ $appointment_id }}</td>
            </tr>
            <tr>
                <th>Paciente</th>
                <td>{{ $patient_name }}</td>
            </tr>
            <tr>
                <th>Plano de Saúde</th>
                <td>{{ $health_plan_name }}</td>
            </tr>
            <tr>
                <th>Data/Hora Agendada</th>
                <td>{{ $scheduled_date }}</td>
            </tr>
            <tr>
                <th>Data/Hora de Conclusão</th>
                <td>{{ $completed_date }}</td>
            </tr>
            <tr>
                <th>Procedimento</th>
                <td>{{ $procedure_name }}</td>
            </tr>
            <tr>
                <th>Status</th>
                <td><strong>Não Compareceu</strong></td>
            </tr>
        </table>

        <p><strong>Notificações Enviadas:</strong></p>
        <ul>
            <li>Email enviado para o plano de saúde</li>
            <li>WhatsApp enviado para o plano de saúde (se configurado)</li>
        </ul>
        
        <p>O agendamento foi automaticamente processado pelo sistema e não requer ação adicional.</p>
    </div>

    <div class="footer">
        <p>Este é um email automático do sistema. Por favor, não responda a este email.</p>
        <p>Data/Hora do envio: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>
</body>
</html>
