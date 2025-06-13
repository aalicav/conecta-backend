<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Nova Cobrança Gerada</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-radius: 5px;
        }
        .content {
            padding: 20px;
        }
        .details {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .amount {
            font-size: 24px;
            font-weight: bold;
            color: #28a745;
        }
        .footer {
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Nova Cobrança Gerada</h1>
        </div>

        <div class="content">
            <p>Olá,</p>

            <p>Foi gerada uma nova cobrança para o seu plano de saúde. Abaixo estão os detalhes:</p>

            <div class="details">
                <p><strong>Plano de Saúde:</strong> {{ $healthPlan->name }}</p>
                <p><strong>Contrato:</strong> {{ $contract->number }}</p>
                <p><strong>Data de Vencimento:</strong> {{ $batch->due_date->format('d/m/Y') }}</p>
                <p class="amount">Valor Total: R$ {{ number_format($batch->total_amount, 2, ',', '.') }}</p>
            </div>

            <p>Esta cobrança inclui os seguintes serviços:</p>

            <ul>
                @foreach($batch->items as $item)
                    <li>
                        {{ $item->appointment->procedure->name }} - 
                        R$ {{ number_format($item->amount, 2, ',', '.') }}
                    </li>
                @endforeach
            </ul>

            <p>Para acessar mais detalhes e realizar o pagamento, acesse o sistema através do link abaixo:</p>

            <p>
                <a href="{{ config('app.url') }}/health-plans/billing/{{ $batch->id }}">
                    Acessar Cobrança
                </a>
            </p>
        </div>

        <div class="footer">
            <p>Esta é uma mensagem automática. Por favor, não responda a este email.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html> 