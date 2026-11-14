<?php

use App\Mail\SkNotice;
use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Barangay;
use App\Models\Budget;
use App\Models\User;
use App\Support\AccessQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | Note for these tests: signing the Admin in through the real form (not actingAs) keeps the barangay "web" guard
 | free, so the same test can also act as a barangay user. After an Admin request the default guard stays on
 | "admin" inside a test, so Auth::shouldUse('web') puts it back before the next barangay request.
 */

function adminSignedIn($test): Admin
{
    $admin = Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    $test->post('/admin/login', ['email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    return $admin;
}

function openRequest(array $over = []): AccessRequest
{
    $request = new AccessRequest();
    $request->forceFill(array_merge([
        'email'       => 'juan@example.com',
        'last_name'   => 'Dela Cruz',
        'first_name'  => 'Juan',
        'middle_name' => 'Santos',
        'barangay_id' => Barangay::defaultId(),
        'role'        => 'treasurer',
        'status'      => 'pending',
    ], $over))->save();

    return $request;
}

/** An account made with a secret code and waiting for the Admin (linked to its request). */
function pendingAccount(array $over = []): User
{
    $request = openRequest(['status' => 'registered']);

    $user = new User(['name' => 'Juan Santos Dela Cruz', 'email' => 'juan@example.com', 'password' => 'Passw0rd123']);
    $user->role              = 'treasurer';
    $user->barangay_id       = Barangay::defaultId();
    $user->status            = 'pending';
    $user->access_request_id = $request->id;
    $user->forceFill($over)->save();

    return $user;
}

function holderOf(string $role, array $over = []): User
{
    $user = User::factory()->create(array_merge(
        ['name' => 'Current Holder', 'email' => 'holder@example.com'],
        array_intersect_key($over, ['name' => 1, 'email' => 1])
    ));
    $user->forceFill(array_merge(['role' => $role, 'barangay_id' => Barangay::defaultId()], $over))->save();

    return $user;
}

/* ------------------------------------------------------------
 | The Admin's own sign-in and system
 * ------------------------------------------------------------ */

it('shows the admin sign-in page', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Admin sign in');
});

it('sends signed-out people and barangay accounts to the admin sign-in page', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));
    $this->get('/admin/requests')->assertRedirect(route('admin.login'));

    $this->actingAs(User::factory()->create())->get('/admin')->assertRedirect(route('admin.login'));
});

it('signs the admin in and out', function () {
    adminSignedIn($this);

    $this->assertAuthenticated('admin');
    $this->get('/admin')->assertOk()->assertSee('Requests waiting');

    $this->post('/admin/logout')->assertRedirect(route('admin.login'));

    $this->assertGuest('admin');
});

it('does not sign a barangay account in on the admin page', function () {
    $user = User::factory()->create();

    $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest('admin');
});

it('keeps the admin out of the barangay finance pages', function () {
    adminSignedIn($this);
    Auth::shouldUse('web');

    $this->get('/budget')->assertRedirect(route('login'));
});

it('sends the admin to the barangay sign-in page link from the admin page', function () {
    $this->get('/admin/login')->assertSee('Barangay sign in');
});

/* ------------------------------------------------------------
 | Step 1: access requests
 * ------------------------------------------------------------ */

it('lists the requests by status and shows every detail of one', function () {
    adminSignedIn($this);
    $request = openRequest();

    $this->get('/admin/requests')->assertOk()
        ->assertSee('Dela Cruz, Juan Santos')
        ->assertSee('juan@example.com')
        ->assertSee('Cantoria No. 4');

    $this->get(route('admin.requests.show', $request))->assertOk()
        ->assertSee('Dela Cruz')
        ->assertSee('Juan')
        ->assertSee('Santos')
        ->assertSee('juan@example.com')
        ->assertSee('SK Treasurer');

    $this->get('/admin/requests?status=denied')->assertOk()->assertDontSee('Dela Cruz, Juan Santos');
});

