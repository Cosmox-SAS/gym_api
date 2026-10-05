<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Membership;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene al día el estado de las membresías de un gimnasio:
 *  - vencidas hace 30 días o más  -> cancelled
 *  - vencidas hace más de 3 días  -> inactive_unpaid
 *  - vencidas recientemente       -> expired (y deuda = precio del plan si estaba en 0)
 *
 * Antes esto se hacía fila por fila en cada GET /memberships, bloqueando la tabla
 * en cada visita. Ahora usa updates masivos y se ejecuta como máximo una vez cada
 * pocos minutos por gimnasio.
 */
class MembershipStatusService
{
    public const THROTTLE_SECONDS = 600;

    /**
     * Ejecuta el mantenimiento solo si no se ha hecho en los últimos minutos para este gimnasio.
     */
    public function refreshGymThrottled(int $gimnasioId): void
    {
        if (Cache::add("memberships:status-refresh:{$gimnasioId}", true, self::THROTTLE_SECONDS)) {
            $this->refreshGym($gimnasioId);
        }
    }

    public function refreshGym(int $gimnasioId, ?Carbon $now = null): void
    {
        $now = $now ?? Carbon::now();
        $memberIds = Member::select('id')->where('gimnasio_id', $gimnasioId);

        // 1. Vencidas hace 30 días o más -> canceladas.
        Membership::whereIn('member_id', $memberIds)
            ->whereIn('status', ['active', 'expired', 'inactive_unpaid'])
            ->whereDate('end_date', '<=', $now->copy()->subDays(30)->toDateString())
            ->update(['status' => 'cancelled']);

        $overdue = fn () => Membership::whereIn('member_id', $memberIds)
            ->where('end_date', '<', $now)
            ->whereNotIn('status', ['cancelled', 'inactive_unpaid']);

        // 2. Vencidas hace más de 3 días -> suspendidas por falta de pago.
        $overdue()
            ->where('end_date', '<', $now->copy()->subDays(3))
            ->update(['status' => 'inactive_unpaid']);

        // 3. Vencidas recientemente: la deuda pasa a ser el precio del plan si estaba en 0.
        $overdue()
            ->where('outstanding_balance', '<=', 0)
            ->whereHas('plan')
            ->update([
                'outstanding_balance' => DB::raw(
                    '(SELECT price FROM membership_plans WHERE membership_plans.id = memberships.plan_id)'
                ),
            ]);

        // 4. ...y quedan marcadas como vencidas.
        $overdue()
            ->where('status', '!=', 'expired')
            ->update(['status' => 'expired']);
    }
}
