<?php

namespace App\Console\Commands;

use App\Mail\CumpleanosAdminMail;
use App\Models\Gimnasio;
use App\Models\User;
use App\Services\BirthdayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBirthdayReport extends Command
{
    protected $signature = 'gym:cumpleanos';

    protected $description = 'Envía a los administradores de cada gimnasio los cumpleaños de clientes de hoy y de la próxima semana.';

    public function handle(BirthdayService $birthdays): int
    {
        $admins = User::whereNotNull('gimnasio_id')
            ->whereNotNull('email')
            ->get(['id', 'name', 'email', 'gimnasio_id'])
            ->groupBy('gimnasio_id');

        foreach ($admins as $gimnasioId => $gymAdmins) {
            $upcoming = $birthdays->upcoming((int) $gimnasioId, 7);
            $hoy = $upcoming->where('days_until', 0)->values();

            // Solo se envía correo los días en que alguien cumple años.
            if ($hoy->isEmpty()) {
                continue;
            }

            $proximos = $upcoming->where('days_until', '>', 0)->values();
            $gymName = Gimnasio::find($gimnasioId)?->nombre ?? 'Gimnasio';

            foreach ($gymAdmins as $admin) {
                Mail::to($admin->email)->queue(new CumpleanosAdminMail($admin->name, $gymName, $hoy, $proximos));
            }

            $this->info("{$gymName}: {$hoy->count()} cumpleaños hoy, correo a {$gymAdmins->count()} administrador(es).");
            Log::info('Reporte de cumpleaños enviado.', [
                'gimnasio_id' => $gimnasioId,
                'cumpleanos_hoy' => $hoy->count(),
                'administradores' => $gymAdmins->count(),
            ]);
        }

        return self::SUCCESS;
    }
}
