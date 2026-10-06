<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Schedule; // <-- AÑADIR ESTA LÍNEA

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php', // Esto está perfecto
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'service.key' => \App\Http\Middleware\ServiceKeyMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    // --- AÑADIR ESTE BLOQUE COMPLETO ---
    ->withSchedule(function ($schedule) {
        // Aquí es donde "registramos" nuestro piloto automático para
        // que se ejecute todos los días a las 8:00 AM.
        $schedule->command('app:update-membership-status')->dailyAt('08:00');
        // Correo a los administradores con los cumpleaños del día.
        $schedule->command('gym:cumpleanos')->dailyAt('07:00');
    })
    // -------------------------------------
    ->create();
