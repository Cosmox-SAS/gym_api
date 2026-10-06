<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .titulo { color: #1f2937; }
        .nota { color: #6b7280; font-size: 13px; }
    </style>
</head>
<body>
    <h2 class="titulo">🎂 Cumpleaños de hoy: {{ $gymName }}</h2>
    <p>Hola <strong>{{ $adminName }}</strong>, estos clientes cumplen años hoy:</p>

    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Cumple</th>
                <th>Teléfono</th>
            </tr>
        </thead>
        <tbody>
            @foreach($hoy as $b)
            <tr>
                <td>{{ $b['name'] }}</td>
                <td>{{ $b['turning_age'] }} años</td>
                <td>{{ $b['phone'] ?: '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @if(count($proximos) > 0)
        <h3>📅 Próximos 7 días</h3>
        <table>
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Fecha</th>
                    <th>Cumple</th>
                </tr>
            </thead>
            <tbody>
                @foreach($proximos as $b)
                <tr>
                    <td>{{ $b['name'] }}</td>
                    <td>{{ \Carbon\Carbon::parse($b['birthday'])->format('d/m') }}</td>
                    <td>{{ $b['turning_age'] }} años</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="nota">También puedes ver los cumpleaños en el panel principal de la app.</p>
</body>
</html>
