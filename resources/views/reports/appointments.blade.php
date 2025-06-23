<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Relatório de Agendamentos</title>
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
    </style>
</head>
<body>
    <div class="header">
        @if(config('app.logo'))
            <img src="{{ config('app.logo') }}" class="logo">
        @endif
        <h2>Relatório de Agendamentos</h2>
        <p>Gerado em: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

    <div class="summary">
        <h3>Resumo</h3>
        <table>
            <tr>
                <th>Total de Agendamentos</th>
                <td>{{ $data->count() }}</td>
            </tr>
            <tr>
                <th>Agendamentos Concluídos</th>
                <td>{{ $data->where('status', 'completed')->count() }}</td>
            </tr>
            <tr>
                <th>Pacientes Ausentes</th>
                <td>{{ $data->where('patient_attended', false)->count() }}</td>
            </tr>
        </table>
    </div>

    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Paciente</th>
                <th>Documento</th>
                <th>Prestador</th>
                <th>Plano</th>
                <th>Status</th>
                <th>Compareceu</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $appointment)
            <tr>
                <td>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i') }}</td>
                <td>{{ $appointment->patient_name }}</td>
                <td>{{ $appointment->patient_document }}</td>
                <td>{{ $appointment->provider_name }}</td>
                <td>{{ $appointment->health_plan_name }}</td>
                <td>{{ $appointment->status }}</td>
                <td>{{ $appointment->patient_attended ? 'Sim' : 'Não' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>{{ config('app.name') }} - Página {PAGENO}</p>
    </div>
</body>
</html> 