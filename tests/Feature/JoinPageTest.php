<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A saved account with the given role. */
function joinAccount(string $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

it('does not expose the removed share sign-up page', function () {
    $this->actingAs(joinAccount('treasurer'))->get('/join')->assertNotFound();
});



it('keeps the personal QR Access ID login route available', function () {
    $this->get('/qr/' . str_repeat('A', 48))->assertOk();
});
