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

class SendMembershipExpiringSoonWhatsApp implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $membershipId)
    {
        $this->onQueue('notifications');
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
            'result' => $result,
        ]);
    }

    public function uniqueId(): string
    {
        return "membership-expiring-soon-whatsapp:{$this->membershipId}";
    }
}
