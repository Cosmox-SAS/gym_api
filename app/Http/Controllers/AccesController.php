<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\AccessLog;
use Carbon\Carbon;

class AccesController extends Controller
{
    /**
     * Acceso por identificación (cédula)
     */
    public function accessByIdentification(Request $request)
    {
        $request->validate([
            'identification' => 'required|string',
            'gimnasio_id' => 'sometimes|integer|exists:gimnasios,id',
        ]);

        $memberQuery = Member::where('identification', $request->identification);

        if ($request->filled('gimnasio_id')) {
            $memberQuery->where('gimnasio_id', $request->gimnasio_id);
        }

        $member = $memberQuery->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Miembro no encontrado'], 404);
        }

        if ($member->is_expired) {
            AccessLog::create([
                'member_id' => $member->id,
                'method'    => 'cedula',
                'status'    => 'denegado',
                'accessed_at' => now(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Membresía expirada',
                'member' => $member,
                'access_summary' => $this->buildAccessSummary($member),
            ], 403);
        }

        AccessLog::create([
            'member_id' => $member->id,
            'method'    => 'cedula',
            'status'    => 'permitido',
            'accessed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Acceso permitido',
            'member' => $member,
            'access_summary' => $this->buildAccessSummary($member),
        ]);
    }

    private function buildAccessSummary(Member $member): array
    {
        $membership = $member->memberships()->latest('end_date')->first();

        if (!$membership) {
            return [
                'has_membership' => false,
                'status' => null,
                'end_date' => null,
                'days_remaining' => null,
                'days_overdue' => null,
                'message' => 'Sin membresía registrada',
            ];
        }

        $endDate = Carbon::parse($membership->end_date);
        $daysDifference = (int) now()->startOfDay()->diffInDays($endDate->copy()->startOfDay(), false);
        $isActive = $membership->status === 'active' && !now()->greaterThan($endDate);

        return [
            'has_membership' => true,
            'status' => $membership->status,
            'end_date' => $endDate->toDateString(),
            'days_remaining' => $isActive ? max(0, $daysDifference) : null,
            'days_overdue' => $isActive ? null : max(0, -$daysDifference),
            'message' => $this->buildAccessSummaryMessage($isActive, $daysDifference),
        ];
    }

    private function buildAccessSummaryMessage(bool $isActive, int $daysDifference): string
    {
        if ($isActive && $daysDifference === 0) {
            return 'Tu membresía vence hoy';
        }

        if ($isActive && $daysDifference === 1) {
            return 'Te queda 1 día de membresía';
        }

        if ($isActive) {
            return "Te quedan {$daysDifference} días de membresía";
        }

        if ($daysDifference > 0) {
            return 'Tu membresía no está activa';
        }

        if ($daysDifference === 0) {
            return 'Tu membresía venció hoy';
        }

        $daysOverdue = -$daysDifference;

        return $daysOverdue === 1
            ? 'Tu membresía venció hace 1 día'
            : "Tu membresía venció hace {$daysOverdue} días";
    }

    /**
     * Acceso por huella.
     * El matching biométrico lo hace el SDK en el frontend (1:N).
     * El frontend envía el member_id ya identificado.
     */
    public function accessByFingerprint(Request $request)
    {
        $request->validate([
            'member_id' => 'required|integer|exists:members,id',
        ]);

        $member = Member::findOrFail($request->member_id);

        if ($member->is_expired) {
            AccessLog::create([
                'member_id' => $member->id,
                'method' => 'huella',
                'status' => 'denegado',
                'accessed_at' => now(),
            ]);
            return response()->json(['success' => false, 'message' => 'Membresía expirada'], 403);
        }

        AccessLog::create([
            'member_id' => $member->id,
            'method' => 'huella',
            'status' => 'permitido',
            'accessed_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Acceso permitido', 'member' => $member]);
    }

    /**
     * Matching 1:N server-side usando dpfj.dll via PHP FFI.
     * Recibe la muestra capturada + lista de candidatos y retorna el member_id coincidente.
     */
    public function matchFingerprint(Request $request)
    {
        $request->validate([
            'sample'                => 'required|string',
            'candidates'            => 'required|array|min:1',
            'candidates.*.id'       => 'required',
            'candidates.*.template' => 'required|string',
        ]);

        $dllPath = 'C:\\Program Files\\DigitalPersona\\U.are.U SDK\\Windows\\Lib\\x64\\dpfj.dll';

        try {
            $ffi = \FFI::cdef('
                typedef int DPFJ_FMD_FORMAT;
                typedef int DPFJ_FINGER_POSITION;
                int dpfj_create_fmd_from_raw(
                    const unsigned char* image_data,
                    unsigned int         image_size,
                    unsigned int         image_width,
                    unsigned int         image_height,
                    unsigned int         image_dpi,
                    DPFJ_FINGER_POSITION finger_pos,
                    unsigned int         cbeff_id,
                    DPFJ_FMD_FORMAT      fmd_type,
                    unsigned char*       fmd,
                    unsigned int*        fmd_size
                );
                int dpfj_compare(
                    DPFJ_FMD_FORMAT  fmd1_type,
                    unsigned char*   fmd1,
                    unsigned int     fmd1_size,
                    unsigned int     fmd1_view_idx,
                    DPFJ_FMD_FORMAT  fmd2_type,
                    unsigned char*   fmd2,
                    unsigned int     fmd2_size,
                    unsigned int     fmd2_view_idx,
                    unsigned int*    score
                );
            ', $dllPath);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo cargar dpfj.dll: ' . $e->getMessage()], 500);
        }

