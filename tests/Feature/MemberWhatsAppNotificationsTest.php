<?php

use App\Models\Gimnasio;
use App\Models\Member;
use App\Models\MembershipNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function whatsappGymAdmin(): User
{
    $gym = Gimnasio::factory()->create();
    $user = User::factory()->create();
    $user->gimnasio_id = $gym->id;
    $user->save();
    Sanctum::actingAs($user);

    return $user;
}

function whatsappMember(User $user, array $attributes = []): Member
{
    return Member::factory()->create(array_merge([
        'gimnasio_id' => $user->gimnasio_id,
        'identification' => (string) fake()->unique()->numberBetween(10000000, 99999999),
        'phone' => '3001234567',
        'allow_whatsapp_notifications' => false,
    ], $attributes));
}

test('crear un cliente con WhatsApp exige un celular colombiano válido', function () {
    whatsappGymAdmin();

    $this->postJson('/api/members', [
        'identification' => '123456',
        'name' => 'Ana',
        'phone' => '6012345678',
        'allow_whatsapp_notifications' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('phone');

    $this->postJson('/api/members', [
        'identification' => '123456',
        'name' => 'Ana',
        'phone' => '+57 601 234 5678',
        'allow_whatsapp_notifications' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('phone');

    $this->postJson('/api/members', [
        'identification' => '123456',
        'name' => 'Ana',
        'allow_whatsapp_notifications' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('phone');

    $this->postJson('/api/members', [
        'identification' => '123456',
        'name' => 'Ana',
        'phone' => '+57 300 123 4567',
        'allow_whatsapp_notifications' => true,
    ])->assertCreated();
});

test('sin WhatsApp se puede guardar cualquier teléfono', function () {
    whatsappGymAdmin();

    $this->postJson('/api/members', [
        'identification' => '123456',
        'name' => 'Ana',
        'phone' => '6012345678',
        'allow_whatsapp_notifications' => false,
    ])->assertCreated();
});

test('editar valida el teléfono guardado cuando solo se activa WhatsApp', function () {
    $user = whatsappGymAdmin();
    $member = whatsappMember($user, ['phone' => '12345']);

    $this->putJson("/api/members/{$member->id}", ['allow_whatsapp_notifications' => true])
        ->assertStatus(422)->assertJsonValidationErrors('phone');

    $this->putJson("/api/members/{$member->id}", ['allow_whatsapp_notifications' => true, 'phone' => '3109876543'])
        ->assertOk();

    expect($member->fresh()->allow_whatsapp_notifications)->toBeTrue();
});

test('el registro público exige celular válido si acepta WhatsApp', function () {
    $user = whatsappGymAdmin();

    $this->postJson("/api/public/register/{$user->gimnasio_id}", [
        'name' => 'Ana',
        'phone' => '123',
        'allow_whatsapp_notifications' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('phone');
});

test('activación masiva omite teléfonos inválidos y clientes de otro gimnasio', function () {
    $user = whatsappGymAdmin();
    $valid = whatsappMember($user);
    $invalid = whatsappMember($user, ['phone' => '6012345678']);
    $otherGym = whatsappMember($user, ['gimnasio_id' => Gimnasio::factory()->create()->id]);

    $this->postJson('/api/members/whatsapp-notifications', [
        'member_ids' => [$valid->id, $invalid->id, $otherGym->id],
        'enabled' => true,
    ])->assertOk()
        ->assertJsonPath('updated', 1)
        ->assertJsonPath('invalid_phone.0.id', $invalid->id);

    expect($valid->fresh()->allow_whatsapp_notifications)->toBeTrue()
        ->and($valid->fresh()->whatsapp_opt_in_at)->not->toBeNull()
        ->and($invalid->fresh()->allow_whatsapp_notifications)->toBeFalse()
        ->and($otherGym->fresh()->allow_whatsapp_notifications)->toBeFalse();

    $this->postJson('/api/members/whatsapp-notifications', [
        'member_ids' => [$valid->id],
        'enabled' => false,
    ])->assertOk()->assertJsonPath('updated', 1);

    expect($valid->fresh()->allow_whatsapp_notifications)->toBeFalse()
        ->and($valid->fresh()->whatsapp_opt_in_at)->toBeNull();
});

test('el historial de WhatsApp solo es visible para el gimnasio del cliente', function () {
    $user = whatsappGymAdmin();
    $member = whatsappMember($user);
    $plan = \App\Models\MembershipPlan::factory()->create(['gym_id' => $user->gimnasio_id]);
    $membership = \App\Models\Membership::factory()->create(['member_id' => $member->id, 'plan_id' => $plan->id]);

    MembershipNotification::create([
        'gimnasio_id' => $user->gimnasio_id,
        'member_id' => $member->id,
        'membership_id' => $membership->id,
        'channel' => 'whatsapp',
        'type' => 'membership_expiring_soon',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->getJson("/api/members/{$member->id}/whatsapp-notifications")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.status', 'sent')
        ->assertJsonPath('0.membership.id', $membership->id);

    $foreign = whatsappMember($user, ['gimnasio_id' => Gimnasio::factory()->create()->id]);
    $this->getJson("/api/members/{$foreign->id}/whatsapp-notifications")->assertNotFound();
});

test('no se puede ver, editar, borrar ni enrolar huella de un cliente de otro gimnasio', function () {
    $user = whatsappGymAdmin();
    $own = whatsappMember($user);
    $foreign = whatsappMember($user, ['gimnasio_id' => Gimnasio::factory()->create()->id, 'name' => 'Ajeno']);

    $this->getJson("/api/members/{$own->id}")->assertOk();

    $this->getJson("/api/members/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/members/{$foreign->id}", ['name' => 'Hackeado'])->assertNotFound();
    $this->postJson("/api/members/{$foreign->id}/fingerprint", ['fingerprint_data' => 'x'])->assertNotFound();
    $this->deleteJson("/api/members/{$foreign->id}")->assertNotFound();

    expect($foreign->fresh())->not->toBeNull()
        ->and($foreign->fresh()->name)->toBe('Ajeno')
        ->and($foreign->fresh()->fingerprint_data)->toBeNull();
});

test('el kiosco por cédula solo devuelve datos mínimos del cliente', function () {
    $user = whatsappGymAdmin();
    $member = whatsappMember($user, ['fingerprint_data' => 'secreto', 'medical_history' => 'privado']);

    $response = $this->postJson('/api/access/identification', [
        'identification' => $member->identification,
        'gimnasio_id' => $user->gimnasio_id,
    ]);

    expect(array_keys($response->json('member')))->toEqualCanonicalizing(['id', 'name', 'identification']);
});

test('las rutas públicas de huella están desactivadas', function () {
    $user = whatsappGymAdmin();

    $this->getJson("/api/access/fingerprints/{$user->gimnasio_id}")->assertNotFound();
    $this->getJson("/api/kiosk/fingerprints/{$user->gimnasio_id}")->assertNotFound();
    $this->postJson('/api/access/fingerprint', ['member_id' => 1])->assertNotFound();
    $this->postJson('/api/access/fingerprint/match', [])->assertNotFound();
});
