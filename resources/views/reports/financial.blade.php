<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Relatório Financeiro</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .logo {
            max-width: 120px;
            max-height: 60px;
            margin-bottom: 15px;
            filter: brightness(0) invert(1);
        }
        .logo-placeholder {
            margin-bottom: 15px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .logo-placeholder h3 {
            margin: 0;
            color: white;
            font-size: 18px;
            font-weight: 600;
        }
        .header h2 {
            margin: 10px 0 5px 0;
            font-size: 24px;
            font-weight: 600;
        }
        .header p {
            margin: 5px 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        th, td {
            border: 1px solid #e9ecef;
            padding: 12px 8px;
            text-align: left;
        }
        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            font-size: 13px;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #666;
            background: white;
            padding: 10px;
            border-top: 1px solid #e9ecef;
        }
        .page-break {
            page-break-after: always;
        }
        .summary {
            margin-bottom: 30px;
        }
        .summary h3 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 8px;
        }
        .summary table {
            width: auto;
            margin: 0;
        }
        .metrics {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            gap: 15px;
        }
        .metric-box {
            border: 1px solid #e9ecef;
            padding: 20px;
            text-align: center;
            width: 30%;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s;
        }
        .metric-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .metric-value {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        .metric-label {
            font-size: 13px;
            color: #666;
            font-weight: 500;
        }
        .amount {
            text-align: right;
            font-weight: 600;
        }
        .total {
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .status-paid {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status-overdue {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .status-processing {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .status-cancelled {
            background-color: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #667eea;
        }
        .highlight {
            background-color: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }
        .currency {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        @page {
            size: A4 landscape;
            margin: 1cm;
        }
        @media print {
            body {
                background-color: white;
            }
            .content {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            .metric-box {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        @if(file_exists(public_path('logo.png')))
            <img src="{{ public_path('logo.png') }}" class="logo" alt="Logo">
        @else
            <div class="logo-placeholder">
                <h3>{{ config('app.name', 'Sistema Médico') }}</h3>
            </div>
        @endif
        <h2>Relatório Financeiro</h2>
        <p>Gerado em: {{ now()->format('d/m/Y H:i:s') }}</p>
        <p>Período: {{ isset($filters['start_date']) ? \Carbon\Carbon::parse($filters['start_date'])->format('d/m/Y') : 'Início' }} a {{ isset($filters['end_date']) ? \Carbon\Carbon::parse($filters['end_date'])->format('d/m/Y') : 'Fim' }}</p>
    </div>

    <div class="content">
        <div class="summary">
            <h3>📊 Resumo Executivo</h3>
            <div class="metrics">
                <div class="metric-box">
                    <div class="metric-value">{{ $data->count() }}</div>
                    <div class="metric-label">Total de Lotes</div>
                </div>
                <div class="metric-box">
                    <div class="metric-value">{{ number_format($data->sum('total_items'), 0, ',', '.') }}</div>
                    <div class="metric-label">Total de Itens</div>
                </div>
                <div class="metric-box">
                    <div class="metric-value currency">R$ {{ number_format($data->sum('total_amount'), 2, ',', '.') }}</div>
                    <div class="metric-label">Valor Total</div>
                </div>
            </div>

            <div class="highlight">
                <h4>💡 Principais Indicadores</h4>
                <p><strong>Valor Médio por Lote:</strong> R$ {{ $data->count() > 0 ? number_format($data->sum('total_amount') / $data->count(), 2, ',', '.') : '0,00' }}</p>
                <p><strong>Itens Médios por Lote:</strong> {{ $data->count() > 0 ? number_format($data->sum('total_items') / $data->count(), 0, ',', '.') : '0' }}</p>
            </div>

            <h4 class="section-title">📈 Distribuição por Status</h4>
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Quantidade</th>
                        <th>% do Total</th>
                        <th>Valor Total</th>
                        <th>Valor Médio</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $statuses = $data->groupBy('status');
                        $total = $data->count();
                        $totalAmount = $data->sum('total_amount');
                    @endphp
                    @foreach($statuses as $status => $batches)
                    <tr>
                        <td>
                            <span class="status-badge status-{{ strtolower($status) }}">
                                {{ $status }}
                            </span>
                        </td>
                        <td>{{ $batches->count() }}</td>
                        <td>{{ number_format(($batches->count() / $total) * 100, 1) }}%</td>
                        <td class="amount currency">R$ {{ number_format($batches->sum('total_amount'), 2, ',', '.') }}</td>
                        <td class="amount currency">R$ {{ $batches->count() > 0 ? number_format($batches->sum('total_amount') / $batches->count(), 2, ',', '.') : '0,00' }}</td>
                    </tr>
                    @endforeach
                    <tr class="total">
                        <td colspan="2"><strong>Total</strong></td>
                        <td><strong>100%</strong></td>
                        <td class="amount currency"><strong>R$ {{ number_format($totalAmount, 2, ',', '.') }}</strong></td>
                        <td class="amount currency"><strong>R$ {{ $total > 0 ? number_format($totalAmount / $total, 2, ',', '.') : '0,00' }}</strong></td>
                    </tr>
                </tbody>
            </table>

            <h4 class="section-title">🏥 Top 10 Planos de Saúde</h4>
            <table>
                <thead>
                    <tr>
                        <th>Plano de Saúde</th>
                        <th>Lotes</th>
                        <th>Itens</th>
                        <th>Valor Total</th>
                        <th>% do Total</th>
                        <th>Valor Médio</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $healthPlans = $data->groupBy('health_plan_name')
                            ->map(function($batches) {
                                return [
                                    'count' => $batches->count(),
                                    'items' => $batches->sum('total_items'),
                                    'amount' => $batches->sum('total_amount')
                                ];
                            })
                            ->sortByDesc('amount')
                            ->take(10);
                    @endphp
                    @foreach($healthPlans as $plan => $stats)
                    <tr>
                        <td><strong>{{ $plan ?: 'Não informado' }}</strong></td>
                        <td>{{ $stats['count'] }}</td>
                        <td>{{ number_format($stats['items'], 0, ',', '.') }}</td>
                        <td class="amount currency">R$ {{ number_format($stats['amount'], 2, ',', '.') }}</td>
                        <td>{{ number_format(($stats['amount'] / $totalAmount) * 100, 1) }}%</td>
                        <td class="amount currency">R$ {{ $stats['count'] > 0 ? number_format($stats['amount'] / $stats['count'], 2, ',', '.') : '0,00' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h3 class="section-title">📋 Lista Detalhada de Lotes</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Data Faturamento</th>
                    <th>Vencimento</th>
                    <th>Plano de Saúde</th>
                    <th>Status</th>
                    <th>Itens</th>
                    <th>Valor Total</th>
                    <th>Valor Médio</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data as $batch)
                <tr>
                    <td><strong>#{{ $batch->id }}</strong></td>
                    <td>{{ \Carbon\Carbon::parse($batch->billing_date)->format('d/m/Y') }}</td>
                    <td>{{ \Carbon\Carbon::parse($batch->due_date)->format('d/m/Y') }}</td>
                    <td>{{ $batch->health_plan_name ?: 'Não informado' }}</td>
                    <td>
                        <span class="status-badge status-{{ strtolower($batch->status) }}">
                            {{ $batch->status }}
                        </span>
                    </td>
                    <td>{{ number_format($batch->total_items, 0, ',', '.') }}</td>
                    <td class="amount currency">R$ {{ number_format($batch->total_amount, 2, ',', '.') }}</td>
                    <td class="amount currency">R$ {{ $batch->total_items > 0 ? number_format($batch->total_amount / $batch->total_items, 2, ',', '.') : '0,00' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="footer">
        <p>{{ config('app.name') }} - Relatório Financeiro - Página {PAGENO}</p>
    </div>
</body>
</html> 