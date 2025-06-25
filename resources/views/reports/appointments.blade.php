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
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .stat-box {
            border: 1px solid #ddd;
            padding: 15px;
            text-align: center;
            width: 23%;
        }
        .graph-container {
            margin-bottom: 30px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
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
        }
        @page {
            size: landscape;
        }
    </style>
    <!-- Include Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="header">
        <h1>Relatório de Agendamentos</h1>
        <p>Gerado em: {{ now()->format('d/m/Y H:i:s') }}</p>
    </div>

    <!-- Summary Statistics -->
    <div class="stats-container">
        <div class="stat-box">
            <h3>Total de Agendamentos</h3>
            <h2>{{ $statistics['total_appointments'] }}</h2>
        </div>
        <div class="stat-box">
            <h3>Taxa de Comparecimento</h3>
            <h2>{{ round(($statistics['attendance_rate']['attended'] / max(1, $statistics['total_appointments'])) * 100, 1) }}%</h2>
        </div>
        <div class="stat-box">
            <h3>Compareceram</h3>
            <h2>{{ $statistics['attendance_rate']['attended'] }}</h2>
        </div>
        <div class="stat-box">
            <h3>Não Compareceram</h3>
            <h2>{{ $statistics['attendance_rate']['not_attended'] }}</h2>
        </div>
    </div>

    <!-- Graphs -->
    <div class="graph-container">
        <div class="chart-title">Distribuição por Status</div>
        <canvas id="statusChart"></canvas>
    </div>

    <div class="graph-container">
        <div class="chart-title">Distribuição Diária</div>
        <canvas id="dailyChart"></canvas>
    </div>

    <!-- Data Table -->
    <h2>Lista de Agendamentos</h2>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Paciente</th>
                <th>Prestador</th>
                <th>Plano de Saúde</th>
                <th>Status</th>
                <th>Compareceu</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $appointment)
            <tr>
                <td>{{ \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i') }}</td>
                <td>{{ $appointment->patient_name }}</td>
                <td>{{ $appointment->provider_name }}</td>
                <td>{{ $appointment->health_plan_name }}</td>
                <td>{{ $appointment->status }}</td>
                <td>{{ $appointment->patient_attended ? 'Sim' : 'Não' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <script>
        // Status Distribution Chart
        var statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: {!! json_encode(array_keys($statistics['status_distribution']->toArray())) !!},
                datasets: [{
                    data: {!! json_encode(array_values($statistics['status_distribution']->toArray())) !!},
                    backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0']
                }]
            }
        });

        // Daily Distribution Chart
        var dailyCtx = document.getElementById('dailyChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: {!! json_encode(array_keys($statistics['daily_distribution']->toArray())) !!},
                datasets: [{
                    label: 'Agendamentos por Dia',
                    data: {!! json_encode(array_values($statistics['daily_distribution']->toArray())) !!},
                    borderColor: '#36A2EB',
                    fill: false
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html> 