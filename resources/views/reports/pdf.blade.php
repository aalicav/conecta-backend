<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $report->name }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
        }
        .header h1 {
            color: #2563eb;
            margin: 0;
            padding: 0;
            font-size: 24px;
        }
        .header p {
            color: #666;
            margin: 10px 0 0;
            font-size: 14px;
        }
        .summary {
            margin-bottom: 30px;
            background: #f8fafc;
            padding: 15px;
            border-radius: 5px;
        }
        .summary h2 {
            color: #1e40af;
            margin: 0 0 15px;
            font-size: 18px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .summary-box {
            background: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .summary-box h3 {
            color: #1e40af;
            margin: 0 0 10px;
            font-size: 16px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 12px;
        }
        .breakdown-table th {
            background: #e5e7eb;
            text-align: left;
            padding: 8px;
        }
        .breakdown-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #eee;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 11px;
        }
        .main-table th {
            background: #2563eb;
            color: white;
            padding: 8px 4px;
            text-align: left;
            font-size: 11px;
            white-space: nowrap;
        }
        .main-table td {
            padding: 6px 4px;
            border-bottom: 1px solid #eee;
        }
        .main-table tr:nth-child(even) {
            background: #f8fafc;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        .no-data {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .highlight {
            font-weight: bold;
            color: #2563eb;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $report->name }}</h1>
        <p>Gerado em: {{ $generatedAt }}</p>
    </div>

    @if($summary)
    <div class="summary">
        <h2>Resumo do Período</h2>
        <div class="summary-box">
            <table class="breakdown-table">
                <tr>
                    <th>Período:</th>
                    <td>{{ $summary['Período'] ?? 'N/A' }}</td>
                    <th>Total de Transações:</th>
                    <td>{{ $summary['Total de Transações'] ?? '0' }}</td>
                </tr>
                <tr>
                    <th>Valor Total (Bruto):</th>
                    <td>R$ {{ isset($summary['Valor Total (Bruto)']) ? number_format($summary['Valor Total (Bruto)'], 2, ',', '.') : '0,00' }}</td>
                    <th>Valor Total (Líquido):</th>
                    <td>R$ {{ isset($summary['Valor Total (Líquido)']) ? number_format($summary['Valor Total (Líquido)'], 2, ',', '.') : '0,00' }}</td>
                </tr>
                <tr>
                    <th>Total Descontos:</th>
                    <td>R$ {{ isset($summary['Total Descontos']) ? number_format($summary['Total Descontos'], 2, ',', '.') : '0,00' }}</td>
                    <th>Total Glosas:</th>
                    <td>R$ {{ isset($summary['Total Glosas']) ? number_format($summary['Total Glosas'], 2, ',', '.') : '0,00' }}</td>
                </tr>
                <tr>
                    <th>Total Recebido:</th>
                    <td class="highlight">R$ {{ isset($summary['Total Recebido']) ? number_format($summary['Total Recebido'], 2, ',', '.') : '0,00' }}</td>
                    <th>Total Pendente:</th>
                    <td>R$ {{ isset($summary['Total Pendente']) ? number_format($summary['Total Pendente'], 2, ',', '.') : '0,00' }}</td>
                </tr>
            </table>
        </div>

        @if(isset($payment_methods))
        <div class="summary-grid">
            <div class="summary-box">
                <h3>Formas de Pagamento</h3>
                <table class="breakdown-table">
                    <tr>
                        <th>Método</th>
                        <th>Quantidade</th>
                        <th>Total</th>
                    </tr>
                    @foreach($payment_methods as $method => $data)
                    <tr>
                        <td>{{ ucfirst($method ?: 'N/A') }}</td>
                        <td class="text-center">{{ $data['count'] }}</td>
                        <td class="text-right">R$ {{ number_format($data['total'], 2, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </table>
            </div>

            <div class="summary-box">
                <h3>Convênios</h3>
                <table class="breakdown-table">
                    <tr>
                        <th>Convênio</th>
                        <th>Quantidade</th>
                        <th>Total</th>
                    </tr>
                    @foreach($health_plans ?? [] as $plan => $data)
                    <tr>
                        <td>{{ $plan }}</td>
                        <td class="text-center">{{ $data['count'] }}</td>
                        <td class="text-right">R$ {{ number_format($data['total'], 2, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </table>
            </div>
        </div>
        @endif
    </div>
    @endif

    @php
        $hasData = false;
        $headers = [];
        
        if (is_array($data) && !empty($data)) {
            $hasData = true;
            $headers = array_keys($data[0]);
        } elseif (is_object($data) && method_exists($data, 'count') && $data->count() > 0) {
            $hasData = true;
            $firstItem = $data->first();
            $headers = array_keys(is_array($firstItem) ? $firstItem : (array)$firstItem);
        }
    @endphp

    @if($hasData)
        <h2>Detalhamento das Transações</h2>
        <table class="main-table">
            <thead>
                <tr>
                    @foreach($headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($data as $row)
                    @php
                        $rowData = is_array($row) ? $row : (array)$row;
                    @endphp
                    <tr>
                        @foreach($rowData as $key => $cell)
                            <td @if(in_array($key, ['Valor Base', 'Desconto', 'Glosa', 'Valor Total'])) class="text-right" @endif>
                                @if(in_array($key, ['Valor Base', 'Desconto', 'Glosa', 'Valor Total']))
                                    R$ {{ is_numeric($cell) ? number_format($cell, 2, ',', '.') : $cell }}
                                @else
                                    {{ $cell }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="no-data">
            Nenhum dado disponível para este relatório no período selecionado.
        </div>
    @endif

    <div class="footer">
        <p>Este relatório foi gerado automaticamente pelo sistema em {{ $generatedAt }}</p>
    </div>
</body>
</html> 