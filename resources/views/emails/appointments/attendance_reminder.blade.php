<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirmação de Comparecimento Pendente</title>
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
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
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
        <h2>🚨 Confirmação de Comparecimento Pendente</h2>
        <p>Agendamento #{{ $appointment_id }}</p>
    </div>

    <div class="content">
        <div class="alert">
            <strong>Atenção:</strong> Este agendamento foi concluído há {{ $hours_overdue }} hora(s) e ainda não foi confirmado o comparecimento do paciente.
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
        </table>

        <p><strong>Ação Necessária:</strong> Por favor, confirme se o paciente compareceu ao agendamento através do sistema.</p>
        
        <p>Se o paciente não compareceu e já se passaram 2 horas desde a conclusão, o agendamento será automaticamente marcado como "não compareceu" e o plano de saúde será notificado.</p>
    </div>

    <div class="footer">
        <p>Este é um email automático do sistema. Por favor, não responda a este email.</p>
        <p>Data/Hora do envio: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>
</body>
</html>
