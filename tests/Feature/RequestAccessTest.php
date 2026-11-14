<?php

use App\Models\AccessRequest;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Valid "Request access" form data. */
function requestForm(array $extra = []): array
{
    return array_merge([
        'email'       => 'Juan@Example.com',
        'last_name'   => 'Dela Cruz',
        'first_name'  => 'Juan',
        'middle_name' => 'Santos',
        'barangay_id' => Barangay::defaultId(),
        'role'        => 'treasurer',
    ], $extra);
}

it('shows the request form with the barangay list, position and an optional middle name', function () {
    $this->get('/request-access')->assertOk()
        ->assertSee('Request access')
        ->assertSee('Last name')
        ->assertSee('First name')
        ->assertSee('Middle name')
        ->assertSee('optional')
        ->assertSee('Busel-Busel')
        ->assertSee('SK Treasurer')
        ->assertSee('SK Chairman');
});

it('saves a pending request and shows the thank-you page', function () {
    $this->post('/request-access', requestForm())->assertRedirect(route('request-access.sent'));

    $request = AccessRequest::first();

    expect($request->email)->toBe('juan@example.com')
        ->and($request->status)->toBe('pending')
        ->and($request->full_name)->toBe('Juan Santos Dela Cruz')
        ->and($request->list_name)->toBe('Dela Cruz, Juan Santos')
        ->and($request->code_hash)->toBeNull();

    $this->get('/request-access/sent')->assertOk()->assertSee('Request sent');
});

it('works without a middle name', function () {
    $this->post('/request-access', requestForm(['middle_name' => '']))->assertRedirect(route('request-access.sent'));

    expect(AccessRequest::first()->middle_name)->toBeNull()
        ->and(AccessRequest::first()->full_name)->toBe('Juan Dela Cruz');
});

// The form allows 6 submissions a minute, so each test below stays under that.
it('needs the email, names, barangay and position', function () {
    foreach (['email', 'last_name', 'first_name', 'barangay_id', 'role'] as $field) {
        $this->post('/request-access', requestForm([$field => '']))->assertSessionHasErrors($field);
    }

    $this->assertDatabaseCount('access_requests', 0);
});

it('refuses a bad email, the removed viewer position and a barangay that is not on the list', function () {
    $this->post('/request-access', requestForm(['email' => 'not-an-email']))->assertSessionHasErrors('email');
    $this->post('/request-access', requestForm(['role' => 'viewer']))->assertSessionHasErrors('role');
    $this->post('/request-access', requestForm(['barangay_id' => 99999]))->assertSessionHasErrors('barangay_id');

    $this->assertDatabaseCount('access_requests', 0);
});

it('does not take a second request from the same email while one is open', function () {
    $this->post('/request-access', requestForm());
    $this->post('/request-access', requestForm())->assertSessionHasErrors('email');

    expect(AccessRequest::count())->toBe(1);
});

it('does not take a request from an email that already has an account', function () {
    User::factory()->create(['email' => 'juan@example.com']);

    $this->post('/request-access', requestForm())->assertSessionHasErrors('email');

    $this->assertDatabaseCount('access_requests', 0);
});

it('refuses a position that is already held in the barangay', function () {
    $holder = User::factory()->create();
    $holder->forceFill(['role' => 'treasurer', 'barangay_id' => Barangay::defaultId()])->save();

    $this->post('/request-access', requestForm())->assertSessionHasErrors('role');

    $this->assertDatabaseCount('access_requests', 0);
});

it('accepts a request for a place whose holder was switched off', function () {
    $holder = User::factory()->create();
    $holder->forceFill(['role' => 'treasurer', 'barangay_id' => Barangay::defaultId(), 'is_active' => false])->save();

    $this->post('/request-access', requestForm())->assertRedirect(route('request-access.sent'));

    expect(AccessRequest::count())->toBe(1);
});

it('lets someone ask again after a denial', function () {
    $this->post('/request-access', requestForm());
    AccessRequest::first()->forceFill(['status' => 'denied'])->save();

    $this->post('/request-access', requestForm())->assertRedirect(route('request-access.sent'));

    expect(AccessRequest::count())->toBe(2);
});

it('logs the request in the activity log', function () {
    $this->post('/request-access', requestForm());

    $this->assertDatabaseHas('activity_logs', ['action' => 'requested', 'subject_type' => 'AccessRequest']);
});
