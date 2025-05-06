<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Pagamento</title>
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
        .payment-card {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #20c997;
        }
        .payment-details {
            margin-bottom: 15px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eaeaea;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: bold;
            color: #555;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            margin-top: 10px;
            border-top: 2px solid #ddd;
            font-size: 18px;
            font-weight: bold;
        }
        .button {
            display: inline-block;
            background-color: #20c997;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 15px;
        }
        .button:hover {
            background-color: #1ba87e;
        }
        .receipt-number {
            font-family: monospace;
            font-size: 16px;
            background-color: #e9ecef;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
            margin: 10px 0;
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
            .payment-card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="Logo" class="logo">
        <h1>Recibo de Pagamento</h1>
    </div>

    <div class="content">
        <p>Olá, {{ $patient->name }}!</p>
        
        <p>Agradecemos pelo seu pagamento. Abaixo está o recibo para sua referência:</p>

        <div class="payment-card">
            <h2>Detalhes do Pagamento</h2>
            
            <div class="receipt-number">Recibo nº: {{ $payment->reference_number }}</div>
            
            <div class="payment-details">
                <div class="detail-row">
                    <div class="detail-label">Data do Pagamento:</div>
                    <div>{{ $payment->formatted_date }}</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Método de Pagamento:</div>
                    <div>{{ $payment->payment_method }}</div>
                </div>
                
                @if($payment->card_info)
                <div class="detail-row">
                    <div class="detail-label">Cartão:</div>
                    <div>{{ $payment->card_info }}</div>
                </div>
                @endif
            </div>
            
            <h3>Itens</h3>
            
            @foreach($payment->items as $item)
            <div class="detail-row">
                <div>{{ $item->description }}</div>
                <div>R$ {{ number_format($item->amount, 2, ',', '.') }}</div>
            </div>
            @endforeach
            
            @if($payment->discount > 0)
            <div class="detail-row" style="color: #28a745;">
                <div class="detail-label">Desconto:</div>
                <div>- R$ {{ number_format($payment->discount, 2, ',', '.') }}</div>
            </div>
            @endif
            
            <div class="total-row">
                <div>Total Pago:</div>
                <div>R$ {{ number_format($payment->total_amount, 2, ',', '.') }}</div>
            </div>
        </div>

        <p>Este recibo serve como comprovante do seu pagamento efetuado em nossa clínica.</p>
        
        <p>Para visualizar seu histórico completo de pagamentos ou baixar este recibo em PDF, clique no botão abaixo:</p>

        <a href="{{ $receiptUrl }}" class="button">Ver Recibo Completo</a>
        
        <p style="margin-top: 20px;">Se você tiver qualquer dúvida ou precisar de assistência, nossa equipe financeira está disponível para ajudar:</p>
        <ul>
            <li>Email: {{ $financialEmail }}</li>
            <li>Telefone: {{ $financialPhone }}</li>
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