it('approves a request and emails the secret code, keeping only its hash', function () {
    Mail::fake();
    adminSignedIn($this);
    $request = openRequest();

    $this->post(route('admin.requests.approve', $request))->assertRedirect(route('admin.requests.show', $request));

    $request->refresh();
    expect($request->status)->toBe('approved')
        ->and($request->code_hash)->toHaveLength(64)
        ->and($request->code_expires_at->isFuture())->toBeTrue();

    Mail::assertSent(SkNotice::class, function (SkNotice $mail) use ($request) {
        return $mail->hasTo('juan@example.com')
            && filled($mail->code)
            && $request->codeMatches($mail->code);
    });
});

it('does not approve a request for a place that already has an active holder', function () {
    Mail::fake();
    adminSignedIn($this);
    holderOf('treasurer');
    $request = openRequest();

    $this->post(route('admin.requests.approve', $request))->assertSessionHasErrors('request');

    expect($request->refresh()->status)->toBe('pending');
    Mail::assertNothingSent();
});

it('can send a fresh code for a request approved earlier', function () {
    Mail::fake();
    adminSignedIn($this);
    $request = openRequest();

    $this->post(route('admin.requests.approve', $request));
    $first = $request->refresh()->code_hash;

    $this->post(route('admin.requests.approve', $request));

    expect($request->refresh()->code_hash)->not->toBe($first);
    Mail::assertSent(SkNotice::class, 2);
});

it('messages the applicant about a mistake and leaves the request pending', function () {
    Mail::fake();
    adminSignedIn($this);
    $request = openRequest();

    $this->post(route('admin.requests.message', $request), ['message' => 'Please confirm the spelling of your last name.'])
        ->assertRedirect(route('admin.requests.show', $request));

    $request->refresh();
    expect($request->status)->toBe('pending')
        ->and($request->last_message)->toBe('Please confirm the spelling of your last name.')
        ->and($request->last_message_at)->not->toBeNull();

    Mail::assertSent(SkNotice::class, fn (SkNotice $mail) => $mail->hasTo('juan@example.com') && in_array('Please confirm the spelling of your last name.', $mail->lines, true));
});

it('needs a message to send one', function () {
    Mail::fake();
    adminSignedIn($this);

    $this->post(route('admin.requests.message', openRequest()), ['message' => ''])->assertSessionHasErrors('message');

    Mail::assertNothingSent();
});

it('denies a request, tells the person, and lets them ask again', function () {
    Mail::fake();
    adminSignedIn($this);
    $request = openRequest();

    $this->post(route('admin.requests.deny', $request), ['reason' => 'Not on the SK roster.'])->assertRedirect(route('admin.requests.index'));

    $request->refresh();
    expect($request->status)->toBe('denied')->and($request->code_hash)->toBeNull();

    Mail::assertSent(SkNotice::class, fn (SkNotice $mail) => $mail->hasTo('juan@example.com') && in_array('Reason: Not on the SK roster.', $mail->lines, true));

    // a denied request is finished
    $this->post(route('admin.requests.approve', $request))->assertSessionHasErrors('request');
});

it('shows the code on screen when the email could not be sent, so the decision is not lost', function () {
    adminSignedIn($this);
    $request = openRequest();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));

    $this->post(route('admin.requests.approve', $request))
        ->assertRedirect(route('admin.requests.show', $request))
        ->assertSessionHas('code_fallback')
        ->assertSessionHas('warning');

    expect($request->refresh()->status)->toBe('approved');
});

/* ------------------------------------------------------------
 | Step 2: accounts waiting for approval, and succession
 * ------------------------------------------------------------ */

it('lists the pending accounts and compares the name with the request', function () {
    adminSignedIn($this);
    $account = pendingAccount(['name' => 'Juan S. Dela Cruz']);

    $this->get('/admin/accounts')->assertOk()->assertSee('Juan S. Dela Cruz')->assertSee('Waiting for approval');

    $this->get(route('admin.accounts.show', $account))->assertOk()
        ->assertSee('Name on the account')
        ->assertSee('Name in the approved request')
        ->assertSee('different from the name in the request');
});

it('does not warn when the name matches the request', function () {
    adminSignedIn($this);
    $account = pendingAccount();

    $this->get(route('admin.accounts.show', $account))->assertOk()->assertDontSee('different from the name in the request');
});

