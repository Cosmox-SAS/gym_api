<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .card { background: #f9f9f9; padding: 20px; border-radius: 8px; border-left: 5px solid #ef4444; }
        .nota { color: #6b7280; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Hola, {{ $member->name }}</h2>
        <p>Te escribimos de <strong>{{ $gymName }}</strong>.</p>

        <p>Tu membresía venció el <strong>{{ $fechaVencimiento->format('d/m/Y') }}</strong> y, como no hemos
           registrado tu pago, quedó <strong>suspendida</strong>.</p>

        <p>Acércate a recepción para renovarla y volver a entrenar. ¡Te esperamos!</p>

        <p class="nota">Si ya realizaste el pago, comunícate con el gimnasio para actualizar tu estado.</p>
    </div>
</body>
</html>
