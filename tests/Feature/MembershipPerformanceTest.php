<?php

use App\Models\Gimnasio;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function perfGym(): array
{
    $gym = Gimnasio::factory()->create();
    $user = User::factory()->create();
    $user->gimnasio_id = $gym->id;
    $user->save();
    Sanctum::actingAs($user);

    $plan = MembershipPlan::factory()->create(['gym_id' => $gym->id, 'price' => 80000]);

    return [$gym, $user, $plan];
}

function perfMembership(Gimnasio $gym, MembershipPlan $plan, string $status, string $endDate, float $balance = 0): Membership
{
    $member = Member::factory()->create([
        'gimnasio_id' => $gym->id,
        'identification' => (string) fake()->unique()->numberBetween(10000000, 99999999),
    ]);

    return Membership::create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => now()->subMonths(2),
        'end_date' => $endDate,
        'status' => $status,
        'outstanding_balance' => $balance,
    ]);
}

test('el mantenimiento de estados da los mismos resultados que la rutina anterior', function () {
    [$gym, , $plan] = perfGym();

    $activeOk = perfMembership($gym, $plan, 'active', now()->addDays(10)->toDateTimeString());
    $expiredYesterday = perfMembership($gym, $plan, 'active', now()->subDay()->toDateTimeString(), 0);
    $expiredWithDebt = perfMembership($gym, $plan, 'active', now()->subDays(2)->toDateTimeString(), 5000);
    $overdue5Days = perfMembership($gym, $plan, 'expired', now()->subDays(5)->toDateTimeString(), 0);
    $overdue40Days = perfMembership($gym, $plan, 'inactive_unpaid', now()->subDays(40)->toDateTimeString(), 80000);
    $cancelled = perfMembership($gym, $plan, 'cancelled', now()->subDays(2)->toDateTimeString(), 0);

    [$otherGym, , $otherPlan] = perfGym();
    $otherGymOverdue = perfMembership($otherGym, $otherPlan, 'active', now()->subDays(5)->toDateTimeString());

    app(MembershipStatusService::class)->refreshGym($gym->id);

    expect($activeOk->fresh()->status)->toBe('active')
        ->and($expiredYesterday->fresh()->status)->toBe('expired')
        ->and((float) $expiredYesterday->fresh()->outstanding_balance)->toBe(80000.0)
        ->and($expiredWithDebt->fresh()->status)->toBe('expired')
        ->and((float) $expiredWithDebt->fresh()->outstanding_balance)->toBe(5000.0)
        ->and($overdue5Days->fresh()->status)->toBe('inactive_unpaid')
        ->and((float) $overdue5Days->fresh()->outstanding_balance)->toBe(0.0)
        ->and($overdue40Days->fresh()->status)->toBe('cancelled')
        ->and($cancelled->fresh()->status)->toBe('cancelled')
        ->and($otherGymOverdue->fresh()->status)->toBe('active');
});

test('el mantenimiento no se repite en cada visita a /memberships', function () {
    Cache::flush();
    [$gym, , $plan] = perfGym();
    $m = perfMembership($gym, $plan, 'active', now()->subDay()->toDateTimeString());

    $this->getJson('/api/memberships')->assertOk();
    expect($m->fresh()->status)->toBe('expired');

    // Una membresía que vence después no se procesa hasta que pase el intervalo.
    $later = perfMembership($gym, $plan, 'active', now()->subDay()->toDateTimeString());
    $this->getJson('/api/memberships')->assertOk();
    expect($later->fresh()->status)->toBe('active');
});

test('la lista de membresías devuelve solo datos básicos del cliente', function () {
    Cache::flush();
    [$gym, , $plan] = perfGym();
    perfMembership($gym, $plan, 'active', now()->addDays(10)->toDateTimeString());

    $member = $this->getJson('/api/memberships')->assertOk()->json('data.0.member');

    expect(array_keys($member))->toEqualCanonicalizing(['id', 'name', 'identification', 'email', 'phone', 'gimnasio_id']);
});

test('las estadísticas cuentan por estado en un solo query', function () {
    Cache::flush();
    [$gym, , $plan] = perfGym();
    perfMembership($gym, $plan, 'active', now()->addDays(2)->toDateTimeString());
    perfMembership($gym, $plan, 'active', now()->addDays(20)->toDateTimeString());
    perfMembership($gym, $plan, 'inactive_unpaid', now()->addDays(20)->toDateTimeString());

    $this->getJson('/api/memberships/stats')->assertOk()->assertExactJson([
        'active' => 2,
        'expired' => 0,
        'inactive_unpaid' => 1,
        'expiring_soon' => 1,
    ]);
});

test('la lista de clientes no hace una consulta por cliente y oculta la huella', function () {
    [$gym, , $plan] = perfGym();
    foreach (range(1, 8) as $_) {
        perfMembership($gym, $plan, 'active', now()->addDays(10)->toDateTimeString());
    }
    Member::where('gimnasio_id', $gym->id)->update(['fingerprint_data' => 'huella']);

    DB::enableQueryLog();
    $members = $this->getJson('/api/members')->assertOk()->json();
    $queries = count(DB::getQueryLog());

    expect($members)->toHaveCount(8)
        ->and($members[0])->not->toHaveKey('fingerprint_data')
        ->and($members[0])->toHaveKey('is_expired')
        ->and($queries)->toBeLessThan(10);
});

test('la lista simple de clientes solo trae datos básicos', function () {
    [$gym, , $plan] = perfGym();
    perfMembership($gym, $plan, 'active', now()->addDays(10)->toDateTimeString());

    $member = $this->getJson('/api/members?simple=1')->assertOk()->json('0');

    expect(array_keys($member))->toEqualCanonicalizing(['id', 'name', 'identification', 'email', 'phone']);
});

test('el historial de pagos no consulta las membresías de cada cliente', function () {
    [$gym, , $plan] = perfGym();
    $method = \App\Models\PaymentMethod::firstOrCreate(['name' => 'Efectivo']);

    foreach (range(1, 6) as $_) {
        $membership = perfMembership($gym, $plan, 'active', now()->addDays(10)->toDateTimeString());
        \App\Models\Payment::create([
            'amount' => 80000,
            'paymentable_id' => $membership->id,
            'paymentable_type' => Membership::class,
            'payment_method_id' => $method->id,
            'paid_at' => now(),
        ]);
    }

    DB::enableQueryLog();
    $response = $this->getJson('/api/payments/history')->assertOk();
    $queries = count(DB::getQueryLog());

    expect($response->json('total_transacciones'))->toBe(6)
        ->and($response->json('historial.0.paymentable.member.name'))->not->toBeNull()
        ->and($response->json('historial.0.paymentable.member'))->not->toHaveKey('is_expired')
        ->and($queries)->toBeLessThan(15);
});
