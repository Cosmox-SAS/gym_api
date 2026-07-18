<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\MembershipNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public const CHANNEL = 'whatsapp';
    public const TYPE_EXPIRING_SOON = 'membership_expiring_soon';

    public function sendMembershipExpiringSoon(Membership $membership): array
    {
        if (!config('services.whatsapp.enabled')) {
            return ['status' => 'skipped', 'reason' => 'disabled'];
        }

        $membership->loadMissing(['member.gimnasio']);
        $member = $membership->member;

        if (!$member || !$member->allow_whatsapp_notifications) {
            return ['status' => 'skipped', 'reason' => 'opt_out'];
        }

        $to = $this->formatColombianPhone($member->phone);
        if (!$to) {
            return ['status' => 'skipped', 'reason' => 'invalid_phone'];
        }

        $alreadySent = MembershipNotification::where('membership_id', $membership->id)
            ->where('channel', self::CHANNEL)
            ->where('type', self::TYPE_EXPIRING_SOON)
            ->where('status', 'sent')
            ->exists();

        if ($alreadySent) {
            return ['status' => 'skipped', 'reason' => 'already_sent'];
        }

        $log = MembershipNotification::updateOrCreate(
            [
                'membership_id' => $membership->id,
                'channel' => self::CHANNEL,
                'type' => self::TYPE_EXPIRING_SOON,
            ],
            [
                'gimnasio_id' => $member->gimnasio_id,
                'member_id' => $member->id,
                'status' => 'pending',
                'error_message' => null,
                'metadata' => ['to' => $to],
            ],
        );

        $configError = $this->configurationError();
        if ($configError) {
            $log->update([
                'status' => 'failed',
                'error_message' => $configError,
            ]);

            return ['status' => 'failed', 'reason' => $configError];
        }

        try {
            $response = Http::withToken(config('services.whatsapp.access_token'))
                ->acceptJson()
                ->post($this->messagesEndpoint(), $this->expiringSoonPayload($membership, $to));

            if (!$response->successful()) {
                $log->update([
                    'status' => 'failed',
                    'error_message' => $response->body(),
                    'metadata' => ['to' => $to, 'http_status' => $response->status()],
                ]);

                Log::warning('No se pudo enviar recordatorio WhatsApp de membresía.', [
                    'membership_id' => $membership->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['status' => 'failed', 'reason' => 'provider_error'];
            }

            $providerMessageId = $response->json('messages.0.id');
            $log->update([
                'status' => 'sent',
                'provider_message_id' => $providerMessageId,
                'sent_at' => now(),
                'metadata' => ['to' => $to, 'response' => $response->json()],
            ]);

            return ['status' => 'sent', 'provider_message_id' => $providerMessageId];
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Error enviando recordatorio WhatsApp de membresía.', [
                'membership_id' => $membership->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'reason' => 'exception'];
        }
    }

    private function expiringSoonPayload(Membership $membership, string $to): array
    {
        $member = $membership->member;
        $gymName = $member->gimnasio->nombre ?? 'tu gimnasio';
        $endDate = Carbon::parse($membership->end_date)->format('d/m/Y');

        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => config('services.whatsapp.template_expiring'),
                'language' => [
                    'code' => config('services.whatsapp.template_language'),
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $this->firstName($member->name)],
                            ['type' => 'text', 'text' => $gymName],
                            ['type' => 'text', 'text' => $endDate],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function messagesEndpoint(): string
    {
        $version = config('services.whatsapp.api_version', 'v20.0');
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
    }

    private function configurationError(): ?string
    {
        foreach (['access_token', 'phone_number_id', 'template_expiring', 'template_language'] as $key) {
            if (!config("services.whatsapp.{$key}")) {
                return "Falta configurar services.whatsapp.{$key}.";
            }
        }

        return null;
    }

    private function formatColombianPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            return '57' . $digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            return $digits;
        }

        return null;
    }

    private function firstName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'cliente';
        }

        return explode(' ', $name)[0];
    }
}
