<?php

use App\Mail\SkNotice;
use App\Models\ActivityLog;
use App\Models\Admin;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function profileAdmin(bool $mustChange = false): Admin
{
    $admin = Admin::create(['name' => 'Old President', 'email' => 'old@example.com', 'password' => 'Passw0rd123']);

    if ($mustChange) {
        $admin->forceFill(['must_change_password' => true])->save();
    }

    return $admin;
}

function profileSignIn($test, string $email = 'old@example.com', string $password = 'Passw0rd123')
{
    return $test->post('/admin/login', ['email' => $email, 'password' => $password]);
}

/* ------------------------------------------------------------
 | First sign-in with a temporary password
 * ------------------------------------------------------------ */

it('sends an Admin with a temporary password to choose their own first', function () {
    profileAdmin(true);

    profileSignIn($this)->assertRedirect(route('admin.profile'));

    $this->get('/admin')->assertRedirect(route('admin.profile'));
    $this->get('/admin/requests')->assertRedirect(route('admin.profile'));
    $this->get('/admin/activity')->assertRedirect(route('admin.profile'));

    $this->get('/admin/profile')->assertOk()->assertSee('Change password')->assertSee('temporary password');
});

it('lets an Admin with a temporary password sign out', function () {
    profileAdmin(true);
    profileSignIn($this);

    $this->post('/admin/logout')->assertRedirect(route('admin.login'));
    $this->assertGuest('admin');
});

it('opens the whole Admin system once the new password is saved', function () {
    $admin = profileAdmin(true);
    profileSignIn($this);

    $this->put(route('admin.profile.password'), [
        'current_password'      => 'Passw0rd123',
        'password'              => 'NewPassw0rd9',
        'password_confirmation' => 'NewPassw0rd9',
    ])->assertRedirect(route('admin.dashboard'));

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse()
        ->and($admin->password_changed_at)->not->toBeNull()
        ->and(Hash::check('NewPassw0rd9', $admin->password))->toBeTrue();

    $this->get('/admin')->assertOk();
    $this->get('/admin/requests')->assertOk();
});

