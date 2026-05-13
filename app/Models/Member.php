<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
class Member extends Model
{
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasFactory;

    protected $fillable = [
    'identification',
    'fingerprint_data',
    'foto1',
    'foto2',
    'foto3',
    'gimnasio_id',
    'name',
    'email',
    'phone',
    'birth_date',
    'medical_history',
    'sexo',
    'estatura',
    'peso',
    // Omitimos 'objetivo_entrenamiento' como solicitaste
];

     public function gimnasio()
    {
        return $this->belongsTo(Gimnasio::class);
    }

    public function memberships()
    {
        //return $this->hasMany(Membership::class);
        return $this->hasMany(Membership::class)->orderBy('id', 'desc');
    }

    public function sales()
    {
        return $this->hasMany(SupplementSale::class);
    }



public function getEdadAttribute()
{
    return $this->birth_date ? Carbon::parse($this->birth_date)->age : null;
}

public function getIndiceMasaCorporalAttribute()
{
    if ($this->peso && $this->estatura && $this->estatura > 0) {
        return round($this->peso / ($this->estatura ** 2), 2);
    }

    return null;
}

protected $appends = ['is_expired', 'initial_photos'];


// --- INICIO DE LA MODIFICACIÓN (Lógica de Control de Acceso) ---

/**
 * Determina si el miembro tiene acceso al gimnasio.
 * 'is_expired' = true SIGNIFICA QUE NO TIENE ACCESO.
 * 'is_expired' = false SIGNIFICA QUE SÍ TIENE ACCESO.
 */
public function getIsExpiredAttribute()
{
    $lastMembership = $this->memberships()->latest('end_date')->first();

    // 1. Si NUNCA ha tenido membresía, no tiene acceso.
    if (!$lastMembership) {
        return true; // "Vencida" = true
    }

    // 2. Si su última membresía NO está activa (ej: 'expired', 'inactive_unpaid', 'cancelled')
    if ($lastMembership->status !== 'active') {
        return true; // "Vencida" = true
    }

    // 3. Si su membresía ESTÁ 'active', chequear la fecha en tiempo real
    //    (Esto es por si la tarea programada [cron job] no se ha ejecutado)
    return now()->greaterThan($lastMembership->end_date);
}

public function getInitialPhotosAttribute()
{
    return [
        $this->formatInitialPhoto($this->foto1),
        $this->formatInitialPhoto($this->foto2),
        $this->formatInitialPhoto($this->foto3),
    ];
}

private function formatInitialPhoto(?string $photo): ?array
{
    if (!$photo) {
        return null;
    }

    return [
        'photo' => $this->resolvePhotoUrl($photo),
        'path' => $photo,
        'taken_at' => null,
    ];
}

private function resolvePhotoUrl(string $photo): string
{
    if (preg_match('/^https?:\/\//i', $photo)) {
        return $photo;
    }

    $disk = config('filesystems.default', 'local');
    $baseUrl = rtrim((string) config("filesystems.disks.{$disk}.url"), '/');

    if ($baseUrl !== '') {
        return $baseUrl . '/' . ltrim($photo, '/');
    }

    try {
        return Storage::disk($disk)->url($photo);
    } catch (\Throwable) {
        return $photo;
    }
}

// --- FIN DE LA MODIFICACIÓN ---


public function accessLogs()
{
    return $this->hasMany(AccessLog::class);
}



}
