<?php

namespace App\Rules;

use App\Services\WhatsAppService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida que el teléfono sea un celular colombiano al que se le pueda enviar WhatsApp
 * (10 dígitos que empiezan por 3, con o sin el prefijo 57).
 */
class ColombianMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!WhatsAppService::formatColombianPhone((string) $value)) {
            $fail('Para recibir recordatorios por WhatsApp el teléfono debe ser un celular colombiano válido (ej: 300 123 4567).');
        }
    }
}
