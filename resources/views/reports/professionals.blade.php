<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Relatório de Profissionais</title>
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
    </style>
</head>
<body>
    <div class="header">
        @if(config('app.logo'))
            <img src="{{ config('app.logo') }}" class="logo">
        @endif
        <h2>Relatório de Profissionais</h2>
        <p>Gerado em: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

    <div class="summary">
        <h3>Resumo</h3>
        <div class="metrics">
            <div class="metric-box">
                <div class="metric-value">{{ $data->count() }}</div>
                <div class="metric-label">Total de Profissionais</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">{{ $data->where('status', 'approved')->count() }}</div>
                <div class="metric-label">Profissionais Ativos</div>
            </div>
            <div class="metric-box">
                <div class="metric-value">{{ $data->sum('total_appointments') }}</div>
                <div class="metric-label">Total de Atendimentos</div>
            </div>
        </div>

        <h4>Distribuição por Especialidade</h4>
        <table>
            <thead>
                <tr>
                    <th>Especialidade</th>
                    <th>Quantidade</th>
                    <th>% do Total</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $specialties = $data->groupBy('specialty');
                    $total = $data->count();
                @endphp
                @foreach($specialties as $specialty => $professionals)
                <tr>
                    <td>{{ $specialty ?: 'Não informada' }}</td>
                    <td>{{ $professionals->count() }}</td>
                    <td>{{ number_format(($professionals->count() / $total) * 100, 1) }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h3>Lista de Profissionais</h3>
    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <th>CPF</th>
                <th>Especialidade</th>
                <th>CRM/CRO</th>
                <th>Clínica</th>
                <th>Status</th>
                <th>Atendimentos</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $professional)
            <tr>
                <td>{{ $professional->name }}</td>
                <td>{{ $professional->cpf }}</td>
                <td>{{ $professional->specialty }}</td>
                <td>{{ $professional->council_number }}</td>
                <td>{{ $professional->clinic_name }}</td>
                <td>{{ $professional->status }}</td>
                <td>{{ $professional->total_appointments }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>{{ config('app.name') }} - Página {PAGENO}</p>
    </div>
</body>
</html> 