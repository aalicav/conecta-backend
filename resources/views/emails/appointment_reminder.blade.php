<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lembrete de Consulta</title>
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
        .appointment-card {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #0066cc;
        }
        .appointment-details {
            margin-bottom: 10px;
        }
        .detail-label {
            font-weight: bold;
            color: #555;
        }
        .detail-value {
            margin-top: 5px;
        }
        .button {
            display: inline-block;
            background-color: #0066cc;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 15px;
        }
        .button:hover {
            background-color: #0055b3;
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
            .appointment-card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="Logo" class="logo">
        <h1>Lembrete de Consulta</h1>
    </div>

    <div class="content">
        <p>Olá, {{ $appointment->patient->name }}!</p>
        
        <p>Este é um lembrete sobre sua consulta agendada, que acontecerá em <strong>{{ $hoursUntilAppointment }}</strong>.</p>

        <div class="appointment-card">
            <div class="appointment-details">
                <div class="detail-label">Profissional:</div>
                <div class="detail-value">Dr(a). {{ $appointment->professional->name }}</div>
            </div>
            
            <div class="appointment-details">
                <div class="detail-label">Data:</div>
                <div class="detail-value">{{ $formattedDate }}</div>
            </div>
            
            <div class="appointment-details">
                <div class="detail-label">Horário:</div>
                <div class="detail-value">{{ $formattedTime }}</div>
            </div>
            
            <div class="appointment-details">
                <div class="detail-label">Local:</div>
                <div class="detail-value">{{ $appointment->clinic->name }}</div>
            </div>

            <div class="appointment-details">
                <div class="detail-label">Endereço:</div>
                <div class="detail-value">{{ $appointment->clinic->address }}, {{ $appointment->clinic->city }}/{{ $appointment->clinic->state }}</div>
            </div>
            
            @if ($appointment->procedure)
            <div class="appointment-details">
                <div class="detail-label">Procedimento:</div>
                <div class="detail-value">{{ $appointment->procedure->name }}</div>
            </div>
            @endif
        </div>

        <p>Recomendamos chegar com pelo menos 10 minutos de antecedência.</p>

        <p>Caso precise reagendar ou cancelar, por favor entre em contato o quanto antes:</p>
        <ul>
            <li>Telefone: {{ $appointment->clinic->phones->first()->number ?? 'Não disponível' }}</li>
            <li>Email: {{ $appointment->clinic->email ?? 'Não disponível' }}</li>
        </ul>

        <a href="{{ $confirmationUrl }}" class="button">Confirmar Presença</a>
    </div>

    <div class="footer">
        <p>Esta mensagem foi enviada automaticamente. Por favor, não responda a este email.</p>
        <div class="address">
            {{ $clinicName }} &bull; {{ $clinicAddress }} &bull; {{ $clinicCity }}/{{ $clinicState }}
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