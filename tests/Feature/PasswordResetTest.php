<?php

use App\Mail\SkNotice;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Barangay;
use App\Models\User;
use App\Support\Passwords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Lost passwords: the Admin's temporary password, choosing your own, and "forgot password" by email
|--------------------------------------------------------------------------
*/

function pwUser(array $o = []): User
{
    $user = User::factory()->create(array_merge(['name' => 'Tess Treasurer', 'email' => 'tess@example.com', 'password' => 'OldPassw0rd1'], $o));
    $user->forceFill(['role' => 'treasurer', 'barangay_id' => Barangay::defaultId(), 'status' => User::STATUS_APPROVED, 'is_active' => true])->save();

    return $user->fresh();
}

function pwAdmin(): Admin
{
    $admin = Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);
    test()->post('/admin/login', ['email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    return $admin;
}

/** The reset link the email carries. */
function pwLinkFrom(string $email): array
{
    $url = null;
    Mail::assertSent(SkNotice::class, function (SkNotice $mail) use ($email, &$url) {
        if ($mail->hasTo($email)) {
            $url = $mail->actionUrl;
        }

        return $mail->hasTo($email);
    });
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = basename((string) parse_url($url, PHP_URL_PATH));

    return [$token, $query['email'] ?? ''];
}

// ---------------------------------------------------------------- the Admin gives a temporary password

it('lets the Admin give a temporary password, shown once, that the person must replace', function () {
    $user = pwUser();
    pwAdmin();

    $res = $this->post(route('admin.accounts.password', $user))->assertRedirect(route('admin.accounts.show', $user));
    $temporary = session('temp_password');
    expect($temporary)->toBeString()->toHaveLength(10)
        ->and($temporary)->toMatch('/[A-Za-z]/')->and($temporary)->toMatch('/[0-9]/');

    // shown on the next page, once
    $this->get(route('admin.accounts.show', $user))->assertOk()->assertSee($temporary)->assertSee('only now');
    $this->get(route('admin.accounts.show', $user))->assertOk()->assertDontSee($temporary);

    $fresh = $user->fresh();
    expect($fresh->must_change_password)->toBeTrue()
        ->and(\Hash::check($temporary, $fresh->password))->toBeTrue()
        ->and(\Hash::check('OldPassw0rd1', $fresh->password))->toBeFalse();

    // the log says it happened, never what the password was
    $log = ActivityLog::where('action', 'password_reset')->first();
    expect($log->description)->toContain('Tess Treasurer')->not->toContain($temporary);
});

it('emails the temporary password only when the Admin ticks the box', function () {
    Mail::fake();
    $user = pwUser();
    pwAdmin();

    $this->post(route('admin.accounts.password', $user))->assertRedirect();
    Mail::assertNothingSent();

    $this->post(route('admin.accounts.password', $user), ['email' => 1])->assertRedirect();
    Mail::assertSent(SkNotice::class, fn (SkNotice $m) => $m->hasTo('tess@example.com') && $m->code === session('temp_password'));
});

it('is only for the Admin, and only for an account that is active', function () {
    $user = pwUser();
    $pending = pwUser(['email' => 'pending@example.com']);
    $pending->forceFill(['status' => User::STATUS_PENDING])->save();

    // a barangay account (any role) cannot use the Admin address
    $this->actingAs(pwUser(['email' => 'chair@example.com']));
    $this->post(route('admin.accounts.password', $user))->assertRedirect();
    expect($user->fresh()->must_change_password)->toBeFalse();

    $this->flushSession();
    Auth::forgetGuards();
    pwAdmin();
    $this->post(route('admin.accounts.password', $pending))->assertNotFound();
    expect($pending->fresh()->must_change_password)->toBeFalse();
});

it('signs the person out of every browser when their password is reset', function () {
    config(['session.driver' => 'database']);
    $user = pwUser();
    $other = pwUser(['email' => 'other@example.com']);
    foreach ([[$user, 'a'], [$user, 'b'], [$other, 'c']] as [$u, $id]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $u->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time()]);
    }

    Passwords::set($user, 'Brand-New-Pass1', true);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);
});

