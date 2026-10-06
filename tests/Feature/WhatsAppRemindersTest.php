<?php

use App\Jobs\SendMembershipExpiringSoonWhatsApp;
use App\Models\Gimnasio;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipNotification;
use App\Models\MembershipPlan;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function reminderMembership(string $endDate, string $status = 'active'): Membership
{
    $gym = Gimnasio::factory()->create(['nombre' => 'Power Gym']);
    $plan = MembershipPlan::factory()->create(['gym_id' => $gym->id, 'price' => 80000]);
    $member = Member::factory()->create([
        'gimnasio_id' => $gym->id,
        'name' => 'Ana Pérez',
        'phone' => '3001234567',
        'allow_whatsapp_notifications' => true,
        'identification' => (string) fake()->unique()->numberBetween(10000000, 99999999),
    ]);

    return Membership::create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => Carbon::parse($endDate)->subMonth(),
        'end_date' => $endDate,
        'status' => $status,
        'outstanding_balance' => 0,
    ]);
}

test('el comando envía el aviso de 3 días antes y el del mismo día del vencimiento', function () {
    Bus::fake([SendMembershipExpiringSoonWhatsApp::class]);
    Mail::fake();
    Carbon::setTestNow('2026-10-06 08:00:00');

    $inThreeDays = reminderMembership('2026-10-09 00:00:00');
    $dueToday = reminderMembership('2026-10-06 00:00:00');
    $paidInAdvance = reminderMembership('2026-11-06 00:00:00'); // ya renovó: su fin se movió

    $this->artisan('app:update-membership-status')->assertSuccessful();

    Bus::assertDispatched(SendMembershipExpiringSoonWhatsApp::class, fn ($job) => $job->membershipId === $inThreeDays->id
        && $job->type === WhatsAppService::TYPE_EXPIRING_SOON);
    Bus::assertDispatched(SendMembershipExpiringSoonWhatsApp::class, fn ($job) => $job->membershipId === $dueToday->id
        && $job->type === WhatsAppService::TYPE_EXPIRES_TODAY);
    Bus::assertNotDispatched(SendMembershipExpiringSoonWhatsApp::class, fn ($job) => $job->membershipId === $paidInAdvance->id);
    Bus::assertDispatchedTimes(SendMembershipExpiringSoonWhatsApp::class, 2);

    // La membresía que vence hoy sigue activa (se marca vencida al día siguiente).
    expect($dueToday->fresh()->status)->toBe('active');
});

test('cada tipo de aviso se envía una sola vez por membresía', function () {
    config([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.access_token' => 'token-de-prueba',
        'services.whatsapp.phone_number_id' => '123',
        'services.whatsapp.template_expiring' => 'membership_expiration_notice',
        'services.whatsapp.template_language' => 'es_CO',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.prueba']]])]);

    $membership = reminderMembership('2026-10-09 00:00:00');
    $service = app(WhatsAppService::class);

    expect($service->sendMembershipExpiringSoon($membership)['status'])->toBe('sent')
        ->and($service->sendMembershipExpiringSoon($membership, WhatsAppService::TYPE_EXPIRES_TODAY)['status'])->toBe('sent')
        ->and($service->sendMembershipExpiringSoon($membership)['reason'])->toBe('already_sent')
        ->and($service->sendMembershipExpiringSoon($membership, WhatsAppService::TYPE_EXPIRES_TODAY)['reason'])->toBe('already_sent');

    Http::assertSentCount(2);
    expect(MembershipNotification::where('membership_id', $membership->id)->pluck('type')->all())
        ->toEqualCanonicalizing([WhatsAppService::TYPE_EXPIRING_SOON, WhatsAppService::TYPE_EXPIRES_TODAY]);
});
