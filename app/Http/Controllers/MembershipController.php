<?php


namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Member;
use App\Models\MembershipPlan;
use Illuminate\Http\Request;
use Carbon\Carbon;
class MembershipController extends Controller
{


public function index(Request $request)
    {
        $gimnasioId = $request->user()->gimnasio_id;
        $now = Carbon::now();
        $cancelCutoff = $now->copy()->subDays(30);

        // --- 1. RUTINA DE MANTENIMIENTO AUTOMÁTICO ---
        Membership::whereIn('status', ['active', 'expired', 'inactive_unpaid'])
            ->whereDate('end_date', '<=', $cancelCutoff->toDateString())
            ->whereHas('member', function ($query) use ($gimnasioId) {
                $query->where('gimnasio_id', $gimnasioId);
            })
            ->update(['status' => 'cancelled']);

        $membresiasVencidas = Membership::with('plan')
                ->where('end_date', '<', $now)
                ->whereNotIn('status', ['cancelled', 'inactive_unpaid'])
            ->whereHas('member', function ($query) use ($gimnasioId) {
                $query->where('gimnasio_id', $gimnasioId);
            })
            ->get();

        foreach ($membresiasVencidas as $m) {
            $guardarCambios = false;

            // Membresías vencidas hace más de 3 días → pasar a 'inactive_unpaid'
            if (Carbon::parse($m->end_date)->lt($now->copy()->subDays(3))) {
                $m->status = 'inactive_unpaid';
                $m->save();
                continue;
            }

            if ($m->status !== 'expired') {
                $m->status = 'expired';
                $guardarCambios = true;
            }

            if ($m->outstanding_balance <= 0 && $m->plan) {
                $m->outstanding_balance = $m->plan->price;
                $guardarCambios = true;
            }

            if ($guardarCambios) {
                $m->save();
            }
        }
        // --- FIN RUTINA ---

        // 2. Construir la consulta base
        $query = Membership::with(['member', 'plan.membershipType'])
            ->whereHas('member', function ($query) use ($gimnasioId) {
                $query->where('gimnasio_id', $gimnasioId);
            });

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

        // Aseguramos que los 'expired' estén actualizados antes de contar
        Membership::where('status', 'active')
            ->where('end_date', '<', Carbon::now())
            ->whereHas('member', function ($query) use ($gimnasioId) {
                $query->where('gimnasio_id', $gimnasioId);
            })
            ->update(['status' => 'expired']);

        // Query base para el gimnasio actual
        $baseQuery = Membership::whereHas('member', function ($query) use ($gimnasioId) {
            $query->where('gimnasio_id', $gimnasioId);
        });

        $stats = [
            'active' => (clone $baseQuery)->where('status', 'active')->count(),
            'expired' => (clone $baseQuery)->where('status', 'expired')->count(),
            'inactive_unpaid' => (clone $baseQuery)->where('status', 'inactive_unpaid')->count(),
            'expiring_soon' => (clone $baseQuery)
                                ->where('status', 'active')
                                ->whereDate('end_date', '>=', Carbon::now())
                                // Notificar 3 días antes (o los que definas en tu tarea programada)
                                ->whereDate('end_date', '<=', Carbon::now()->addDays(3))
                                ->count(),
        ];

        return response()->json($stats);
    }
}
