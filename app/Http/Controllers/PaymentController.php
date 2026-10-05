<?php

namespace App\Http\Controllers;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\SupplementSale; // <-- 1. IMPORTAR SupplementSale
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
class PaymentController extends Controller
{
   public function index(Request $request)
{
    $gimnasioId = $request->user()->gimnasio_id;

    $payments = Payment::with(['paymentable.member', 'payment_method'])
        ->whereHasMorph(
            'paymentable',
            [\App\Models\Membership::class],
            function ($query) use ($gimnasioId) {
                $query->whereHas('member', function ($q) use ($gimnasioId) {
                    $q->where('gimnasio_id', $gimnasioId);
                });
            }
        )
        ->get();

    return response()->json($payments);
}

public function store(Request $request)
    {
        $validated = $request->validate([
            'member_id'         => 'required|exists:members,id',
            'amount'            => 'required|numeric|min:1000',
            'payment_method_id' => 'required|exists:payment_methods,id',
        ]);

        // 1. Buscar la membresía (Activa, Vencida o Por Pagar)
        $membership = Membership::with('plan')
            ->where('member_id', $validated['member_id'])
            ->whereIn('status', ['active', 'expired', 'inactive_unpaid'])
            ->latest('created_at') // Ordenar por creación para agarrar la última
            ->first();

        if (!$membership) {
            return response()->json(['error' => 'No se encontró una membresía asociada para aplicar el pago.'], 404);
        }

        // 2. Crear el Registro de Pago
        $payment = Payment::create([
            'amount'            => $validated['amount'],
            'paymentable_id'    => $membership->id,
            'paymentable_type'  => Membership::class,
            'payment_method_id' => $validated['payment_method_id'],
            'paid_at'           => now(),
        ]);

        // 3. Aplicar pago a la deuda
        $membership->outstanding_balance -= $validated['amount'];
        if ($membership->outstanding_balance < 0) {
            $membership->outstanding_balance = 0;
        }

        $plan = $membership->plan;

        // =================================================================================
        // LÓGICA DE REACTIVACIÓN JUSTA (Borrón y Cuenta Nueva)
        // =================================================================================

        // Si ya pagó todo Y la membresía estaba vencida o inactiva...
        if ($membership->outstanding_balance == 0 && in_array($membership->status, ['expired', 'inactive_unpaid'])) {

            // A. Definir HOY como el nuevo inicio
            $fechaBase = Carbon::now();

            // B. Actualizamos Fecha Inicio a HOY (Para ignorar el mes que no vino)
            $membership->start_date = $fechaBase->copy();

            // C. Calculamos Fecha Fin desde HOY según el plan (CORREGIDO INGLÉS/ESPAÑOL)
            // Usamos las claves en Inglés que vienen de tu base de datos ('daily', 'monthly'...)
            switch ($plan->frequency) {
                case 'daily':
                case 'diario':
                    $membership->end_date = $fechaBase->copy()->addDay();
                    break;

                case 'weekly':
                case 'semanal':
                    $membership->end_date = $fechaBase->copy()->addWeek();
                    break;

                case 'biweekly':
                case 'quincenal':
                    $membership->end_date = $fechaBase->copy()->addWeeks(2); // O addDays(15)
                    break;

                case 'monthly':
                case 'mensual':
                    $membership->end_date = $fechaBase->copy()->addMonth();
                    break;

                case 'quarterly':
                    $membership->end_date = $fechaBase->copy()->addMonths(3);
                    break;

                case 'biannual':
                    $membership->end_date = $fechaBase->copy()->addMonths(6);
                    break;

                case 'yearly':
                case 'anual':
                    $membership->end_date = $fechaBase->copy()->addYear();
                    break;

                default:
                    // Por seguridad, si falla, damos 1 mes
                    $membership->end_date = $fechaBase->copy()->addMonth();
                    break;
            }

            // D. Activar estado
            $membership->status = 'active';

            // NOTA: No seteamos outstanding_balance al precio del plan aquí.
            // El cliente acaba de pagar, su deuda debe ser 0 para entrar.
        }

        // 4. Lógica para renovar una membresía que YA estaba activa (Adelantar pago)
        else if ($membership->status == 'active' && $membership->outstanding_balance == 0) {
            // Aquí NO movemos la start_date, solo extendemos la end_date
            $fechaFinActual = Carbon::parse($membership->end_date);

            // Si la fecha fin ya pasó (pero seguía activa por error), usamos HOY.
            // Si no ha pasado, sumamos desde la fecha fin actual (continuidad).
            $baseCalculo = $fechaFinActual->isPast() ? Carbon::now() : $fechaFinActual;

            switch ($plan->frequency) {
                case 'daily': case 'diario':        $baseCalculo->addDay(); break;
                case 'weekly': case 'semanal':      $baseCalculo->addWeek(); break;
                case 'biweekly': case 'quincenal':  $baseCalculo->addWeeks(2); break;
                case 'monthly': case 'mensual':     $baseCalculo->addMonth(); break;
                case 'yearly': case 'anual':        $baseCalculo->addYear(); break;
            }

            $membership->end_date = $baseCalculo;

            // Aquí podrías generar la nueva deuda para el próximo mes si quisieras
            // $membership->outstanding_balance = $plan->price;
        }

        $membership->save();

       // ============================================================
        // 📧 LÓGICA DE CORREOS INTELIGENTE
        // ============================================================
        try {
            $membership->load('member.gimnasio');
            $miembro = $membership->member;

            // Verificamos que el pago haya activado la membresía y tengamos datos
            if ($membership->status === 'active' && $miembro && $miembro->email && $miembro->gimnasio) {

                // 🔍 EL TRUCO: Contamos cuántos pagos tiene este cliente en total
                // Usamos whereHasMorph para buscar pagos asociados a sus membresías
                $totalPagos = \App\Models\Payment::whereHasMorph(
                    'paymentable',
                    [\App\Models\Membership::class],
                    function ($query) use ($miembro) {
                        $query->where('member_id', $miembro->id);
                    }
                )->count();

                if ($totalPagos <= 1) {
                    // CASO 1: Es su primer pago -> BIENVENIDA (Con reglas y horarios)
                    \Illuminate\Support\Facades\Mail::to($miembro->email)
                        ->send(new \App\Mail\BienvenidaMiembroMail($miembro, $miembro->gimnasio));

                    \Illuminate\Support\Facades\Log::info("📧 Bienvenida enviada a nuevo cliente: " . $miembro->email);
                } else {
                    // CASO 2: Ya tiene pagos previos -> RENOVACIÓN (Solo gracias)
                    // Pasamos el $payment actual para mostrar el monto en el correo
                    \Illuminate\Support\Facades\Mail::to($miembro->email)
                        ->send(new \App\Mail\RenovacionMiembroMail($miembro, $miembro->gimnasio, $payment));

                    \Illuminate\Support\Facades\Log::info("📧 Renovación enviada a cliente recurrente: " . $miembro->email);
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("❌ Error enviando correo pago: " . $e->getMessage());
        }
    
    // ============================================================
    // FIN CORREO
    // ============================================================

    return response()->json([ // <--- ESTO YA LO TIENES
        'message'    => 'Pago registrado y membresía actualizada correctamente.',
        'payment'    => $payment,
        'membership' => $membership
    ], 201);

        return response()->json([
            'message'    => 'Pago registrado y membresía actualizada correctamente.',
            'payment'    => $payment,
            'membership' => $membership
        ], 201);
    }
    public function show($id)
    {
        $payment = Payment::with('paymentable')->findOrFail($id);
        return response()->json($payment);
    }

    public function update(Request $request, $id)
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'payment_method_id' => 'sometimes|exists:payment_methods,id',
            'paymentable_type' => 'sometimes|string',
            'paymentable_id' => 'sometimes|integer',
            'paid_at' => 'sometimes|date',
        ]);

