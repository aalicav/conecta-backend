<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lembrete de Consulta - Profissional</title>
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
            background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .content {
            background-color: #fff;
            padding: 30px;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .reminder-box {
            background-color: #f8d7da;
            border: 2px solid #f5c6cb;
            color: #721c24;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }
        .time-remaining {
            font-size: 24px;
            font-weight: bold;
            color: #dc3545;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .info-table th,
        .info-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .info-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            width: 30%;
        }
        .info-table td {
            background-color: #fff;
        }
        .highlight {
            background-color: #e7f3ff;
            padding: 15px;
            border-left: 4px solid #007bff;
            margin: 15px 0;
        }
        .footer {
            margin-top: 30px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            font-size: 14px;
            color: #666;
            text-align: center;
        }
        .icon {
            font-size: 20px;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>⏰ Lembrete de Consulta</h1>
        <p>Dr(a). {{ $provider_name }}</p>
    </div>

    <div class="content">
        <div class="reminder-box">
            <h2>⏱️ Tempo Restante</h2>
            <div class="time-remaining">{{ $time_remaining }}</div>
            <p>até sua consulta</p>
        </div>

        <h3>📋 Detalhes da Consulta</h3>
        <table class="info-table">
            <tr>
                <th><span class="icon">🆔</span>ID da Consulta</th>
                <td>{{ $appointment_id }}</td>
            </tr>
            <tr>
                <th><span class="icon">👤</span>Paciente</th>
                <td>{{ $patient_name }}</td>
            </tr>
            <tr>
                <th><span class="icon">🆔</span>Documento</th>
                <td>{{ $patient_document }}</td>
            </tr>
            <tr>
                <th><span class="icon">📞</span>Telefone</th>
                <td>{{ $patient_phone }}</td>
            </tr>
            <tr>
                <th><span class="icon">📅</span>Data e Horário</th>
                <td><strong>{{ $scheduled_date }}</strong></td>
            </tr>
            <tr>
                <th><span class="icon">🩺</span>Procedimento</th>
                <td>{{ $procedure_name }}</td>
            </tr>
            <tr>
                <th><span class="icon">👨‍⚕️</span>Profissional</th>
                <td>{{ $provider_name }}</td>
            </tr>
            <tr>
                <th><span class="icon">📍</span>Endereço</th>
                <td>{{ $provider_address }}</td>
            </tr>
        </table>

        <div class="highlight">
            <h4>⚠️ Preparação para a Consulta:</h4>
            <ul>
                <li>Confirme sua disponibilidade</li>
                <li>Prepare o ambiente de atendimento</li>
                <li>Verifique se tem todos os materiais necessários</li>
                <li>Em caso de imprevisto, entre em contato conosco</li>
            </ul>
        </div>

        <p><strong>Dúvidas?</strong> Entre em contato conosco através do sistema ou pelo WhatsApp.</p>
    </div>

    <div class="footer">
        <p>Este é um lembrete automático do sistema Conecta Saúde.</p>
        <p>Data/Hora do envio: {{ now()->format('d/m/Y H:i:s') }}</p>
        <p>Por favor, não responda a este email.</p>
    </div>
</body>
</html>
