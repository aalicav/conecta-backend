<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Relatório de Clínicas</title>
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
        .chart {
            width: 100%;
            height: 200px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        @if(config('app.logo'))
            <img src="{{ config('app.logo') }}" class="logo">
        @endif
        <h2>Relatório de Clínicas</h2>
        <p>Gerado em: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

    <div class="summary">
        <h3>Resumo</h3>
        <div class="metrics">
            <div class="metric-box">
                <div class="metric-value">{{ $data->count() }}</div>
                <div class="metric-label">Total de Clínicas</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">{{ $data->sum('total_professionals') }}</div>
                <div class="metric-label">Total de Profissionais</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">{{ $data->sum('total_appointments') }}</div>
                <div class="metric-label">Total de Atendimentos</div>
            </div>
        </div>

        <h4>Distribuição por Estado</h4>
        <table>
            <thead>
                <tr>
                    <th>Estado</th>
                    <th>Quantidade</th>
                    <th>% do Total</th>
                    <th>Profissionais</th>
                    <th>Atendimentos</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $states = $data->groupBy('state');
                    $total = $data->count();
                @endphp
                @foreach($states as $state => $clinics)
                <tr>
                    <td>{{ $state ?: 'Não informado' }}</td>
                    <td>{{ $clinics->count() }}</td>
                    <td>{{ number_format(($clinics->count() / $total) * 100, 1) }}%</td>
                    <td>{{ $clinics->sum('total_professionals') }}</td>
                    <td>{{ $clinics->sum('total_appointments') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <h4>Top 10 Cidades</h4>
        <table>
            <thead>
                <tr>
                    <th>Cidade</th>
                    <th>Estado</th>
                    <th>Clínicas</th>
                    <th>Profissionais</th>
                    <th>Atendimentos</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $cities = $data->groupBy('city')
                        ->map(function($clinics) {
                            return [
                                'count' => $clinics->count(),
                                'state' => $clinics->first()->state,
                                'professionals' => $clinics->sum('total_professionals'),
                                'appointments' => $clinics->sum('total_appointments')
                            ];
                        })
                        ->sortByDesc('count')
                        ->take(10);
                @endphp
                @foreach($cities as $city => $stats)
                <tr>
                    <td>{{ $city ?: 'Não informada' }}</td>
                    <td>{{ $stats['state'] }}</td>
                    <td>{{ $stats['count'] }}</td>
                    <td>{{ $stats['professionals'] }}</td>
                    <td>{{ $stats['appointments'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h3>Lista de Clínicas</h3>
    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <th>CNPJ</th>
                <th>Cidade</th>
                <th>Estado</th>
                <th>Status</th>
                <th>Profissionais</th>
                <th>Atendimentos</th>
                <th>Taxa de Conclusão</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $clinic)
            <tr>
                <td>{{ $clinic->name }}</td>
                <td>{{ $clinic->cnpj }}</td>
                <td>{{ $clinic->city }}</td>
                <td>{{ $clinic->state }}</td>
                <td>{{ $clinic->status }}</td>
                <td>{{ $clinic->total_professionals }}</td>
                <td>{{ $clinic->total_appointments }}</td>
                <td>
                    @if($clinic->total_appointments > 0)
                        {{ number_format(($clinic->completed_appointments / $clinic->total_appointments) * 100, 1) }}%
                    @else
                        0%
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>{{ config('app.name') }} - Página {PAGENO}</p>
    </div>
</body>
</html> 