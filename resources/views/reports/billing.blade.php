<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Relatório de Faturamento</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo {
            max-width: 150px;
            margin-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f5f5f5;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        .page-break {
            page-break-after: always;
        }
        .summary {
            margin-bottom: 20px;
        }
        .summary table {
            width: auto;
        }
        .metrics {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .metric-box {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
            width: 30%;
        }
        .metric-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        .metric-label {
            font-size: 12px;
            color: #666;
        }
        .amount {
            text-align: right;
        }
        .total {
            font-weight: bold;
            background-color: #f9f9f9;
        }
        .status-pending {
            color: #f39c12;
        }
        .status-paid {
            color: #27ae60;
        }
        .status-overdue {
            color: #c0392b;
        }
    </style>
</head>
<body>
    <div class="header">
        @if(config('app.logo'))
            <img src="{{ config('app.logo') }}" class="logo">
        @endif
        <h2>Relatório de Faturamento</h2>
        <p>Gerado em: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

    <div class="summary">
        <h3>Resumo</h3>
        <div class="metrics">
            <div class="metric-box">
                <div class="metric-value">{{ $data->count() }}</div>
                <div class="metric-label">Total de Itens</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">{{ number_format($data->sum('quantity'), 0, ',', '.') }}</div>
                <div class="metric-label">Quantidade Total</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">R$ {{ number_format($data->sum('total_amount'), 2, ',', '.') }}</div>
                <div class="metric-label">Valor Total</div>
            </div>
        </div>

        <h4>Distribuição por Status</h4>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Quantidade</th>
                    <th>% do Total</th>
                    <th>Valor Total</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $statuses = $data->groupBy('status');
                    $total = $data->count();
                    $totalAmount = $data->sum('total_amount');
                @endphp
                @foreach($statuses as $status => $items)
                <tr>
                    <td>{{ $status }}</td>
                    <td>{{ $items->count() }}</td>
                    <td>{{ number_format(($items->count() / $total) * 100, 1) }}%</td>
                    <td class="amount">R$ {{ number_format($items->sum('total_amount'), 2, ',', '.') }}</td>
                </tr>
                @endforeach
                <tr class="total">
                    <td colspan="2">Total</td>
                    <td>100%</td>
                    <td class="amount">R$ {{ number_format($totalAmount, 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        <h4>Top 10 Planos de Saúde</h4>
        <table>
            <thead>
                <tr>
                    <th>Plano de Saúde</th>
                    <th>Itens</th>
                    <th>Quantidade</th>
                    <th>Valor Total</th>
                    <th>% do Total</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $healthPlans = $data->groupBy('health_plan_name')
                        ->map(function($items) {
                            return [
                                'count' => $items->count(),
                                'quantity' => $items->sum('quantity'),
                                'amount' => $items->sum('total_amount')
                            ];
                        })
                        ->sortByDesc('amount')
                        ->take(10);
                @endphp
                @foreach($healthPlans as $plan => $stats)
                <tr>
                    <td>{{ $plan }}</td>
                    <td>{{ $stats['count'] }}</td>
                    <td>{{ number_format($stats['quantity'], 0, ',', '.') }}</td>
                    <td class="amount">R$ {{ number_format($stats['amount'], 2, ',', '.') }}</td>
                    <td>{{ number_format(($stats['amount'] / $totalAmount) * 100, 1) }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h3>Lista de Itens</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Descrição</th>
                <th>Plano de Saúde</th>
                <th>Data Faturamento</th>
                <th>Vencimento</th>
                <th>Status</th>
                <th>Quantidade</th>
                <th>Valor Unit.</th>
                <th>Valor Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $item)
            <tr>
                <td>{{ $item->id }}</td>
                <td>{{ $item->description }}</td>
                <td>{{ $item->health_plan_name }}</td>
                <td>{{ \Carbon\Carbon::parse($item->billing_date)->format('d/m/Y') }}</td>
                <td>{{ \Carbon\Carbon::parse($item->due_date)->format('d/m/Y') }}</td>
                <td class="status-{{ strtolower($item->status) }}">{{ $item->status }}</td>
                <td>{{ number_format($item->quantity, 0, ',', '.') }}</td>
                <td class="amount">R$ {{ number_format($item->unit_price, 2, ',', '.') }}</td>
                <td class="amount">R$ {{ number_format($item->total_amount, 2, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>{{ config('app.name') }} - Página {PAGENO}</p>
    </div>
</body>
</html> 