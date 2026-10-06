<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .card { background: #f9f9f9; padding: 20px; border-radius: 8px; border-left: 5px solid #f59e0b; }
        .nota { color: #6b7280; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Hola, {{ $member->name }} 👋</h2>
        <p>Te escribimos de <strong>{{ $gymName }}</strong>.</p>

        <p>Tu membresía venció el <strong>{{ $fechaVencimiento->format('d/m/Y') }}</strong>.</p>

        <p>Para seguir entrenando sin interrupciones, renueva tu plan en recepción antes del
           <strong>{{ $fechaVencimiento->copy()->addDays(3)->format('d/m/Y') }}</strong>.</p>

        <p class="nota">Si ya realizaste el pago, puedes ignorar este mensaje.</p>
    </div>
</body>
</html>