it('approves an account, emails the person, and then lets them sign in', function () {
    Mail::fake();
    adminSignedIn($this);
    $account = pendingAccount();

    $this->post(route('admin.accounts.approve', $account))->assertRedirect(route('admin.accounts.show', $account));

    $account->refresh();
    expect($account->status)->toBe('approved')->and($account->approved_at)->not->toBeNull();
    Mail::assertSent(SkNotice::class, fn (SkNotice $mail) => $mail->hasTo('juan@example.com'));

    Auth::shouldUse('web');
    $this->post('/login', ['email' => 'juan@example.com', 'password' => 'Passw0rd123'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();
});

it('does not approve an account for a place that already has an active holder', function () {
    adminSignedIn($this);
    holderOf('treasurer');
    $account = pendingAccount();

    $this->post(route('admin.accounts.approve', $account))->assertSessionHasErrors('account');

    expect($account->refresh()->status)->toBe('pending');
});

it('messages the owner of a pending account and keeps it pending', function () {
    Mail::fake();
    adminSignedIn($this);
    $account = pendingAccount();

    $this->post(route('admin.accounts.message', $account), ['message' => 'Your name is incomplete.'])
        ->assertRedirect(route('admin.accounts.show', $account));

    expect($account->refresh()->status)->toBe('pending')
        ->and($account->accessRequest->last_message)->toBe('Your name is incomplete.');
    Mail::assertSent(SkNotice::class, fn (SkNotice $mail) => $mail->hasTo('juan@example.com'));
});

it('denies a pending account by removing it and freeing the person to ask again', function () {
    Mail::fake();
    adminSignedIn($this);
    $account = pendingAccount();

    $this->post(route('admin.accounts.deny', $account), ['reason' => 'Wrong barangay.'])->assertRedirect(route('admin.accounts.index'));

    $this->assertDatabaseMissing('users', ['email' => 'juan@example.com']);
    expect(AccessRequest::first()->status)->toBe('denied');
    Mail::assertSent(SkNotice::class, fn (SkNotice $mail) => $mail->hasTo('juan@example.com'));
});

it('does not deny an approved account (it is switched off instead)', function () {
    adminSignedIn($this);
    $user = holderOf('treasurer');

    $this->post(route('admin.accounts.deny', $user))->assertSessionHasErrors('account');

    $this->assertDatabaseHas('users', ['email' => 'holder@example.com']);
});

it('switches an account off at the end of a term, keeps its records, and frees the place', function () {
    adminSignedIn($this);
    $old = holderOf('treasurer');

    // something the old treasurer recorded (made with nobody signed in, it goes to Cantoria No. 4)
    Budget::create(['year' => 2026, 'type' => 'regular', 'total_budget' => 1000, 'opening_bank_balance' => 0, 'opening_cash_balance' => 0]);

    $this->post(route('admin.accounts.disable', $old))->assertRedirect(route('admin.accounts.show', $old));

    $old->refresh();
    expect($old->is_active)->toBeFalse()->and($old->deactivated_at)->not->toBeNull();

    // the old treasurer cannot sign in, and says why
    Auth::shouldUse('web');
    $this->post('/login', ['email' => 'holder@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been switched off. Please contact the SK Federation.']);

    // everything the account recorded is still there
    expect(Budget::withoutGlobalScopes()->where('barangay_id', Barangay::defaultId())->count())->toBe(1);

    // and the new appointee can now be approved
    $newcomer = pendingAccount(['email' => 'new@example.com']);
    $this->post(route('admin.accounts.approve', $newcomer))->assertSessionHasNoErrors();

    expect($newcomer->refresh()->status)->toBe('approved');
});

it('switches an account back on, unless the place was taken in the meantime', function () {
    adminSignedIn($this);
    $old = holderOf('treasurer', ['is_active' => false, 'deactivated_at' => now()]);

    $this->post(route('admin.accounts.enable', $old))->assertSessionHasNoErrors();
    expect($old->refresh()->is_active)->toBeTrue();

    $old->forceFill(['is_active' => false])->save();
    $other = holderOf('treasurer', ['email' => 'other@example.com']);

    $this->post(route('admin.accounts.enable', $old))->assertSessionHasErrors('account');
    expect($old->refresh()->is_active)->toBeFalse();
});

it('does not let a pending account use the QR Access ID', function () {
    $user  = pendingAccount();
    $token = Str::random(48);

    $user->forceFill([
        'qr_token_hash' => AccessQr::hash($token),
        'qr_pin'        => '123456',
        'qr_issued_at'  => now(),
    ])->save();

    $this->post('/qr/' . $token, ['pin' => '123456'])->assertSessionHasErrors();

    $this->assertGuest();
});

/* ------------------------------------------------------------
 | The whole journey
 * ------------------------------------------------------------ */

it('walks a person from the request to a signed-in account', function () {
    Mail::fake();
    adminSignedIn($this);
    Auth::shouldUse('web');

    // 1. the person asks for access
    $this->post('/request-access', [
        'email' => 'juan@example.com', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Santos',
        'barangay_id' => Barangay::defaultId(), 'role' => 'treasurer',
    ])->assertRedirect(route('request-access.sent'));

    $request = AccessRequest::first();

    // 2. the Admin approves and the code is emailed
    $this->post(route('admin.requests.approve', $request));

    $code = null;
    Mail::assertSent(SkNotice::class, function (SkNotice $mail) use (&$code) {
        $code = $mail->code;

        return $mail->hasTo('juan@example.com');
    });
    expect($code)->not->toBeNull();

    // 3. the person makes the account with the code, and cannot sign in yet
    Auth::shouldUse('web');
    $this->post('/register', [
        'name' => 'Juan Santos Dela Cruz', 'email' => 'juan@example.com', 'barangay_id' => Barangay::defaultId(),
        'role' => 'treasurer', 'access_code' => $code, 'password' => 'Passw0rd123', 'password_confirmation' => 'Passw0rd123',
    ])->assertRedirect(route('login'));

    $user = User::where('email', 'juan@example.com')->first();
    expect($user->status)->toBe('pending');

    $this->post('/login', ['email' => 'juan@example.com', 'password' => 'Passw0rd123'])->assertSessionHasErrors('email');
    $this->assertGuest();

    // 4. the Admin approves the account, and now the person can sign in
    $this->post(route('admin.accounts.approve', $user))->assertSessionHasNoErrors();

    Auth::shouldUse('web');
    $this->post('/login', ['email' => 'juan@example.com', 'password' => 'Passw0rd123'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();
});

/* ------------------------------------------------------------
 | Overview pages and the activity log
 * ------------------------------------------------------------ */

it('shows all barangays with who holds the Treasurer place', function () {
    adminSignedIn($this);
    holderOf('treasurer', ['name' => 'Maria Treasurer']);

    $this->get('/admin/barangays')->assertOk()
        ->assertSee('Busel-Busel')
        ->assertSee('Maria Treasurer')
        ->assertSee('vacant');
});

it('shows the activity log and filters it by barangay and text', function () {
    adminSignedIn($this);

    $suyo = Barangay::where('slug', 'suyo')->value('id');

    ActivityLog::create(['barangay_id' => Barangay::defaultId(), 'action' => 'created', 'description' => 'Marker for cantoria', 'created_at' => now()]);
    ActivityLog::create(['barangay_id' => $suyo, 'action' => 'created', 'description' => 'Marker for suyo', 'created_at' => now()]);

    $this->get('/admin/activity')->assertOk()->assertSee('Marker for cantoria')->assertSee('Marker for suyo');

    $this->get('/admin/activity?barangay_id=' . $suyo)->assertOk()->assertSee('Marker for suyo')->assertDontSee('Marker for cantoria');

    $this->get('/admin/activity?q=cantoria')->assertOk()->assertSee('Marker for cantoria')->assertDontSee('Marker for suyo');
});

it('writes the admin decisions in the activity log under the admin name', function () {
    Mail::fake();
    adminSignedIn($this);
    $request = openRequest();

    $this->post(route('admin.requests.approve', $request));

    $log = ActivityLog::where('action', 'approved')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_name)->toBe('Admin Federation President')
        ->and($log->user_role)->toBe('admin');
});
