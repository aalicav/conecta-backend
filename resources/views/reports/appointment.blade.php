<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Relatório de Agendamentos</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .stats-container {
            margin-bottom: 30px;
        }
        .stat-row {
            display: block;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            padding: 10px;
        }
        .stat-label {
            font-weight: bold;
            margin-right: 10px;
        }
        .graph-container {
            margin-bottom: 30px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f5f5f5;
        }
        .chart-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            background-color: #f5f5f5;
            padding: 10px;
        }
        @page {
            size: landscape;
        }
    </style>
</head>
<body>
    @php
        $totalAppointments = $statistics['total_appointments'] ?? 0;
        $attendanceRate = isset($statistics['attendance_rate']) && $totalAppointments > 0
            ? round(($statistics['attendance_rate']['attended'] / $totalAppointments) * 100, 1)
            : 0;
        $attended = $statistics['attendance_rate']['attended'] ?? 0;
        $notAttended = $statistics['attendance_rate']['not_attended'] ?? 0;
    @endphp

    <div class="header">
        <h1>Relatório de Agendamentos</h1>
        <p>Gerado em: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

    <!-- Summary Statistics -->
    <div class="stats-container">
        <div class="stat-row">
            <span class="stat-label">Total de Agendamentos:</span>
            <span>{{ $totalAppointments }}</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">Taxa de Comparecimento:</span>
            <span>{{ $attendanceRate }}%</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">Compareceram:</span>
            <span>{{ $attended }}</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">Não Compareceram:</span>
            <span>{{ $notAttended }}</span>
        </div>
    </div>

    <!-- Status Distribution -->
    <div class="graph-container">
        <div class="chart-title">Distribuição por Status</div>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Quantidade</th>
                    <th>Porcentagem</th>
                </tr>
            </thead>
            <tbody>
                @foreach($statistics['status_distribution'] ?? [] as $status => $count)
                <tr>
                    <td>{{ $status }}</td>
                    <td>{{ $count }}</td>
                    <td>{{ $totalAppointments > 0 ? round(($count / $totalAppointments) * 100, 1) : 0 }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Daily Distribution -->
    <div class="graph-container">
        <div class="chart-title">Distribuição Diária</div>
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Quantidade</th>
                    <th>Porcentagem do Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($statistics['daily_distribution'] ?? [] as $date => $count)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</td>
                    <td>{{ $count }}</td>
                    <td>{{ $totalAppointments > 0 ? round(($count / $totalAppointments) * 100, 1) : 0 }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Data Table -->
    <div class="chart-title">Lista de Agendamentos</div>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Paciente</th>
                <th>Prestador</th>
                <th>Plano de Saúde</th>
                <th>Endereço</th>
                <th>Status</th>
                <th>Compareceu</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data ?? [] as $appointment)
            <tr>
                <td>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i') }}</td>
                <td>{{ $appointment->patient_name }}</td>
                <td>{{ $appointment->provider_name }}</td>
                <td>{{ $appointment->health_plan_name }}</td>
                <td>
                    @if($appointment->street)
                        {{ $appointment->street }}, {{ $appointment->number }}
                        @if($appointment->complement)
                            - {{ $appointment->complement }}
                        @endif
                        <br>
                        {{ $appointment->neighborhood }} - {{ $appointment->address_city }}/{{ $appointment->address_state }}
                    @else
                        Não informado
                    @endif
                </td>
                <td>{{ $appointment->status }}</td>
                <td>{{ $appointment->patient_attended ? 'Sim' : 'Não' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="7" style="text-align: center;">Nenhum agendamento encontrado</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html> 