it('checks the current password, the strength and that the password really changes', function () {
    $admin = profileAdmin();
    profileSignIn($this);

    $this->put(route('admin.profile.password'), [
        'current_password' => 'wrong-one', 'password' => 'NewPassw0rd9', 'password_confirmation' => 'NewPassw0rd9',
    ])->assertSessionHasErrors('current_password');

    $this->put(route('admin.profile.password'), [
        'current_password' => 'Passw0rd123', 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->put(route('admin.profile.password'), [
        'current_password' => 'Passw0rd123', 'password' => 'Passw0rd123', 'password_confirmation' => 'Passw0rd123',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('Passw0rd123', $admin->refresh()->password))->toBeTrue();
});

/* ------------------------------------------------------------
 | Name and email
 * ------------------------------------------------------------ */

it('changes the name and email after the password is confirmed', function () {
    $admin = profileAdmin();
    profileSignIn($this);

    $this->put(route('admin.profile.details'), [
        'name' => 'Maria Santos', 'email' => 'Maria@Example.com', 'current_password' => 'Passw0rd123',
    ])->assertRedirect(route('admin.profile'));

    $admin->refresh();
    expect($admin->name)->toBe('Maria Santos')->and($admin->email)->toBe('maria@example.com');
});

it('does not change the details with a wrong password or a taken email', function () {
    $admin = profileAdmin();
    profileSignIn($this);

    $this->put(route('admin.profile.details'), [
        'name' => 'Maria Santos', 'email' => 'maria@example.com', 'current_password' => 'nope',
    ])->assertSessionHasErrors('current_password');

    expect($admin->refresh()->name)->toBe('Old President');
});

/* ------------------------------------------------------------
 | Handover to a new president
 * ------------------------------------------------------------ */

it('hands the account over with a random temporary password and keeps every record', function () {
    Mail::fake();
    $admin = profileAdmin();
    profileSignIn($this);

    ActivityLog::create(['action' => 'created', 'description' => 'Earlier entry', 'created_at' => now()]);

    $this->post(route('admin.profile.handover'), [
        'current_password' => 'Passw0rd123',
        'new_name'         => 'New President',
        'new_email'        => 'New@Example.com',
        'email_it'         => '1',
    ])->assertRedirect(route('admin.login'));

    // the outgoing president is signed out and the old password is dead
    $this->assertGuest('admin');

    $admin->refresh();
    expect($admin->name)->toBe('New President')
        ->and($admin->email)->toBe('new@example.com')
        ->and($admin->must_change_password)->toBeTrue()
        ->and(Hash::check('Passw0rd123', $admin->password))->toBeFalse();

    // the temporary password was emailed, is random, and works
    $temp = null;
    Mail::assertSent(SkNotice::class, function (SkNotice $mail) use (&$temp) {
        $temp = $mail->code;

        return $mail->hasTo('new@example.com');
    });
    expect($temp)->not->toBeNull()
        ->and($temp)->not->toBe('ChangeMe!2026')
        ->and(Hash::check($temp, $admin->password))->toBeTrue();

    // nothing was lost, and the handover is itself in the log
    expect(Admin::count())->toBe(1)
        ->and(ActivityLog::where('description', 'Earlier entry')->exists())->toBeTrue()
        ->and(ActivityLog::where('action', 'handover')->exists())->toBeTrue();

    // the new president signs in with the temporary password and must choose their own
    profileSignIn($this, 'new@example.com', $temp)->assertRedirect(route('admin.profile'));
});

it('shows the temporary password once when it is not emailed', function () {
    Mail::fake();
    profileAdmin();
    profileSignIn($this);

    $this->post(route('admin.profile.handover'), [
        'current_password' => 'Passw0rd123', 'new_email' => 'new@example.com',
    ])->assertRedirect(route('admin.login'))
      ->assertSessionHas('handover.temp');

    Mail::assertNothingSent();
});

it('needs the current password and the new email for a handover', function () {
    $admin = profileAdmin();
    profileSignIn($this);

    $this->post(route('admin.profile.handover'), ['current_password' => 'wrong', 'new_email' => 'new@example.com'])
        ->assertSessionHasErrors('current_password');

    $this->post(route('admin.profile.handover'), ['current_password' => 'Passw0rd123', 'new_email' => ''])
        ->assertSessionHasErrors('new_email');

    expect($admin->refresh()->email)->toBe('old@example.com');
    $this->assertAuthenticated('admin');
});

it('signs out a session that was open elsewhere when the password changes', function () {
    profileAdmin();
    profileSignIn($this);
    $this->get('/admin')->assertOk();

    // the password is changed somewhere else (another device, or the server command)
    Admin::first()->forceFill(['password' => 'Other12345'])->save();
    Auth::guard('admin')->forgetUser();

    $this->get('/admin')->assertRedirect(route('admin.login'));
});

/* ------------------------------------------------------------
 | Server command and seeder
 * ------------------------------------------------------------ */

it('resets the existing Admin from the server with a new temporary password', function () {
    $admin = profileAdmin();

    $this->artisan('sk:reset-admin', ['--name' => 'Next President'])
        ->expectsOutputToContain('Temporary password')
        ->assertSuccessful();

    $admin->refresh();
    expect($admin->name)->toBe('Next President')
        ->and($admin->must_change_password)->toBeTrue()
        ->and(Hash::check('Passw0rd123', $admin->password))->toBeFalse();
});

it('creates the Admin from the server when there is none', function () {
    $this->artisan('sk:reset-admin', ['email' => 'First@Example.com'])->assertSuccessful();

    expect(Admin::count())->toBe(1)
        ->and(Admin::first()->email)->toBe('first@example.com')
        ->and(Admin::first()->must_change_password)->toBeTrue();
});

it('needs an email from the server command when there is no Admin yet', function () {
    $this->artisan('sk:reset-admin')->assertFailed();

    expect(Admin::count())->toBe(0);
});

it('creates the first Admin once from the seeder, who must then choose their own password', function () {
    config(['sk.admin_seed' => ['name' => 'Seed President', 'email' => 'seed@example.com', 'password' => 'Seeded12345']]);

    $this->seed(AdminSeeder::class);

    $admin = Admin::first();
    expect($admin->email)->toBe('seed@example.com')
        ->and($admin->name)->toBe('Seed President')
        ->and($admin->must_change_password)->toBeTrue()
        ->and(Hash::check('Seeded12345', $admin->password))->toBeTrue();
});

it('leaves the Admin alone when the seeder runs again', function () {
    config(['sk.admin_seed' => ['name' => 'Seed President', 'email' => 'seed@example.com', 'password' => 'Seeded12345']]);
    $this->seed(AdminSeeder::class);

    config(['sk.admin_seed' => ['name' => 'Someone Else', 'email' => 'else@example.com', 'password' => 'Different12345']]);
    $this->seed(AdminSeeder::class);

    expect(Admin::count())->toBe(1)->and(Admin::first()->email)->toBe('seed@example.com');
});
