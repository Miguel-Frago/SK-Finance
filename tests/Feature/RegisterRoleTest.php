<?php

use App\Models\AccessRequest;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A request the Admin approved, with its secret code. Returns [request, code]. */
function approvedRequest(array $over = []): array
{
    $code = AccessRequest::newCode();

    $request = new AccessRequest();
    $request->forceFill(array_merge([
        'email'           => 'person@example.com',
        'last_name'       => 'Dela Cruz',
        'first_name'      => 'Juan',
        'middle_name'     => 'Santos',
        'barangay_id'     => Barangay::defaultId(),
        'role'            => 'treasurer',
        'status'          => 'approved',
        'code_hash'       => AccessRequest::hashCode($code),
        'code_expires_at' => now()->addDays(7),
    ], $over))->save();

    return [$request, $code];
}

/** Valid "Create account" form data. */
function signUp(string $code, array $extra = []): array
{
    return array_merge([
        'name'                  => 'Juan Santos Dela Cruz',
        'email'                 => 'person@example.com',
        'barangay_id'           => Barangay::defaultId(),
        'role'                  => 'treasurer',
        'access_code'           => $code,
        'password'              => 'Passw0rd123',
        'password_confirmation' => 'Passw0rd123',
    ], $extra);
}

it('shows the barangay list, Register as and Secret code on the create account form', function () {
    $this->get('/register')->assertOk()
        ->assertSee('Register as')
        ->assertSee('Choose position')
        ->assertSee('SK Treasurer')
        ->assertSee('SK Chairman')
        ->assertSee('Secret code')
        ->assertSee('Choose your barangay')
        ->assertSee('Busel-Busel')
        ->assertSee('Request access')
        ->assertDontSee('Viewer');
});

it('makes a pending account when the email, barangay, position and secret code match the approved request', function () {
    [$request, $code] = approvedRequest();

    $this->post('/register', signUp($code))->assertRedirect(route('login'));

    $user = User::where('email', 'person@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Juan Santos Dela Cruz')
        ->and($user->role)->toBe('treasurer')
        ->and($user->barangay_id)->toEqual(Barangay::defaultId())
        ->and($user->status)->toBe('pending')
        ->and($user->access_request_id)->toEqual($request->id);

    $request->refresh();
    expect($request->status)->toBe('registered')
        ->and($request->code_used_at)->not->toBeNull();
});

it('takes the name from the approved request, whatever is sent in the form', function () {
    [, $code] = approvedRequest();

    $this->post('/register', signUp($code, ['name' => 'Somebody Else']))->assertRedirect(route('login'));

    expect(User::where('email', 'person@example.com')->value('name'))->toBe('Juan Santos Dela Cruz');
});

it('accepts the code in small letters and without the dash', function () {
    [, $code] = approvedRequest();

    $typed = strtolower(str_replace('-', '', $code));

    $this->post('/register', signUp($typed))->assertRedirect(route('login'));

    $this->assertDatabaseHas('users', ['email' => 'person@example.com']);
});

it('refuses a wrong secret code', function () {
    approvedRequest();

    $this->post('/register', signUp('AAAAA-BBBBB'))
        ->assertSessionHasErrors(['access_code'], null, 'register');

    $this->assertDatabaseMissing('users', ['email' => 'person@example.com']);
});

it('gives the same answer when there is no request at all, so emails cannot be guessed', function () {
    $this->post('/register', signUp('AAAAA-BBBBB'))
        ->assertSessionHasErrors(['access_code'], null, 'register');

    $this->assertDatabaseCount('users', 0);
});

it('refuses a request the Admin has not approved yet', function () {
    [, $code] = approvedRequest(['status' => 'pending']);

    $this->post('/register', signUp($code))
        ->assertSessionHasErrors(['access_code'], null, 'register');

    $this->assertDatabaseCount('users', 0);
});

