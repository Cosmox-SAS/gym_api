<?php


namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Services\MembershipStatusService;
use Illuminate\Http\Request;
use Carbon\Carbon;
class MembershipController extends Controller
{


public function index(Request $request)
    {
        $gimnasioId = $request->user()->gimnasio_id;

        // --- 1. RUTINA DE MANTENIMIENTO AUTOMÁTICO ---
        // Actualiza estados vencidos/cancelados con updates masivos, como máximo cada
        // pocos minutos por gimnasio (antes se hacía fila por fila en cada visita).
        app(MembershipStatusService::class)->refreshGymThrottled($gimnasioId);

        // 2. Construir la consulta base
        // Solo los datos del cliente que muestra la vista (sin fotos, huella ni estado calculado).
        $query = Membership::with(['member:id,name,identification,email,phone,gimnasio_id', 'plan.membershipType'])
            ->whereIn('member_id', Member::select('id')->where('gimnasio_id', $gimnasioId));

        // 3. Filtro de búsqueda por nombre de miembro
        $search = $request->input('search');
        if ($search) {
            $query->whereHas('member', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        // 4. Aplicar filtro de estado
        $statusFilter = $request->input('status');

        if ($statusFilter === 'expiring_soon') {
            $query->where('status', 'active')
                  ->whereDate('end_date', '>=', Carbon::now())
                  ->whereDate('end_date', '<=', Carbon::now()->addDays(3));
        } elseif ($statusFilter && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        } else {
            // Vista por defecto: excluir inactivas y canceladas
            $query->whereIn('status', ['active', 'expired', 'inactive_unpaid']);
        }

        // 5. Filtro de frecuencia del plan
        $frequency = $request->input('frequency');
        if ($frequency) {
            $query->whereHas('plan', function ($q) use ($frequency) {
                $q->where('frequency', $frequency);
            });
        }

        // 6. Ordenar por fecha más reciente y paginar
        $memberships = $query->orderByDesc('end_date')->paginate(15);
        $memberships->getCollection()->each(fn ($membership) => $membership->member?->setAppends([]));

        return response()->json($memberships);
    }
 public function store(Request $request)
{
    $validated = $request->validate([
        'member_id' => 'required|exists:members,id',
        'plan_id' => 'required|exists:membership_plans,id',
        // 'end_date' => 'required|date', // -> Eliminado: Esta fecha se calcula abajo
    ]);

    // Verificar si el cliente ya tiene una membresía activa O PENDIENTE (permitimos expired)
    $existing = \App\Models\Membership::where('member_id', $validated['member_id'])
        ->whereIn('status', ['active', 'inactive_unpaid']) // <-- MODIFICADO: Quitamos 'expired'
        ->first();

    if ($existing) {
        return response()->json([
            'error' => 'El cliente ya tiene una membresía activa o pendiente de pago.' // <-- MODIFICADO
        ], 422); // 422 = Unprocessable Entity
    }

    // OPTIONAL: Si el usuario quiere borrar el historial de vencidas al asignar una nueva:
    \App\Models\Membership::where('member_id', $validated['member_id'])
        ->where('status', 'expired')
        ->delete();

    // Obtener el plan para usar su precio como saldo pendiente
    $plan = \App\Models\MembershipPlan::findOrFail($validated['plan_id']);

    $validated['outstanding_balance'] = $plan->price;

    // Asignar fecha de inicio automáticamente
    $validated['start_date'] = now();

     // Calcular fecha de fin según frecuencia
    $fechaFin = now()->copy();
    switch ($plan->frequency) {
        case 'daily':    $fechaFin->addDay(); break;
        case 'weekly':   $fechaFin->addWeek(); break;
        case 'biweekly': $fechaFin->addDays(15); break;
        case 'monthly':  $fechaFin->addMonth()->day($validated['start_date']->day); break;
    }
    $validated['end_date'] = $fechaFin;

    // =================================================================
    // Cuando el admin crea una membresía, también debe estar inactiva
    // y esperar el pago en la vista de Pagos.
        $validated['status'] = 'inactive_unpaid'; // <-- MODIFICADO (antes era 'active')
    // =================================================================


    $membership = \App\Models\Membership::create($validated);

    return response()->json($membership, 201);
}


    public function show($id)
    {
        $membership = Membership::with(['member', 'plan'])->findOrFail($id);
        return response()->json($membership);
    }

    public function update(Request $request, $id)
    {
        $membership = Membership::findOrFail($id);

        $validated = $request->validate([
            'plan_id' => 'sometimes|exists:membership_plans,id',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            // Añadimos los nuevos estados que puede poner el admin
                'status' => 'sometimes|in:active,expired,cancelled,inactive_unpaid', // 'inactive' removed
        ]);

        // Si se cambia el plan mientras la membresía sigue pendiente de pago (o vencida),
        // recalculamos la deuda y la fecha fin tentativa según el plan nuevo, en vez de
        // dejar arrastrado el precio/fecha del plan anterior.
        if (
            array_key_exists('plan_id', $validated) &&
            (int) $validated['plan_id'] !== (int) $membership->plan_id &&
            in_array($membership->status, ['inactive_unpaid', 'expired'])
        ) {
            $plan = MembershipPlan::findOrFail($validated['plan_id']);

            $validated['outstanding_balance'] = $plan->price;

            $fechaInicio = $membership->start_date ? Carbon::parse($membership->start_date) : Carbon::now();
            $fechaFin = $fechaInicio->copy();
            switch ($plan->frequency) {
                case 'daily': case 'diario':       $fechaFin->addDay(); break;
                case 'weekly': case 'semanal':     $fechaFin->addWeek(); break;
                case 'biweekly': case 'quincenal': $fechaFin->addDays(15); break;
                case 'monthly': case 'mensual':    $fechaFin->addMonth(); break;
                case 'quarterly':                  $fechaFin->addMonths(3); break;
                case 'biannual':                   $fechaFin->addMonths(6); break;
                case 'yearly': case 'anual':        $fechaFin->addYear(); break;
                default:                           $fechaFin->addMonth(); break;
            }
            $validated['end_date'] = $fechaFin;
        }

        $membership->update($validated);

        return response()->json($membership);
    }

    public function destroy($id)
    {
        $membership = Membership::findOrFail($id);
        $membership->delete();

        return response()->json(['message' => 'Membership deleted']);
    }

    public function getByMemberId($memberId)
    {
        $membership = Membership::where('member_id', $memberId)
           // MODIFICADO: Incluir 'inactive_unpaid' para que el controlador de pago la encuentre
           ->whereIn('status', ['active', 'expired', 'inactive_unpaid'])
           ->latest('end_date')
           ->first();

         if (!$membership) {
        // Mensaje actualizado
        return response()->json(['error' => 'No se encontró membresía activa, vencida o pendiente.'], 404);
    }

    return response()->json($membership);
    }


    // --- MÉTODO NUEVO PARA EL DASHBOARD DE ADMIN ---

    /**
     * Devuelve estadísticas clave para el dashboard del administrador.
     */
    public function getStats(Request $request)
    {
        $gimnasioId = $request->user()->gimnasio_id;

        /* Nota: Para que 'inactive_unpaid' sea preciso,
         la tarea programada 'app:update-membership-status'
         DEBE ejecutarse al menos una vez al día (configurar Cron Job).
        */

        // Aseguramos que los estados estén actualizados antes de contar (como máximo cada pocos minutos).
        app(MembershipStatusService::class)->refreshGymThrottled($gimnasioId);

        $today = Carbon::now()->toDateString();
        $soon = Carbon::now()->addDays(3)->toDateString();

        // Un solo query con conteos condicionales en vez de cuatro.
        $row = Membership::whereIn('member_id', Member::select('id')->where('gimnasio_id', $gimnasioId))
            ->selectRaw("SUM(status = 'active') as active")
            ->selectRaw("SUM(status = 'expired') as expired")
            ->selectRaw("SUM(status = 'inactive_unpaid') as inactive_unpaid")
            // Notificar 3 días antes (igual que la tarea programada)
            ->selectRaw("SUM(status = 'active' AND DATE(end_date) BETWEEN ? AND ?) as expiring_soon", [$today, $soon])
            ->first();

        $stats = [
            'active' => (int) $row->active,
            'expired' => (int) $row->expired,
            'inactive_unpaid' => (int) $row->inactive_unpaid,
            'expiring_soon' => (int) $row->expiring_soon,
        ];

        return response()->json($stats);
    }
}
