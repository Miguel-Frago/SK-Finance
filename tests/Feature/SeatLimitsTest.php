<?php

use App\Mail\SkNotice;
use App\Models\AccessRequest;
use App\Models\Admin;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function seatAdmin($test): Admin
{
    $admin = Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    $test->post('/admin/login', ['email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    return $admin;
}

/** An approved, switched-on account in Cantoria No. 4 (or another barangay). */
function seatUser(string $role, string $email, array $over = []): User
{
    $user = User::factory()->create(['name' => ucfirst($role) . ' ' . $email, 'email' => $email]);
    $user->forceFill(array_merge(['role' => $role, 'barangay_id' => Barangay::defaultId()], $over))->save();

    return $user;
}

function seatFill(string $role, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        seatUser($role, "{$role}{$i}@example.com");
    }
}

function seatRequest(string $role, string $status = 'pending', string $email = 'new@example.com'): AccessRequest
{
    $request = new AccessRequest();
    $request->forceFill([
        'email' => $email, 'last_name' => 'Cruz', 'first_name' => 'Ana', 'barangay_id' => Barangay::defaultId(),
        'role' => $role, 'status' => $status,
    ])->save();

    return $request;
}

function seatPending(string $role, string $email = 'pending@example.com'): User
{
    $user = new User(['name' => 'Pending Person', 'email' => $email, 'password' => 'Passw0rd123']);
    $user->role        = $role;
    $user->barangay_id = Barangay::defaultId();
    $user->status      = 'pending';
    $user->save();

    return $user;
}

/* ------------------------------------------------------------
 | The new positions
 * ------------------------------------------------------------ */

it('offers the Secretary and Kagawad positions on both forms', function () {
    $this->get('/request-access')->assertOk()->assertSee('SK Secretary')->assertSee('SK Kagawad');
    $this->get('/register')->assertOk()->assertSee('SK Secretary')->assertSee('SK Kagawad');
});

it('takes a request for the Secretary and for a Kagawad', function () {
    foreach (['secretary', 'kagawad'] as $role) {
        $this->post('/request-access', [
            'email' => "{$role}@example.com", 'last_name' => 'Cruz', 'first_name' => 'Ana',
            'barangay_id' => Barangay::defaultId(), 'role' => $role,
        ])->assertRedirect(route('request-access.sent'));
    }

    expect(AccessRequest::pluck('role')->all())->toBe(['secretary', 'kagawad']);
});

it('lets a person with an approved request make a Secretary account that waits for approval', function () {
    $code    = AccessRequest::newCode();
    $request = seatRequest('secretary', 'approved', 'sec@example.com');
    $request->forceFill(['code_hash' => AccessRequest::hashCode($code), 'code_expires_at' => now()->addDays(7)])->save();

    $this->post('/register', [
        'name' => 'Ana Cruz', 'email' => 'sec@example.com', 'barangay_id' => Barangay::defaultId(), 'role' => 'secretary',
        'access_code' => $code, 'password' => 'Passw0rd123', 'password_confirmation' => 'Passw0rd123',
    ])->assertRedirect(route('login'));

    $user = User::where('email', 'sec@example.com')->first();
    expect($user->role)->toBe('secretary')->and($user->status)->toBe('pending');
});

/* ------------------------------------------------------------
 | One Secretary, seven Kagawads
 * ------------------------------------------------------------ */

it('refuses a request for a Secretary place that is already taken', function () {
    seatUser('secretary', 'sec@example.com');

    $this->post('/request-access', [
        'email' => 'another@example.com', 'last_name' => 'Cruz', 'first_name' => 'Ben',
        'barangay_id' => Barangay::defaultId(), 'role' => 'secretary',
    ])->assertSessionHasErrors('role');

    $this->assertDatabaseCount('access_requests', 0);
});

it('takes a request for the seventh Kagawad but not for an eighth', function () {
    seatFill('kagawad', 6);

    $this->post('/request-access', [
        'email' => 'seventh@example.com', 'last_name' => 'Cruz', 'first_name' => 'Ana',
        'barangay_id' => Barangay::defaultId(), 'role' => 'kagawad',
    ])->assertRedirect(route('request-access.sent'));

    seatUser('kagawad', 'seventh@example.com');

    $this->post('/request-access', [
        'email' => 'eighth@example.com', 'last_name' => 'Cruz', 'first_name' => 'Ben',
        'barangay_id' => Barangay::defaultId(), 'role' => 'kagawad',
    ])->assertSessionHasErrors('role');

    expect(AccessRequest::where('email', 'eighth@example.com')->exists())->toBeFalse();
});

it('counts Kagawads per barangay', function () {
    seatFill('kagawad', 7);

    $suyo = Barangay::where('slug', 'suyo')->value('id');

    $this->post('/request-access', [
        'email' => 'suyo@example.com', 'last_name' => 'Cruz', 'first_name' => 'Ana', 'barangay_id' => $suyo, 'role' => 'kagawad',
    ])->assertRedirect(route('request-access.sent'));
});

it('does not count switched-off Kagawads', function () {
    seatFill('kagawad', 6);
    seatUser('kagawad', 'gone@example.com', ['is_active' => false]);

    expect(User::seatAvailable(Barangay::defaultId(), 'kagawad'))->toBeTrue();
});

it('does not approve a second Secretary request', function () {
    Mail::fake();
    seatAdmin($this);
    seatUser('secretary', 'sec@example.com');
    $request = seatRequest('secretary');

    $this->post(route('admin.requests.approve', $request))->assertSessionHasErrors('request');

    expect($request->refresh()->status)->toBe('pending');
    Mail::assertNothingSent();
});

it('does not approve an eighth Kagawad request, but does once a place is freed', function () {
    Mail::fake();
    seatAdmin($this);
    seatFill('kagawad', 7);
    $request = seatRequest('kagawad');

    $this->post(route('admin.requests.approve', $request))->assertSessionHasErrors('request');
    expect(session('errors')->first('request'))->toContain('all 7')->toContain('SK Kagawad');
    Mail::assertNothingSent();

    User::where('email', 'kagawad7@example.com')->first()->forceFill(['is_active' => false])->save();

    $this->post(route('admin.requests.approve', $request))->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe('approved');
    Mail::assertSent(SkNotice::class, fn (SkNotice $mail) => $mail->hasTo('new@example.com'));
});

it('does not approve an eighth Kagawad account, but approves the seventh', function () {
    Mail::fake();
    seatAdmin($this);
    seatFill('kagawad', 6);

    $seventh = seatPending('kagawad', 'seventh@example.com');
    $this->post(route('admin.accounts.approve', $seventh))->assertSessionHasNoErrors();
    expect($seventh->refresh()->status)->toBe('approved');

    $eighth = seatPending('kagawad', 'eighth@example.com');
    $this->post(route('admin.accounts.approve', $eighth))->assertSessionHasErrors('account');
    expect($eighth->refresh()->status)->toBe('pending');
});

it('does not switch a Kagawad back on when the seven places are full', function () {
    seatAdmin($this);
    seatFill('kagawad', 7);
    $old = seatUser('kagawad', 'old@example.com', ['is_active' => false, 'deactivated_at' => now()]);

    $this->post(route('admin.accounts.enable', $old))->assertSessionHasErrors('account');
    expect($old->refresh()->is_active)->toBeFalse();

    User::where('email', 'kagawad1@example.com')->first()->forceFill(['is_active' => false])->save();

    $this->post(route('admin.accounts.enable', $old))->assertSessionHasNoErrors();
    expect($old->refresh()->is_active)->toBeTrue();
});

/* ------------------------------------------------------------
 | The Admin's overview
 * ------------------------------------------------------------ */

it('shows the Secretary and the number of Kagawads on the barangay overview', function () {
    seatAdmin($this);
    seatUser('secretary', 'sec@example.com');
    seatFill('kagawad', 3);

    $this->get('/admin/barangays')->assertOk()
        ->assertSee('SK Secretary')
        ->assertSee('Secretary sec@example.com')
        ->assertSee('3 of 7');
});

it('puts the Secretary and Kagawads in the barangay CSV', function () {
    seatAdmin($this);
    seatUser('secretary', 'sec@example.com');
    seatFill('kagawad', 2);

    $csv = $this->get('/admin/barangays?export=csv')->streamedContent();

    expect($csv)->toContain('SK Secretary')->toContain('Kagawads')->toContain('2 of 7')->toContain('sec@example.com');
});

/* ------------------------------------------------------------
 | What the Secretary and Kagawads can do
 * ------------------------------------------------------------ */

it('lets the Secretary and Kagawads look at the finances but not change them', function () {
    foreach (['secretary', 'kagawad'] as $role) {
        $user = seatUser($role, "{$role}-view@example.com");

        $this->actingAs($user)->get('/budget')->assertOk();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get('/transactions/create')->assertForbidden();
        $this->actingAs($user)->get('/fund-movements/create')->assertForbidden();
        $this->actingAs($user)->post('/budget', [])->assertForbidden();
    }
});

it('shows the Minutes link and the barangay name, not "Treasurer Portal", to every role', function () {
    foreach (['treasurer', 'chairman', 'secretary', 'kagawad'] as $role) {
        $this->actingAs(seatUser($role, "{$role}-nav@example.com"))->get('/budget')
            ->assertOk()
            ->assertSee('Minutes of Meetings')
            ->assertSee('Cantoria No. 4 Portal')
            ->assertDontSee('Treasurer Portal');
    }
});
