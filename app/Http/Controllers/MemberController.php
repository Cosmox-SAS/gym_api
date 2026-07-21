<?php


namespace App\Http\Controllers;

use App\Models\Gimnasio;
use App\Models\Member;
// --- AÑADIR IMPORTS ---
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            ->with('memberships.plan.type')
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
            'allow_whatsapp_notifications' => 'sometimes|boolean',
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
        $memberData['allow_whatsapp_notifications'] = $request->boolean('allow_whatsapp_notifications');
        $memberData['whatsapp_opt_in_at'] = $memberData['allow_whatsapp_notifications'] ? now() : null;
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
        $member = Member::with('memberships.plan.type')->findOrFail($id);

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
            'allow_whatsapp_notifications' => 'sometimes|boolean',
            'birth_date' => 'sometimes|nullable|date',
            'medical_history' => 'nullable|string',
            'sexo' => 'nullable|string|in:masculino,femenino,no_binario,otro,preferir_no_decir',
            'estatura' => 'nullable|numeric|min:0',
            'peso' => 'nullable|numeric|min:0',
            'identification' => 'sometimes|string|unique:members,identification,' . $member->id,
            'initial_photos' => 'nullable|array|max:3',
            // 'fingerprint_data' => 'nullable|string', // Se maneja abajo
        ]);

        $oldPhotos = [$member->foto1, $member->foto2, $member->foto3];

        if (array_key_exists('initial_photos', $validated)) {
            $validated = $this->mapInitialPhotosToColumns($validated, $validated['initial_photos']);
            unset($validated['initial_photos']);
        }

        if ($request->has('allow_whatsapp_notifications')) {
            $validated['allow_whatsapp_notifications'] = $request->boolean('allow_whatsapp_notifications');
            $validated['whatsapp_opt_in_at'] = $validated['allow_whatsapp_notifications']
                ? ($member->whatsapp_opt_in_at ?? now())
                : null;
        }

       // (Lógica de huella simplificada)
        if ($request->has('fingerprint_data')) {
             $member->fingerprint_data = $request->fingerprint_data;
        }

       $member->update($validated);
       $member->save(); // Guardar huella si cambió

       if (array_key_exists('foto1', $validated) || array_key_exists('foto2', $validated) || array_key_exists('foto3', $validated)) {
           $this->deleteRemovedPhotos($oldPhotos, [$member->foto1, $member->foto2, $member->foto3]);
       }

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
        $this->deletePhotosFromStorage([$member->foto1, $member->foto2, $member->foto3]);
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
        $filename = (string) Str::uuid() . '.jpg';
        $directory = $gymSegment . '/' . $clientSegment;

        if (!$file->isValid()) {
            return response()->json([
                'message' => 'El archivo subido no es valido.',
            ], 422);
        }

        $disk = config('filesystems.default', 'r2');
        $path = $directory . '/' . $filename;
        $diskInstance = Storage::disk($disk);
        $prepared = $this->prepareImageForStorage($file);
        $stream = fopen($prepared['path'], 'r');

        try {
            $diskInstance->writeStream($path, $stream, [
                'visibility' => 'public',
                'mimetype' => $prepared['mime'],
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (!empty($prepared['temporary_path']) && file_exists($prepared['temporary_path'])) {
                @unlink($prepared['temporary_path']);
            }
        }

        $url = $this->resolveStorageUrl($path, $disk);

        return response()->json([
            'message' => 'Foto subida correctamente.',
            'url' => $url,
            'photo' => $url,
            'path' => $path,
            'disk' => $disk,
            'mime' => $prepared['mime'],
            'size' => $prepared['size'],
            'original_size' => $file->getSize(),
            'taken_at' => now()->toIso8601String(),
        ], 201);
    }

    private function prepareImageForStorage(UploadedFile $file): array
    {
        $sourcePath = $file->getPathname();

        if (empty($sourcePath) || !is_file($sourcePath)) {
            abort(response()->json([
                'message' => 'No se encontro el archivo temporal para subir.',
            ], 422));
        }

        $contents = file_get_contents($sourcePath);
        $source = $contents !== false ? @imagecreatefromstring($contents) : false;

        if (!$source) {
            return [
                'path' => $sourcePath,
                'temporary_path' => null,
                'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ];
        }

        $source = $this->applyImageOrientation($source, $sourcePath);
        $width = imagesx($source);
        $height = imagesy($source);
        $maxSide = 1600;
        $scale = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'member-photo-');
        imagejpeg($target, $temporaryPath, 78);

        imagedestroy($source);
        imagedestroy($target);

        return [
            'path' => $temporaryPath,
            'temporary_path' => $temporaryPath,
            'mime' => 'image/jpeg',
            'size' => filesize($temporaryPath) ?: 0,
        ];
    }

    private function applyImageOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated ?: $image;
    }

    private function resolveStorageUrl(string $path, ?string $disk = null): string
    {
        $disk = $disk ?: config('filesystems.default', 'local');
        $baseUrl = rtrim((string) config("filesystems.disks.{$disk}.url"), '/');

        if ($baseUrl !== '') {
            return $baseUrl . '/' . ltrim($path, '/');
        }

        return Storage::disk($disk)->url($path);
    }

    private function deleteRemovedPhotos(array $oldPhotos, array $newPhotos): void
    {
        $remaining = array_filter(array_map(fn ($photo) => $this->photoStoragePath($photo), $newPhotos));

        $removed = array_filter($oldPhotos, function ($photo) use ($remaining) {
            $path = $this->photoStoragePath($photo);
            return $path && !in_array($path, $remaining, true);
        });

        $this->deletePhotosFromStorage($removed);
    }

    private function deletePhotosFromStorage(array $photos): void
    {
        $disk = config('filesystems.default', 'local');
        $paths = array_values(array_unique(array_filter(array_map(fn ($photo) => $this->photoStoragePath($photo), $photos))));

        if ($paths === []) {
            return;
        }

        Storage::disk($disk)->delete($paths);
    }

    private function photoStoragePath(?string $photo): ?string
    {
        if (!$photo) {
            return null;
        }

        if (!preg_match('/^https?:\/\//i', $photo)) {
            return ltrim($photo, '/');
        }

        foreach (['r2', 's3', config('filesystems.default', 'local')] as $disk) {
            $baseUrl = rtrim((string) config("filesystems.disks.{$disk}.url"), '/');
            if ($baseUrl !== '' && str_starts_with($photo, $baseUrl . '/')) {
                return ltrim(substr($photo, strlen($baseUrl)), '/');
            }
        }

        return null;
    }

    private function mapInitialPhotosToColumns(array $data, $raw): array
    {
        $normalized = [null, null, null];
        if (!is_array($raw)) {
            $data['foto1'] = null;
            $data['foto1_taken_at'] = null;
            $data['foto2'] = null;
            $data['foto2_taken_at'] = null;
            $data['foto3'] = null;
            $data['foto3_taken_at'] = null;
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

            if (is_array($entry) && (!empty($entry['path']) || !empty($entry['photo']))) {
                $normalized[$i] = [
                    'photo' => (string) ($entry['path'] ?? $entry['photo']),
                    'taken_at' => $entry['taken_at'] ?? null,
                ];
            }
        }

        $data['foto1'] = $normalized[0]['photo'] ?? null;
        $data['foto1_taken_at'] = $this->normalizePhotoTakenAt($normalized[0]['taken_at'] ?? null);
        $data['foto2'] = $normalized[1]['photo'] ?? null;
        $data['foto2_taken_at'] = $this->normalizePhotoTakenAt($normalized[1]['taken_at'] ?? null);
        $data['foto3'] = $normalized[2]['photo'] ?? null;
        $data['foto3_taken_at'] = $this->normalizePhotoTakenAt($normalized[2]['taken_at'] ?? null);

        return $data;
    }

    private function normalizePhotoTakenAt($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
