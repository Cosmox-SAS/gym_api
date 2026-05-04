<?php


namespace App\Http\Controllers;

use App\Models\Gimnasio;
use App\Models\Member;
// --- AÑADIR IMPORTS ---
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr; // <-- IMPORTANTE AÑADIR ESTO
use Illuminate\Support\Str;
use Carbon\Carbon;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $gimnasioId = $request->user()->gimnasio_id;
            return Member::where('gimnasio_id', $gimnasioId)
            ->with('memberships')
            ->get();
    }

    // --- ======================================== ---
    // --- MÉTODO STORE COMPLETAMENTE CORREGIDO ---
    // --- ======================================== ---
    public function store(Request $request)
    {
        // 1. Validar TODOS los campos, incluyendo el plan opcional
        $validated = $request->validate([
            'identification' => 'required|string|unique:members,identification',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:members,email',
            'phone' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'medical_history' => 'nullable|string',
            'sexo' => 'nullable|string|in:masculino,femenino,no_binario,otro,preferir_no_decir',
            'estatura' => 'nullable|numeric|min:0',
            'peso' => 'nullable|numeric|min:0',
            'initial_photos' => 'nullable|array|max:3',
            // 'fingerprint_data' => 'nullable|string', // Quitado de aquí, se maneja abajo
            'plan_id' => 'nullable|exists:membership_plans,id' // <-- REGLA AÑADIDA
        ]);

        // 2. Separar los datos del miembro de los datos del plan
        // Excluimos 'plan_id' porque no pertenece a la tabla 'members'
        $memberData = Arr::except($validated, ['plan_id']); // <-- ¡CAMBIO CLAVE!

        // Asignar gimnasio_id del admin autenticado
        $memberData['gimnasio_id'] = $request->user()->gimnasio_id;
        $memberData = $this->mapInitialPhotosToColumns($memberData, $memberData['initial_photos'] ?? null);
        unset($memberData['initial_photos']);

        // --- Lógica de Huella (Opcional) ---
        $gimnasio = Gimnasio::findOrFail($memberData['gimnasio_id']);
        if ($gimnasio->uses_access_control) {
             $request->validate(['fingerprint_data' => 'required|string']);
             $memberData['fingerprint_data'] = $request->fingerprint_data;
        } else {
            $memberData['fingerprint_data'] = null;
        }
        // --- Fin Lógica Huella ---


        $member = null;

        try {
            DB::beginTransaction();

            // 3. Crear al Miembro solo con sus datos (¡YA NO USA ...$validated!)
            $member = Member::create($memberData);

            // 4. Si se proveyó un plan_id, crear la membresía
            // Usamos $validated['plan_id'] porque ya fue validado
            if (!empty($validated['plan_id'])) {

                // (Comprobamos que el plan sea del gimnasio correcto por seguridad)
                $plan = MembershipPlan::where('id', $validated['plan_id'])
                                      ->where('gym_id', $memberData['gimnasio_id'])
                                      ->firstOrFail();

                // (Lógica para calcular fechas)
                $startDate = Carbon::now();
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
                    'end_date' => $endDate,
                    'status' => 'inactive_unpaid', // Inicia inactiva
                    'outstanding_balance' => $plan->price, // Con saldo pendiente
                ]);
            }

            DB::commit();

            // Devolvemos el miembro recién creado
            return response()->json($member, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            // Devolver un error más específico
            return response()->json(['error' => 'No se pudo crear el miembro.', 'details' => $e->getMessage()], 500);
        }
    }
    // --- ======================================== ---
    // ---


    public function show($id)
    {
        // (Tu código de 'show' original estaba bien, pero este es más completo)
         $member = Member::with(['memberships' => function ($q)
        {
        // Modificado para que también pueda encontrar la membresía pendiente
        $q->whereIn('status', ['active', 'inactive_unpaid', 'expired']);
        }, 'memberships.plan'])->findOrFail($id);

        return response()->json($member);
    }

    public function update(Request $request, $id)
    {
        $member = Member::findOrFail($id);

        $validated = $request->validate([
            // (Quitamos gimnasio_id, no se debe cambiar)
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|unique:members,email,' . $member->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'birth_date' => 'sometimes|nullable|date',
            'medical_history' => 'nullable|string',
            'sexo' => 'nullable|string|in:masculino,femenino,no_binario,otro,preferir_no_decir',
            'estatura' => 'nullable|numeric|min:0',
            'peso' => 'nullable|numeric|min:0',
            'identification' => 'sometimes|string|unique:members,identification,' . $member->id,
            'initial_photos' => 'nullable|array|max:3',
            // 'fingerprint_data' => 'nullable|string', // Se maneja abajo
        ]);

        if (array_key_exists('initial_photos', $validated)) {
            $validated = $this->mapInitialPhotosToColumns($validated, $validated['initial_photos']);
            unset($validated['initial_photos']);
        }

       // (Lógica de huella simplificada)
        if ($request->has('fingerprint_data')) {
             $member->fingerprint_data = $request->fingerprint_data;
        }

       $member->update($validated);
       $member->save(); // Guardar huella si cambió

       return response()->json($member);
    }


    public function storeFingerprint(Request $request, $id)
    {
        $request->validate([
            'fingerprint_data' => 'required|string',
        ]);

        $member = Member::findOrFail($id);
        $member->fingerprint_data = $request->fingerprint_data;
        $member->save();

        return response()->json(['message' => 'Huella guardada correctamente']);
    }

    public function destroy($id)
    {
        $member = Member::findOrFail($id);
        $member->delete();

        return response()->json(['message' => 'Miembro eliminado con éxito']);
    }

    /**
     * Enrolar o actualizar la huella dactilar de un miembro (DigitalPersona).
     * El cliente envía el FMD (Fingerprint Minutiae Data) en base64.
     */
    public function enrollFingerprint(Request $request, $id)
    {
        $member = Member::findOrFail($id);

        $request->validate([
            'fingerprint_data' => 'required|string',
        ]);

        $member->fingerprint_data = $request->fingerprint_data;
        $member->save();

        return response()->json(['success' => true, 'message' => 'Huella registrada correctamente']);
    }

    public function uploadInitialPhoto(Request $request)
    {
        $validated = $request->validate([
            'photo' => 'required|file|image|mimes:png,jpg,jpeg|max:3072',
            'member_id' => 'nullable|integer|exists:members,id',
            'identification' => 'nullable|string|max:80',
        ]);

        $gimnasioId = $request->user()->gimnasio_id;
        $gimnasio = Gimnasio::findOrFail($gimnasioId);

        $clientId = null;
        if (!empty($validated['member_id'])) {
            $member = Member::where('id', $validated['member_id'])
                ->where('gimnasio_id', $gimnasioId)
                ->firstOrFail();
            $clientId = $member->identification ?: (string) $member->id;
        }

        if (!$clientId) {
            $clientId = $validated['identification'] ?? null;
        }

        if (!$clientId) {
            return response()->json([
                'message' => 'Debes enviar identification o member_id para organizar el archivo.',
            ], 422);
        }

        $gymSegment = Str::slug($gimnasio->nombre ?: ('gym-' . $gimnasioId));
        $clientSegment = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $clientId) ?: ('member-' . ($validated['member_id'] ?? 'tmp'));

        $file = $request->file('photo');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = (string) Str::uuid() . '.' . $extension;
        $directory = $gymSegment . '/' . $clientSegment;

        $disk = config('filesystems.default', 'local');
        $path = Storage::disk($disk)->putFileAs($directory, $file, $filename, 'public');
        $url = Storage::url($path);

        return response()->json([
            'message' => 'Foto subida correctamente.',
            'url' => $url,
            'path' => $path,
            'disk' => $disk,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'taken_at' => now()->toIso8601String(),
        ], 201);
    }

    private function mapInitialPhotosToColumns(array $data, $raw): array
    {
        $normalized = [null, null, null];
        if (!is_array($raw)) {
            $data['foto1'] = null;
            $data['foto2'] = null;
            $data['foto3'] = null;
            return $data;
        }

        for ($i = 0; $i < 3; $i++) {
            $entry = $raw[$i] ?? null;

            if (is_string($entry) && trim($entry) !== '') {
                $normalized[$i] = [
                    'photo' => $entry,
                    'taken_at' => null,
                ];
                continue;
            }

            if (is_array($entry) && !empty($entry['photo'])) {
                $normalized[$i] = [
                    'photo' => (string) $entry['photo'],
                    'taken_at' => $entry['taken_at'] ?? null,
                ];
            }
        }

        $data['foto1'] = $normalized[0]['photo'] ?? null;
        $data['foto2'] = $normalized[1]['photo'] ?? null;
        $data['foto3'] = $normalized[2]['photo'] ?? null;

        return $data;
    }
}
