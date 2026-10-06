<?php

use App\Jobs\SendMembershipExpiringSoonWhatsApp;
use App\Mail\MembershipExpired;
use App\Mail\MembershipExpiringSoon;
use App\Mail\MembershipSuspended;
use App\Models\Gimnasio;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Nunca enviar WhatsApp reales desde los tests.
    config(['services.whatsapp.enabled' => false]);
    Bus::fake([SendMembershipExpiringSoonWhatsApp::class]);
    Mail::fake();
});

afterEach(fn () => Carbon::setTestNow());

function emailMembership(string $status, string $endDate, ?string $email = 'cliente@test.com'): Membership
{
    $gym = Gimnasio::factory()->create(['nombre' => 'Power Gym']);
    $plan = MembershipPlan::factory()->create(['gym_id' => $gym->id, 'price' => 80000]);
    $member = Member::factory()->create([
        'gimnasio_id' => $gym->id,
        'name' => 'Ana Pérez',
        'email' => $email,
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

test('el comando diario envía los tres correos de estado de membresía', function () {
    Carbon::setTestNow('2026-10-06 08:00:00');

    $soon = emailMembership('active', '2026-10-09');      // vence en 3 días
    $expired = emailMembership('active', '2026-10-05');   // venció ayer
    $suspended = emailMembership('expired', '2026-10-02'); // vencida hace 4 días

    $this->artisan('app:update-membership-status')->assertSuccessful();

    Mail::assertQueued(MembershipExpiringSoon::class, fn ($m) => $m->membership->is($soon) && $m->hasTo('cliente@test.com'));
    Mail::assertQueued(MembershipExpired::class, fn ($m) => $m->membership->is($expired));
    Mail::assertQueued(MembershipSuspended::class, fn ($m) => $m->membership->is($suspended));
    Mail::assertQueuedCount(3);
});

test('no se envía correo a clientes sin email', function () {
    Carbon::setTestNow('2026-10-06 08:00:00');
    emailMembership('active', '2026-10-09', null);

    $this->artisan('app:update-membership-status')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('los correos de estado se generan con los datos del cliente', function () {
    $membership = emailMembership('active', '2026-10-09');

    $soon = (new MembershipExpiringSoon($membership))->render();
    $expired = (new MembershipExpired($membership))->render();
    $suspended = (new MembershipSuspended($membership))->render();

    expect($soon)->toContain('Ana Pérez')->toContain('Power Gym')->toContain('09/10/2026')
        ->and($expired)->toContain('venció el <strong>09/10/2026')->toContain('12/10/2026')
        ->and($suspended)->toContain('suspendida')->toContain('Power Gym');
});
