<?php

use App\Models\Gimnasio;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function uniqGymAdmin(): User
{
    $gym = Gimnasio::factory()->create();
    $user = User::factory()->create();
    $user->gimnasio_id = $gym->id;
    $user->save();

    return $user;
}

function uniqMember(int $gymId, string $identification, ?string $email = null): Member
{
    return Member::factory()->create([
        'gimnasio_id' => $gymId,
        'identification' => $identification,
        'email' => $email,
    ]);
}

test('la misma persona puede ser cliente de dos gimnasios', function () {
    $otherGym = uniqGymAdmin();
    uniqMember($otherGym->gimnasio_id, '1003400077', 'alex@correo.com');

    Sanctum::actingAs(uniqGymAdmin());

    $this->postJson('/api/members', [
        'identification' => '1003400077',
        'name' => 'Alex',
        'email' => 'alex@correo.com',
    ])->assertCreated();

    expect(Member::where('identification', '1003400077')->count())->toBe(2);
});

test('no se repiten cédula ni email dentro del mismo gimnasio', function () {
    $admin = uniqGymAdmin();
    uniqMember($admin->gimnasio_id, '1003400077', 'alex@correo.com');
    Sanctum::actingAs($admin);

    $this->postJson('/api/members', ['identification' => '1003400077', 'name' => 'Otro'])
        ->assertStatus(422)->assertJsonValidationErrors('identification');

    $this->postJson('/api/members', ['identification' => '999', 'name' => 'Otro', 'email' => 'alex@correo.com'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

test('al editar se valida la unicidad solo dentro del gimnasio', function () {
    $admin = uniqGymAdmin();
    $otherGym = uniqGymAdmin();
    uniqMember($otherGym->gimnasio_id, '555', 'ajeno@correo.com');
    uniqMember($admin->gimnasio_id, '777');
    $member = uniqMember($admin->gimnasio_id, '123', 'mio@correo.com');
    Sanctum::actingAs($admin);

    // Igual a un cliente de otro gimnasio: permitido.
    $this->putJson("/api/members/{$member->id}", ['identification' => '555', 'email' => 'ajeno@correo.com'])->assertOk();

    // Igual a otro cliente del mismo gimnasio: rechazado.
    $this->putJson("/api/members/{$member->id}", ['identification' => '777'])
        ->assertStatus(422)->assertJsonValidationErrors('identification');

    // Conservar sus propios datos: permitido.
    $this->putJson("/api/members/{$member->id}", ['identification' => '555'])->assertOk();
});

test('el registro público valida la cédula solo dentro de su gimnasio', function () {
    $otherGym = uniqGymAdmin();
    uniqMember($otherGym->gimnasio_id, '1003400077', 'alex@correo.com');
    $gym = uniqGymAdmin();
    $plan = MembershipPlan::factory()->create(['gym_id' => $gym->gimnasio_id]);

    $payload = [
        'name' => 'Alex',
        'identification' => '1003400077',
        'email' => 'alex@correo.com',
        'birth_date' => '1995-05-10',
        'sexo' => 'masculino',
        'plan_id' => $plan->id,
    ];

    $this->postJson("/api/public/register/{$gym->gimnasio_id}", $payload)->assertSuccessful();
    $this->postJson("/api/public/register/{$gym->gimnasio_id}", $payload)
        ->assertStatus(422)->assertJsonValidationErrors(['identification', 'email']);
});

test('el kiosco por cédula usa el gimnasio de la URL y no se confunde entre gimnasios', function () {
    $gymA = uniqGymAdmin();
    $gymB = uniqGymAdmin();
    uniqMember($gymA->gimnasio_id, '1003400077');
    $memberB = uniqMember($gymB->gimnasio_id, '1003400077');
    uniqMember($gymA->gimnasio_id, '42');

    // Con el gimnasio en la URL encuentra al cliente correcto.
    $this->postJson('/api/access/identification', ['identification' => '1003400077', 'gimnasio_id' => $gymB->gimnasio_id])
        ->assertJsonPath('member.id', $memberB->id);

    // Sin gimnasio y con la cédula en dos gimnasios: no adivina.
    $this->postJson('/api/access/identification', ['identification' => '1003400077'])->assertStatus(422);

    // Sin gimnasio pero cédula única: sigue funcionando como antes.
    $this->postJson('/api/access/identification', ['identification' => '42'])->assertJsonPath('member.identification', '42');
});
