<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Membership;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail; // <-- 1. IMPORTAR MAIL

// 2. IMPORTAR TUS FUTUROS MAILABLES (Asegúrate de crearlos con `php artisan make:mail`)
use App\Mail\MembershipExpiringSoon;
use App\Mail\MembershipExpired;
use App\Mail\MembershipSuspended;


class UpdateMembershipStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-membership-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualiza el estado de las membresías basado en la fecha de vencimiento y envía notificaciones.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando actualización de estados de membresías...');
        Log::info('Iniciando [app:update-membership-status]...');

        $now = Carbon::now();

        // 1. CANCELAR: Membresías vencidas hace 30 días o más
        $cancelled = Membership::with('member')
            ->whereIn('status', ['active', 'expired', 'inactive_unpaid'])
            ->whereDate('end_date', '<=', $now->copy()->subDays(30)->toDateString())
            ->get();

        foreach ($cancelled as $membership) {
            $membership->status = 'cancelled';
            $membership->save();

            Log::info("Membresía de [{$membership->member->name}] cancelada por superar 30 días vencida.");
        }

        // 2. NOTIFICAR: Membresías que vencen en 3 días (Recordatorio)
        $expiringSoon = Membership::with('member')
            ->where('status', 'active')
            ->whereDate('end_date', '=', $now->copy()->addDays(3)->toDateString())
            ->get();

        foreach ($expiringSoon as $membership) {

            // --- INICIO MODIFICACIÓN ---
            // Solo enviar si el miembro tiene un email registrado
            if ($membership->member && $membership->member->email) {
                 Mail::to($membership->member->email)->queue(new MembershipExpiringSoon($membership));
            }
            // --- FIN MODIFICACIÓN ---

            Log::info("Notificando a [{$membership->member->name}]: Su membresía vence en 3 días.");
            // TODO: Notificar al Admin (puedes guardar esto en una tabla 'notifications' o similar)
        }

        // 3. VENCER: Membresías que vencieron ayer o antes y siguen 'activas'
        $expired = Membership::with('member')
            ->where('status', 'active')
            ->whereDate('end_date', '<', $now->toDateString())
            ->get();

        foreach ($expired as $membership) {
            $membership->status = 'expired';
            $membership->save();

            // --- INICIO MODIFICACIÓN ---
            if ($membership->member && $membership->member->email) {
                Mail::to($membership->member->email)->queue(new MembershipExpired($membership));
            }
            // --- FIN MODIFICACIÓN ---

            Log::info("Membresía de [{$membership->member->name}] ha vencido. Estado -> expired.");
        }

        // 4. SUSPENDER: Membresías 'vencidas' por más de 3 días (Período de gracia)
        // (Esta lógica ya estaba correcta)
        $suspended = Membership::with('member')
            ->where('status', 'expired')
            ->whereDate('end_date', '<=', $now->copy()->subDays(3)->toDateString())
            ->get();

        foreach ($suspended as $membership) {
            $membership->status = 'inactive_unpaid';
            $membership->save();

            // --- INICIO MODIFICACIÓN ---
            if ($membership->member && $membership->member->email) {
                Mail::to($membership->member->email)->queue(new MembershipSuspended($membership));
            }
            // --- FIN MODIFICACIÓN ---

            Log::info("Membresía de [{$membership->member->name}] suspendida por falta de pago. Estado -> inactive_unpaid.");
        }

        $this->info('Actualización de estados completada.');
        Log::info('Finalizado [app:update-membership-status].');
    }
}
