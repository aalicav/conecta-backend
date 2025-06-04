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
        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-table th {
            text-align: left;
            padding: 8px;
            background: #e5e7eb;
            font-weight: bold;
        }
        .summary-table td {
            padding: 8px;
            border-bottom: 1px solid #eee;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .main-table th {
            background: #2563eb;
            color: white;
            padding: 12px 8px;
            text-align: left;
            font-size: 14px;
        }
        .main-table td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        .main-table tr:nth-child(even) {
            background: #f8fafc;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .no-data {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
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
        <h2>Resumo</h2>
        <table class="summary-table">
            @foreach($summary as $key => $value)
            <tr>
                <th>{{ ucwords(str_replace('_', ' ', $key)) }}</th>
                <td>{{ is_numeric($value) ? number_format($value, 2, ',', '.') : $value }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    @if(!empty($data))
        <table class="main-table">
            <thead>
                <tr>
                    @foreach(array_keys($data[0]) as $header)
                        <th>{{ ucwords(str_replace('_', ' ', $header)) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($data as $row)
                    <tr>
                        @foreach($row as $cell)
                            <td>{{ is_numeric($cell) ? number_format($cell, 2, ',', '.') : $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="no-data">
            Nenhum dado disponível para este relatório.
        </div>
    @endif

    <div class="footer">
        <p>Este é um relatório gerado automaticamente pelo sistema.</p>
    </div>
</body>
</html> 