it('works only once', function () {
    [, $code] = approvedRequest();

    $this->post('/register', signUp($code))->assertRedirect(route('login'));
    $this->post('/register', signUp($code, ['email' => 'person@example.com']))
        ->assertSessionHasErrors(['access_code'], null, 'register');

    expect(User::where('email', 'person@example.com')->count())->toBe(1);
});

it('refuses an expired secret code', function () {
    [, $code] = approvedRequest(['code_expires_at' => now()->subDay()]);

    $this->post('/register', signUp($code))
        ->assertSessionHasErrors(['access_code'], null, 'register');

    $this->assertDatabaseCount('users', 0);
});

it('needs the same barangay and position as the approved request', function () {
    [, $code] = approvedRequest();

    $suyo = Barangay::where('slug', 'suyo')->value('id');

    $this->post('/register', signUp($code, ['barangay_id' => $suyo]))
        ->assertSessionHasErrors(['role'], null, 'register');

    $this->post('/register', signUp($code, ['role' => 'chairman']))
        ->assertSessionHasErrors(['role'], null, 'register');

    $this->assertDatabaseCount('users', 0);
});

it('needs a barangay, a position and a real position', function () {
    [, $code] = approvedRequest();

    $this->post('/register', signUp($code, ['barangay_id' => '']))
        ->assertSessionHasErrors(['barangay_id'], null, 'register');

    $this->post('/register', signUp($code, ['role' => '']))
        ->assertSessionHasErrors(['role'], null, 'register');

    $this->post('/register', signUp($code, ['role' => 'viewer']))
        ->assertSessionHasErrors(['role'], null, 'register');

    $this->post('/register', signUp($code, ['role' => 'admin']))
        ->assertSessionHasErrors(['role'], null, 'register');

    $this->assertDatabaseCount('users', 0);
});

it('needs a password with letters and numbers', function () {
    [, $code] = approvedRequest();

    $this->post('/register', signUp($code, ['password' => 'short', 'password_confirmation' => 'short']))
        ->assertSessionHasErrors(['password'], null, 'register');

    $this->assertDatabaseCount('users', 0);
});

it('does not let a pending account sign in, and says why', function () {
    [, $code] = approvedRequest();
    $this->post('/register', signUp($code));

    $this->post('/login', ['email' => 'person@example.com', 'password' => 'Passw0rd123'])
        ->assertSessionHasErrors(['email' => "Your account is waiting for the Admin's approval. You will get an email when it is approved."]);

    $this->assertGuest();
});

/** A saved account with the given role, in Cantoria No. 4 unless a barangay slug is given. */
function accountWithRole(string $role, string $slug = 'cantoria-no-4'): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role, 'barangay_id' => Barangay::where('slug', $slug)->value('id')])->save();

    return $user;
}

it('lets a kagawad look at the budget', function () {
    $this->actingAs(accountWithRole('kagawad'))->get('/budget')->assertOk();
});

it('stops a kagawad from adding or changing anything', function () {
    $kagawad = accountWithRole('kagawad');

    $this->actingAs($kagawad)->get('/transactions/create')->assertForbidden();
    $this->actingAs($kagawad)->get('/fund-movements/create')->assertForbidden();
    $this->actingAs($kagawad)->post('/budget', [])->assertForbidden();
});

it('stops a kagawad from changing the names and signatures on the report', function () {
    $this->actingAs(accountWithRole('kagawad'))
        ->putJson('/reports/signatories', ['reportNo' => '2026-001'])
        ->assertForbidden();
});

it('still lets the chairman and the treasurer edit the names and signatures', function () {
    $this->actingAs(accountWithRole('chairman'))
        ->putJson('/reports/signatories', ['reportNo' => '2026-001'])
        ->assertOk();

    $this->actingAs(accountWithRole('treasurer'))
        ->putJson('/reports/signatories', ['reportNo' => '2026-002'])
        ->assertOk();
});