        $ANSI_FMT  = 0x001B0001;
        $THRESHOLD = 21474836;

        // sample viene como JSON string: {"data":"<base64>","width":N,"height":N,"dpi":N}
        $probeJson = json_decode($request->sample, true);

        if (!$probeJson) {
            return response()->json(['error' => 'Formato de sample inválido'], 422);
        }

        $probeFmd = $this->rawToFmd($ffi, $probeJson, $ANSI_FMT);
        if (!$probeFmd) {
            return response()->json(['error' => 'No se pudo extraer FMD del sample'], 422);
        }

        $probeSize = strlen($probeFmd);
        $cProbe    = \FFI::new("unsigned char[{$probeSize}]");
        \FFI::memcpy($cProbe, $probeFmd, $probeSize);

        \Log::info("Fingerprint match — probe FMD size: {$probeSize}, candidates: " . count($request->candidates));

        $bestScore       = PHP_INT_MAX;
        $bestCandidateId = null;

        foreach ($request->candidates as $candidate) {
            // template guardado como JSON (nuevo) o base64 puro (legado)
            $tmplJson = json_decode($candidate['template'], true);
            $tmplFmd  = $tmplJson
                ? $this->rawToFmd($ffi, $tmplJson, $ANSI_FMT)
                : base64_decode($candidate['template']);

            if (!$tmplFmd) continue;
            $tmplSize = strlen($tmplFmd);

            $cTmpl = \FFI::new("unsigned char[{$tmplSize}]");
            \FFI::memcpy($cTmpl, $tmplFmd, $tmplSize);

            $score = \FFI::new("unsigned int[1]");
            $ret   = $ffi->dpfj_compare(
                $ANSI_FMT, $cProbe, $probeSize, 0,
                $ANSI_FMT, $cTmpl,  $tmplSize,  0,
                $score
            );

            \Log::info("  candidate {$candidate['id']}: ret={$ret}, score={$score[0]}, tmplSize={$tmplSize}");

            if ($ret === 0 && $score[0] < $bestScore) {
                $bestScore       = $score[0];
                $bestCandidateId = $candidate['id'];
            }
        }

        \Log::info("Best: score={$bestScore}, threshold={$THRESHOLD}, matched=" . ($bestCandidateId ?? 'none'));

        if ($bestCandidateId === null || $bestScore >= $THRESHOLD) {
            return response()->json(['member_id' => null, 'score' => $bestScore]);
        }

        $member = Member::find($bestCandidateId);
        if (!$member) {
            return response()->json(['member_id' => null]);
        }

        $status = $member->is_expired ? 'denegado' : 'permitido';
        AccessLog::create([
            'member_id'   => $member->id,
            'method'      => 'huella',
            'status'      => $status,
            'accessed_at' => now(),
        ]);

        return response()->json([
            'member_id' => $member->id,
            'success'   => !$member->is_expired,
            'message'   => $member->is_expired ? 'Membresía expirada' : 'Acceso permitido',
            'member'    => $member,
        ]);
    }

    /**
     * Convierte imagen cruda (array {data, width, height, dpi}) a FMD ANSI usando dpfj.dll
     */
    private function rawToFmd($ffi, array $raw, int $fmt): ?string
    {
        $imgBin    = base64_decode($raw['data']);
        $imgSize   = strlen($imgBin);
        $width     = (int) ($raw['width']  ?? 0);
        $height    = (int) ($raw['height'] ?? 0);
        $dpi       = (int) ($raw['dpi']    ?? 500);
        $maxFmdSz  = 1600; // MAX_FMD_SIZE

        if ($imgSize === 0 || $width === 0 || $height === 0) return null;

        $cImg  = \FFI::new("unsigned char[{$imgSize}]");
        \FFI::memcpy($cImg, $imgBin, $imgSize);

        $cFmd     = \FFI::new("unsigned char[{$maxFmdSz}]");
        $cFmdSize = \FFI::new("unsigned int[1]");
        $cFmdSize[0] = $maxFmdSz;

        $ret = $ffi->dpfj_create_fmd_from_raw(
            $cImg, $imgSize, $width, $height, $dpi,
            0, 0, // finger_pos=unknown, cbeff_id=0
            $fmt,
            $cFmd, $cFmdSize
        );

        if ($ret !== 0) {
            \Log::warning("rawToFmd failed: ret={$ret}, size={$imgSize}, {$width}x{$height}@{$dpi}dpi");
            return null;
        }

        return \FFI::string($cFmd, $cFmdSize[0]);
    }

    /**
     * Devuelve todos los templates de huella de un gimnasio para que
     * el kiosco pueda hacer la identificación 1:N localmente con el SDK.
     */
    public function getFingerprintTemplates($gimnasio_id)
    {
        $members = Member::where('gimnasio_id', $gimnasio_id)
            ->whereNotNull('fingerprint_data')
            ->select('id', 'name', 'fingerprint_data')
            ->get();

        return response()->json($members);
    }

    public function getFingerprintsForGym($gimnasio_id)
    {
        return $this->getFingerprintTemplates($gimnasio_id);
    }
}
