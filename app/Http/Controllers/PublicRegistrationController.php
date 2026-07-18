<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gimnasio;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PublicRegistrationController extends Controller
{
    /**
     * Muestra los planes disponibles para un gimnasio (para poblar el <select> del form).
     * El ID del gimnasio vendrá en la URL del QR.
     */
    public function getPlans($gimnasio_id)
    {
        $gimnasio = Gimnasio::findOrFail($gimnasio_id);

        // Obtenemos solo los planes de ese gimnasio
        $planes = MembershipPlan::where('gym_id', $gimnasio->id)
                            ->with('membershipType')
                            ->get();

        return response()->json([
            'gimnasio' => $gimnasio,
            'planes' => $planes
        ]);
    }

    /**
     * Almacena el registro público de un nuevo miembro.
     * Esta es la acción del formulario del QR.
     */
    public function store(Request $request, $gimnasio_id)
    {
        $gimnasio = Gimnasio::findOrFail($gimnasio_id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:members,email',
            'phone' => 'nullable|string|max:20',
            'allow_whatsapp_notifications' => 'sometimes|boolean',
            'birth_date' => 'required|date',
            // Usamos los mismos 'in' que tu MemberController original
            'sexo' => 'required|string|in:masculino,femenino,no_binario,otro,preferir_no_decir',
            'estatura' => 'nullable|numeric|min:0',
            'peso' => 'nullable|numeric|min:0',
            'identification' => 'required|string|unique:members,identification',
            'plan_id' => [
                'required',
                // Validamos que el plan exista Y que pertenezca al gimnasio
                Rule::exists('membership_plans', 'id')->where(function ($query) use ($gimnasio_id) {
                    return $query->where('gym_id', $gimnasio_id);
                }),
            ],
            // Omitimos 'objetivo_entrenamiento' como solicitaste
        ]);

        $plan = MembershipPlan::findOrFail($validated['plan_id']);
        $member = null;

        try {
            DB::beginTransaction();

            // 1. Crear al Miembro
            $member = Member::create([
                'gimnasio_id' => $gimnasio->id,
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'allow_whatsapp_notifications' => $request->boolean('allow_whatsapp_notifications'),
                'whatsapp_opt_in_at' => $request->boolean('allow_whatsapp_notifications') ? now() : null,
                'birth_date' => $validated['birth_date'],
                'sexo' => $validated['sexo'],
                'estatura' => $validated['estatura'] ?? null,
                'peso' => $validated['peso'] ?? null,
                'identification' => $validated['identification'],
                'medical_history' => null, // <-- ESTA ES LA LÍNEA QUE FALTABA
                'fingerprint_data' => null, // La huella se toma en recepción
            ]);

            // 2. Crear la Membresía (Inactiva hasta el pago)
            $startDate = Carbon::now();

            // Calcular fecha de fin basado en el plan (lógica de MembershipController)
            $endDate = $startDate->copy();
            switch ($plan->frequency) {
                case 'daily':    $endDate->addDay(); break;
                case 'weekly':   $endDate->addWeek(); break;
                case 'biweekly': $endDate->addDays(15); break;
                case 'monthly':  $endDate->addMonthNoOverflow(); break;
            }

            Membership::create([
                'member_id' => $member->id,
                'plan_id' => $plan->id,
                'start_date' => $startDate,
                'end_date' => $endDate, // Fecha de fin si pagara hoy
                'status' => 'inactive_unpaid', // <-- ESTADO CLAVE
                'outstanding_balance' => $plan->price, // <-- DEUDA INICIAL
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Te has registrado con éxito. Por favor, acércate a recepción para completar tu pago y activar tu membresía.',
                'member' => $member,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            // Devolvemos el error de la base de datos para más detalles
            return response()->json(['error' => 'No se pudo completar el registro.', 'details' => $e->getMessage()], 500);
        }
    }
}