        $payment->update($validated);

        return response()->json($payment);
    }

    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);
        $payment->delete();

        return response()->json(['message' => 'Payment deleted']);
    }

    public function totalToday()
{
    $hoy = Carbon::today();

    $total = DB::table('payments')
        ->whereDate('created_at', $hoy)
        ->sum('amount');

    return response()->json(['total' => $total]);
}

    // --- 6. MÉTODO NUEVO PARA HISTORIAL DE PAGOS (DASHBOARD) ---
    /**
     * Obtiene historial de ingresos (Membresías Y Suplementos)
     * para un rango de fechas.
     */
    public function getHistory(Request $request)
    {
        $gimnasioId = $request->user()->gimnasio_id;

        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        // Usar inicio de mes por defecto si no se provee fecha
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        // Usar hoy por defecto
        $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));

        // Carbon necesita 'endOfDay' para incluir transacciones de hoy
        $endDateCarbon = Carbon::parse($endDate)->endOfDay();
        $startDateCarbon = Carbon::parse($startDate)->startOfDay();


        // 1. Pagos de Membresías
        $queryPayments = Payment::with(['paymentable.member:id,name,identification,gimnasio_id', 'payment_method'])
            ->whereBetween('paid_at', [$startDateCarbon, $endDateCarbon])
            ->whereHasMorph(
                'paymentable',
                [Membership::class],
                function ($query) use ($gimnasioId) {
                    $query->whereHas('member', function ($q) use ($gimnasioId) {
                        $q->where('gimnasio_id', $gimnasioId);
                    });
                }
            );

        // 2. Pagos de Suplementos (Basado en SupplementSaleController)
        $querySales = Payment::with(['paymentable.product', 'paymentable.member:id,name,identification,gimnasio_id', 'payment_method'])
            ->whereBetween('paid_at', [$startDateCarbon, $endDateCarbon])
            ->whereHasMorph(
                'paymentable',
                [SupplementSale::class], // Modelo de venta
                function ($query) use ($gimnasioId) {
                    // La venta de suplemento tiene 'member'
                    // y el member tiene gimnasio_id
                    $query->whereHas('member', function ($q) use ($gimnasioId) {
                        $q->where('gimnasio_id', $gimnasioId);
                    });
                }
            );

        $payments = $queryPayments->get();
        $sales = $querySales->get();

        // 3. Combinar todo y ordenar
        $allTransactions = $payments->concat($sales)->sortByDesc('paid_at');

        // El historial solo muestra el nombre del cliente: sin campos calculados
        // (is_expired consultaría las membresías de cada cliente).
        $allTransactions->each(fn ($payment) => $payment->paymentable?->member?->setAppends([]));

        return response()->json([
            'total_ingresos' => $allTransactions->sum('amount'),
            'total_transacciones' => $allTransactions->count(),
            'historial' => $allTransactions->values(), // re-indexar array
        ]);
    }
}
