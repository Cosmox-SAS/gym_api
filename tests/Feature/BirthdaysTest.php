<?php

use App\Mail\CumpleanosAdminMail;
use App\Models\Gimnasio;
use App\Models\Member;
use App\Models\User;
use App\Services\BirthdayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function birthdayGym(): array
{
    $gym = Gimnasio::factory()->create(['nombre' => 'Power Gym']);
    $user = User::factory()->create();
    $user->gimnasio_id = $gym->id;
    $user->save();

    return [$gym, $user];
}

function birthdayMember(Gimnasio $gym, ?string $birthDate, string $name = 'Cliente'): Member
{
    return Member::factory()->create([
        'gimnasio_id' => $gym->id,
        'name' => $name,
        'identification' => (string) fake()->unique()->numberBetween(10000000, 99999999),
        'birth_date' => $birthDate,
    ]);
}

afterEach(fn () => Carbon::setTestNow());

test('calcula cumpleaños de hoy y de los próximos días, ordenados', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    [$gym] = birthdayGym();

    birthdayMember($gym, '1990-10-05', 'Hoy');
    birthdayMember($gym, '2000-10-08', 'En tres días');
    birthdayMember($gym, '1985-10-20', 'Fuera del rango');
    birthdayMember($gym, '1995-10-04', 'Fue ayer');
    birthdayMember($gym, null, 'Sin fecha');

    $result = app(BirthdayService::class)->upcoming($gym->id, 7);

    expect($result->pluck('name')->all())->toBe(['Hoy', 'En tres días'])
        ->and($result[0]['days_until'])->toBe(0)
        ->and($result[0]['turning_age'])->toBe(36)
        ->and($result[1]['days_until'])->toBe(3)
        ->and($result[1]['turning_age'])->toBe(26);
});

test('funciona al cruzar el fin de año', function () {
    Carbon::setTestNow('2026-12-29 09:00:00');
    [$gym] = birthdayGym();
    birthdayMember($gym, '1990-01-02', 'Año nuevo');

    $result = app(BirthdayService::class)->upcoming($gym->id, 7);

    expect($result)->toHaveCount(1)
        ->and($result[0]['birthday'])->toBe('2027-01-02')
        ->and($result[0]['days_until'])->toBe(4)
        ->and($result[0]['turning_age'])->toBe(37);
});

test('los nacidos el 29 de febrero cumplen el 28 en años no bisiestos', function () {
    Carbon::setTestNow('2027-02-28 08:00:00');
    [$gym] = birthdayGym();
    birthdayMember($gym, '2000-02-29', 'Bisiesto');

    $result = app(BirthdayService::class)->upcoming($gym->id, 0);

    expect($result)->toHaveCount(1)->and($result[0]['days_until'])->toBe(0);
});

test('el endpoint solo devuelve clientes del gimnasio del usuario', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    [$gym, $user] = birthdayGym();
    [$otherGym] = birthdayGym();
    birthdayMember($gym, '1990-10-05', 'Mío');
    birthdayMember($otherGym, '1990-10-05', 'Ajeno');
    Sanctum::actingAs($user);

    $this->getJson('/api/members/birthdays?days=7')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'Mío');

    $this->getJson('/api/members/birthdays?days=100')->assertStatus(422);
});

test('el comando envía el correo solo a gimnasios con cumpleaños hoy', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-05 07:00:00');
    [$gym, $admin] = birthdayGym();
    [$quietGym] = birthdayGym();
    birthdayMember($gym, '1990-10-05', 'Hoy');
    birthdayMember($gym, '1990-10-09', 'Próximo');
    birthdayMember($quietGym, '1990-10-09', 'Solo próximo');

    $this->artisan('gym:cumpleanos')->assertSuccessful();

    Mail::assertQueued(CumpleanosAdminMail::class, 1);
    Mail::assertQueued(CumpleanosAdminMail::class, function (CumpleanosAdminMail $mail) use ($admin) {
        return $mail->hasTo($admin->email)
            && $mail->hoy->pluck('name')->all() === ['Hoy']
            && $mail->proximos->pluck('name')->all() === ['Próximo'];
    });
});

test('el correo de cumpleaños se genera sin errores', function () {
    $mail = new CumpleanosAdminMail('Ana', 'Power Gym', collect([
        ['name' => 'Juan', 'turning_age' => 30, 'phone' => '3001234567', 'birthday' => '2026-10-05'],
    ]), collect([
        ['name' => 'Luisa', 'turning_age' => 25, 'phone' => null, 'birthday' => '2026-10-08'],
    ]));

    $html = $mail->render();

    expect($html)->toContain('Juan')->toContain('30 años')->toContain('Luisa')->toContain('08/10');
});
