<?php

use App\Models\User;
use App\Support\AccessQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Valid form data for creating a card. */
function cardPayload(string $pin, array $extra = []): array
{
    return array_merge([
        'current_password' => 'password',
        'pin'              => $pin,
        'pin_confirmation' => $pin,
        'valid_until'      => now()->addYear()->toDateString(),
    ], $extra);
}

/** A user who already has a card. Returns [user, token]. */
function userWithCard(string $pin = '123456'): array
{
    $token = Str::random(48);

    $user = User::factory()->create();
    $user->forceFill([
        'qr_token_hash' => AccessQr::hash($token),
        'qr_pin'        => $pin,
        'qr_issued_at'  => now(),
    ])->save();

    return [$user, $token];
}

it('shows the PIN page for any well-formed token without revealing if it exists', function () {
    $this->get('/qr/' . Str::random(48))->assertOk()->assertSee('6-digit PIN');
});

it('asks for the PIN even when this browser is already signed in', function () {
    [$user, $token] = userWithCard('123456');

    $this->actingAs($user)->get('/qr/' . $token)
        ->assertOk()
        ->assertSee('6-digit PIN');

    $this->assertGuest();
});

it('signs in as the card owner after the PIN, replacing whoever was signed in', function () {
    [$owner, $token] = userWithCard('123456');
    $other = User::factory()->create();

    $this->actingAs($other)->get('/qr/' . $token);
    $this->post('/qr/' . $token, ['pin' => '123456'])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($owner);
});

it('rejects a malformed token', function () {
    $this->get('/qr/short')->assertNotFound();
});

it('signs in with the right card and PIN', function () {
    [$user, $token] = userWithCard('123456');

    $this->post('/qr/' . $token, ['pin' => '123456'])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('does not sign in with a wrong PIN', function () {
    [, $token] = userWithCard('123456');

    $this->post('/qr/' . $token, ['pin' => '000000'])->assertSessionHasErrors('pin');

    $this->assertGuest();
});

it('does not sign in with an unknown card', function () {
    userWithCard('123456');

    $this->post('/qr/' . Str::random(48), ['pin' => '123456'])->assertSessionHasErrors('pin');

    $this->assertGuest();
});

it('locks the card after 5 wrong PINs, even if the right PIN comes next', function () {
    [, $token] = userWithCard('123456');

    foreach (range(1, 5) as $i) {
        $this->post('/qr/' . $token, ['pin' => '000000']);
    }

    $this->post('/qr/' . $token, ['pin' => '123456'])->assertSessionHasErrors('pin');

    $this->assertGuest();
});

it('lets a signed-in user create a card, and stores only hashes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('access-id.store'), cardPayload('654321'))
        ->assertRedirect(route('access-id.card'))
        ->assertSessionHas('access_id_card');

    $card  = session('access_id_card');
    $token = Str::afterLast($card['url'], '/qr/');

    expect($token)->toHaveLength(48)
        ->and($card['barangay'])->toBe('Brgy. Cantoria No. 4, Luna, La Union')
        ->and($card)->not->toHaveKey('pin');

    $user->refresh();
    expect($user->qr_token_hash)->toBe(AccessQr::hash($token))
        ->and($user->qr_token_hash)->not->toBe($token)
        ->and($user->qr_pin)->not->toBe('654321')
        ->and($user->hasAccessId())->toBeTrue();
});

it('needs the current password to create a card', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('access-id.store'), cardPayload('654321', ['current_password' => 'wrong']))
        ->assertSessionHasErrors('current_password', null, 'accessId');

    expect($user->refresh()->hasAccessId())->toBeFalse();
});

it('needs a future valid-until date', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('access-id.store'), cardPayload('654321', ['valid_until' => now()->subDay()->toDateString()]))
        ->assertSessionHasErrors('valid_until', null, 'accessId');
});

it('shows the printable card with the name, details and QR code, but never the PIN', function () {
    $user = User::factory()->create(['name' => 'Juan Dela Cruz', 'position' => 'SK Treasurer 2026']);

    $this->actingAs($user)->post(route('access-id.store'), cardPayload('654321'));

    $this->get(route('access-id.card'))
        ->assertOk()
        ->assertSee('Juan Dela Cruz')
        ->assertSee('Brgy. Cantoria No. 4, Luna, La Union')
        ->assertSee('SK Treasurer 2026')
        ->assertSee('Scan to Access')
        ->assertSee('1/8 index card')
        ->assertSee('<svg', false)
        ->assertDontSee('654321');
});

it('sends you back to the profile when there is no new card to show', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('access-id.card'))->assertRedirect(route('profile.edit'));
});

it('wipes the QR from the session when you press Done', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('access-id.store'), cardPayload('654321'));
    $this->post(route('access-id.card.done'))->assertRedirect(route('profile.edit'));

    $this->assertNull(session('access_id_card'));
});

it('switches the old card off when a new one is made', function () {
    [$user, $oldToken] = userWithCard('111111');

    $this->actingAs($user)->post(route('access-id.store'), cardPayload('222222'));

    auth()->logout();

    $this->post('/qr/' . $oldToken, ['pin' => '222222'])->assertSessionHasErrors('pin');
    $this->assertGuest();
});

it('revokes the card', function () {
    [$user, $token] = userWithCard('123456');

    $this->actingAs($user)->delete(route('access-id.destroy'), ['current_password' => 'password'])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->hasAccessId())->toBeFalse();

    auth()->logout();
    $this->post('/qr/' . $token, ['pin' => '123456'])->assertSessionHasErrors('pin');
    $this->assertGuest();
});

it('shows the profile page with the Access ID section', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee('QR Access ID');
});
