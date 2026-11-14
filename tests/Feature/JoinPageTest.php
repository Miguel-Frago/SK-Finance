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

it('shows the treasurer QR codes for the create account and sign in pages', function () {
    $this->actingAs(joinAccount('treasurer'))
        ->get('http://192.168.1.10:8000/join')
        ->assertOk()
        ->assertSee('http://192.168.1.10:8000/request-access')
        ->assertSee('http://192.168.1.10:8000/login')
        ->assertSee('<svg', false);
});

it('warns when the page was opened with an address a phone cannot use', function () {
    $this->actingAs(joinAccount('treasurer'))
        ->get('http://sk-finance.test/join')
        ->assertOk()
        ->assertSee('only works on this computer');
});

it('keeps the share page away from kagawads and the chairman', function () {
    $this->actingAs(joinAccount('kagawad'))->get('/join')->assertForbidden();
    $this->actingAs(joinAccount('chairman'))->get('/join')->assertForbidden();
});