// ---------------------------------------------------------------- choosing your own

it('keeps everything closed until the person has chosen their own password', function () {
    $user = pwUser();
    Passwords::set($user, 'TempPass12', true);

    $this->post('/login', ['email' => 'tess@example.com', 'password' => 'TempPass12'])->assertRedirect();
    $this->get(route('dashboard'))->assertRedirect(route('password.choose'));
    $this->get(route('transactions.index'))->assertRedirect(route('password.choose'));
    $this->getJson('/messages/state')->assertStatus(423);
    $this->get(route('password.choose'))->assertOk()->assertSee('Choose your own password');
    $this->post(route('logout'))->assertRedirect();                       // signing out is always possible
});

it('asks for a real new password and then opens everything', function () {
    $user = pwUser();
    Passwords::set($user, 'TempPass12', true);
    $this->actingAs($user->fresh());

    $this->put(route('password.choose.update'), ['password' => 'TempPass12', 'password_confirmation' => 'TempPass12'])->assertSessionHasErrors('password');   // same as the temporary one
    $this->put(route('password.choose.update'), ['password' => 'short1', 'password_confirmation' => 'short1'])->assertSessionHasErrors('password');
    $this->put(route('password.choose.update'), ['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'])->assertSessionHasErrors('password');
    $this->put(route('password.choose.update'), ['password' => 'Fine-Pass-123', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
    expect($user->fresh()->must_change_password)->toBeTrue();

    $this->put(route('password.choose.update'), ['password' => 'Fine-Pass-123', 'password_confirmation' => 'Fine-Pass-123'])->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();
    expect($fresh->must_change_password)->toBeFalse()->and($fresh->password_changed_at)->not->toBeNull()
        ->and(\Hash::check('Fine-Pass-123', $fresh->password))->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('password.choose'))->assertRedirect(route('dashboard'));       // nothing left to choose
});

it('does not bother an ordinary account with the choose-a-password page', function () {
    $this->actingAs(pwUser());
    $this->get(route('dashboard'))->assertOk();
    $this->put(route('password.choose.update'), ['password' => 'Fine-Pass-123', 'password_confirmation' => 'Fine-Pass-123'])->assertNotFound();
});

// ---------------------------------------------------------------- forgot password

it('shows the forgot-password link on the sign-in page and the form itself', function () {
    $this->get(route('login'))->assertOk()->assertSee('Forgot your password?');
    $this->get(route('password.request'))->assertOk()->assertSee('Send the link')->assertSee('temporary password');
});

it('gives exactly the same answer for every address, and emails only a real active account', function () {
    Mail::fake();
    pwUser();
    pwUser(['email' => 'off@example.com'])->forceFill(['is_active' => false])->save();
    pwUser(['email' => 'new@example.com'])->forceFill(['status' => User::STATUS_PENDING])->save();

    $answers = [];
    foreach (['tess@example.com', 'nobody@example.com', 'off@example.com', 'new@example.com', 'TESS@EXAMPLE.COM'] as $email) {
        $res = $this->post(route('password.email'), ['email' => $email])->assertRedirect()->assertSessionHasNoErrors();
        $answers[] = session('status');
    }
    expect(collect($answers)->unique())->toHaveCount(1);

    Mail::assertSent(SkNotice::class, 2);                       // tess twice (two spellings of the same address); nobody else
    Mail::assertSent(SkNotice::class, fn (SkNotice $m) => $m->hasTo('tess@example.com'));
    Mail::assertNotSent(SkNotice::class, fn (SkNotice $m) => $m->hasTo('nobody@example.com') || $m->hasTo('off@example.com') || $m->hasTo('new@example.com'));
});

it('lets the person choose a new password with the emailed link, once', function () {
    Mail::fake();
    $user = pwUser();
    $this->post(route('password.email'), ['email' => 'tess@example.com']);
    [$token, $email] = pwLinkFrom('tess@example.com');

    $this->get(route('password.reset', ['token' => $token, 'email' => $email]))->assertOk()->assertSee('Choose a new password');

    // rules for the new password, and a wrong token
    $this->post(route('password.update'), ['token' => $token, 'email' => $email, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
    $this->post(route('password.update'), ['token' => 'not-the-token', 'email' => $email, 'password' => 'Fresh-Pass-123', 'password_confirmation' => 'Fresh-Pass-123'])->assertSessionHasErrors('email');

    $this->post(route('password.update'), ['token' => $token, 'email' => $email, 'password' => 'Fresh-Pass-123', 'password_confirmation' => 'Fresh-Pass-123'])
        ->assertRedirect(route('login'))->assertSessionHas('status');

    // the new password works, the old one does not, and the link cannot be used again
    $this->post('/login', ['email' => 'tess@example.com', 'password' => 'OldPassw0rd1'])->assertSessionHasErrors();
    Auth::forgetGuards();
    $this->post('/login', ['email' => 'tess@example.com', 'password' => 'Fresh-Pass-123'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('logout'));                       // a signed-in browser is sent away from the guest pages
    Auth::forgetGuards();
    $this->post(route('password.update'), ['token' => $token, 'email' => $email, 'password' => 'Another-Pass-9', 'password_confirmation' => 'Another-Pass-9'])->assertSessionHasErrors('email');

    expect(ActivityLog::where('action', 'password_reset')->exists())->toBeTrue()
        ->and($user->fresh()->must_change_password)->toBeFalse();
});

it('does not honour a link after the account was switched off', function () {
    Mail::fake();
    $user = pwUser();
    $this->post(route('password.email'), ['email' => 'tess@example.com']);
    [$token, $email] = pwLinkFrom('tess@example.com');

    $user->forceFill(['is_active' => false])->save();

    $this->post(route('password.update'), ['token' => $token, 'email' => $email, 'password' => 'Fresh-Pass-123', 'password_confirmation' => 'Fresh-Pass-123'])->assertSessionHasErrors('email');
    expect(\Hash::check('OldPassw0rd1', $user->fresh()->password))->toBeTrue();
});

it('lets a link expire', function () {
    Mail::fake();
    pwUser();
    $this->post(route('password.email'), ['email' => 'tess@example.com']);
    [$token, $email] = pwLinkFrom('tess@example.com');

    $this->travel(61)->minutes();
    $this->post(route('password.update'), ['token' => $token, 'email' => $email, 'password' => 'Fresh-Pass-123', 'password_confirmation' => 'Fresh-Pass-123'])->assertSessionHasErrors('email');
});

it('sends at most three links per address per hour', function () {
    Mail::fake();
    pwUser();

    foreach (range(1, 5) as $i) {
        $this->post(route('password.email'), ['email' => 'tess@example.com'])->assertRedirect();
    }
    Mail::assertSent(SkNotice::class, 3);
});

it('limits how often the forgot-password form can be used from one place', function () {
    Mail::fake();
    foreach (range(1, 10) as $i) {
        $this->post(route('password.email'), ['email' => "nobody{$i}@example.com"])->assertRedirect();
    }
    $this->post(route('password.email'), ['email' => 'nobody11@example.com'])->assertStatus(429);
});

it('lets a reset link also clear a temporary password', function () {
    Mail::fake();
    $user = pwUser();
    Passwords::set($user, 'TempPass12', true);

    $this->post(route('password.email'), ['email' => 'tess@example.com']);
    [$token, $email] = pwLinkFrom('tess@example.com');
    $this->post(route('password.update'), ['token' => $token, 'email' => $email, 'password' => 'Fresh-Pass-123', 'password_confirmation' => 'Fresh-Pass-123'])->assertRedirect(route('login'));

    expect($user->fresh()->must_change_password)->toBeFalse();
});
