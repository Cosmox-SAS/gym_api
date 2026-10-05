<?php

namespace App\Jobs;

use App\Models\Membership;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendMembershipExpiringSoonWhatsApp implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $membershipId)
    {
    }

    public function handle(WhatsAppService $whatsApp): void
    {
        $membership = Membership::with(['member.gimnasio'])->find($this->membershipId);

        if (!$membership) {
            Log::warning('No se envió recordatorio WhatsApp porque la membresía no existe.', [
                'membership_id' => $this->membershipId,
            ]);

            return;
        }

        $result = $whatsApp->sendMembershipExpiringSoon($membership);

        Log::info('Resultado recordatorio WhatsApp de membresía.', [
            'membership_id' => $membership->id,
            'attempt' => $this->attempts(),
            'result' => $result,
        ]);

        // Lanzar la excepción hace que la cola aplique $tries y $backoff.
        if ($this->isRetryable($result)) {
            throw new RuntimeException(
                "Fallo temporal enviando recordatorio WhatsApp de la membresía {$membership->id}: {$result['reason']}"
            );
        }
    }

    /**
     * Solo se reintentan errores de red, rate limit (429) y errores 5xx de Meta.
     * Los 4xx (plantilla inválida, token vencido, número no válido) no se arreglan reintentando.
     */
    private function isRetryable(array $result): bool
    {
        if (($result['status'] ?? null) !== 'failed') {
            return false;
        }

        return match ($result['reason'] ?? null) {
            'exception' => true,
            'provider_error' => ($result['http_status'] ?? 0) === 429 || ($result['http_status'] ?? 0) >= 500,
            default => false,
        };
    }

    public function uniqueId(): string
    {
        return "membership-expiring-soon-whatsapp:{$this->membershipId}";
    }
}
