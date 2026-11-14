<?php

use App\Mail\SkNotice;
use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Barangay;
use App\Models\User;
use App\Support\CsvExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function upAdmin($test): Admin
{
    $admin = Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    $test->post('/admin/login', ['email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    return $admin;
}

function upRequest(array $over = []): AccessRequest
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

function upTreasurer(string $name = 'Maria Treasurer', string $email = 'maria@example.com'): User
{
    $user = User::factory()->create(['name' => $name, 'email' => $email]);
    $user->forceFill(['role' => 'treasurer', 'barangay_id' => Barangay::defaultId()])->save();

    return $user;
}

/* ------------------------------------------------------------
 | Messages disappear by themselves (like on the Treasurer pages)
 * ------------------------------------------------------------ */

it('shows a success message as a pop-up that closes after 3 seconds', function () {
    Mail::fake();
    upAdmin($this);
    $request = upRequest();

    $this->followingRedirects()
        ->post('/admin/requests/' . $request->id . '/deny')
        ->assertOk()
        ->assertSee('toast toast-success', false)
        ->assertSee('data-timeout="3000"', false);
});

it('shows a warning message as a pop-up that closes after 6 seconds', function () {
    upAdmin($this);

    // the test mailer is "array", so the test email must say that nothing really leaves the server
    $this->followingRedirects()
        ->post('/admin/profile/test-email')
        ->assertOk()
        ->assertSee('toast toast-warning', false)
        ->assertSee('data-timeout="6000"', false)
        ->assertSee('Emails are not being sent');
});

it('shows errors as a pop-up that closes after 8 seconds', function () {
    upAdmin($this);

    $this->from('/admin/profile')
        ->followingRedirects()
        ->put('/admin/profile/password', ['current_password' => 'wrong', 'password' => 'NewPassw0rd1', 'password_confirmation' => 'NewPassw0rd1'])
        ->assertSee('toast toast-error', false)
        ->assertSee('data-timeout="8000"', false);
});

it('keeps the one-time secret code on the page instead of making it disappear', function () {
    upAdmin($this);

    $this->withSession(['code_fallback' => 'K7M2P-9QX4R'])
        ->get('/admin')
        ->assertOk()
        ->assertSee('K7M2P-9QX4R')
        ->assertSee('code-box', false);
});

/* ------------------------------------------------------------
 | Email warning and test button
 * ------------------------------------------------------------ */

it('warns on the dashboard while emails are not being sent', function () {
    upAdmin($this);

    $this->get('/admin')->assertOk()->assertSee('Emails are not being sent');

    config(['mail.default' => 'smtp']);

    $this->get('/admin')->assertOk()->assertDontSee('Emails are not being sent');
});

it('sends the test email to the Admin when mail is really set up', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    upAdmin($this);

    $this->followingRedirects()
        ->post('/admin/profile/test-email')
        ->assertSee('Test email sent to fed@example.com');

    Mail::assertSent(SkNotice::class, fn ($mail) => $mail->hasTo('fed@example.com'));
});

it('does not allow the test email without signing in', function () {
    $this->post('/admin/profile/test-email')->assertRedirect(route('admin.login'));
});

/* ------------------------------------------------------------
 | Search and barangay filter
 * ------------------------------------------------------------ */

it('searches requests by name or email and by barangay', function () {
    upAdmin($this);

    upRequest();
    upRequest(['first_name' => 'Maria', 'last_name' => 'Reyes', 'middle_name' => null, 'email' => 'maria.reyes@example.com', 'barangay_id' => Barangay::where('id', '!=', Barangay::defaultId())->value('id')]);

    $this->get('/admin/requests?q=Reyes')->assertOk()->assertSee('Reyes, Maria')->assertDontSee('Dela Cruz');
    $this->get('/admin/requests?q=juan@example.com')->assertOk()->assertSee('Dela Cruz')->assertDontSee('Reyes, Maria');
    $this->get('/admin/requests?barangay_id=' . Barangay::defaultId())->assertOk()->assertSee('Dela Cruz')->assertDontSee('Reyes, Maria');
    $this->get('/admin/requests?q=nobody-by-this-name')->assertOk()->assertSee('No requests here');
});

it('searches accounts by name or email and by barangay', function () {
    upAdmin($this);

    $maria = upTreasurer();
    $other = User::factory()->create(['name' => 'Pedro Chairman', 'email' => 'pedro@example.com']);
    $other->forceFill(['role' => 'chairman', 'barangay_id' => Barangay::where('id', '!=', Barangay::defaultId())->value('id')])->save();

    $this->get('/admin/accounts?status=all&q=Pedro')->assertOk()->assertSee('Pedro Chairman')->assertDontSee('Maria Treasurer');
    $this->get('/admin/accounts?status=all&barangay_id=' . Barangay::defaultId())->assertOk()->assertSee('Maria Treasurer')->assertDontSee('Pedro Chairman');
});

/* ------------------------------------------------------------
 | Needs attention
 * ------------------------------------------------------------ */

it('lists a secret code that is about to end, with a button to send a new one', function () {
    upAdmin($this);

    upRequest(['status' => 'approved', 'code_expires_at' => now()->addDay()]);

    $this->get('/admin')->assertOk()->assertSee('Secret codes running out')->assertSee('Resend code');
});

it('does not list a code that still has days left', function () {
    upAdmin($this);

    upRequest(['status' => 'approved', 'code_expires_at' => now()->addDays(6)]);

    $this->get('/admin')->assertOk()->assertDontSee('Resend code')->assertSee('No code ends in the next 2 days');
});

it('lists the oldest waiting requests on the dashboard', function () {
    upAdmin($this);

    upRequest();

    $this->get('/admin')->assertOk()->assertSee('Oldest requests waiting')->assertSee('Dela Cruz, Juan Santos');
});

/* ------------------------------------------------------------
 | Barangay coverage
 * ------------------------------------------------------------ */

it('shows how many barangays have a Treasurer and can show only the vacant ones', function () {
    upAdmin($this);
    upTreasurer();

    $this->get('/admin/barangays')->assertOk()->assertSee('have an active Treasurer')->assertSee('Maria Treasurer');
    $this->get('/admin/barangays?show=vacant')->assertOk()->assertDontSee('Maria Treasurer');
    $this->get('/admin/barangays?show=active')->assertOk()->assertSee('Maria Treasurer');
});

it('downloads the barangay list as CSV', function () {
    upAdmin($this);
    upTreasurer();

    $response = $this->get('/admin/barangays?export=csv');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain('Barangay,District,"SK Treasurer"')->toContain('Maria Treasurer');
});

/* ------------------------------------------------------------
 | Last sign-in
 * ------------------------------------------------------------ */

it('shows when an account last signed in, from the activity log', function () {
    upAdmin($this);
    $maria = upTreasurer();
    $never = User::factory()->create(['name' => 'Quiet Person', 'email' => 'quiet@example.com']);
    $never->forceFill(['role' => 'chairman', 'barangay_id' => Barangay::defaultId()])->save();

    ActivityLog::create([
        'barangay_id' => $maria->barangay_id, 'user_id' => $maria->id, 'user_name' => $maria->name, 'user_role' => 'treasurer',
        'action' => 'login', 'description' => 'Signed in', 'created_at' => now()->subDays(3),
    ]);

    $this->get('/admin/accounts?status=active')->assertOk()->assertSee('Last sign-in')->assertSee('3 days ago')->assertSee('Never');
    $this->get('/admin/accounts/' . $maria->id)->assertOk()->assertSee('Last sign-in')->assertSee('3 days ago');
});

/* ------------------------------------------------------------
 | Activity log: CSV and print
 * ------------------------------------------------------------ */

it('downloads the activity log as CSV with the same filters', function () {
    upAdmin($this);

    ActivityLog::create(['action' => 'created', 'description' => 'Marker for cantoria', 'user_name' => 'Maria', 'created_at' => now()]);
    ActivityLog::create(['action' => 'created', 'description' => 'Marker for suyo', 'user_name' => 'Pedro', 'created_at' => now()]);

    $response = $this->get('/admin/activity?export=csv&q=cantoria');

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('When,Who,Role')->toContain('Marker for cantoria')->not->toContain('Marker for suyo');
});

it('offers a print button on the activity log and the barangay list', function () {
    upAdmin($this);

    $this->get('/admin/activity')->assertOk()->assertSee('window.print()', false);
    $this->get('/admin/barangays')->assertOk()->assertSee('window.print()', false);
});

it('does not let a spreadsheet run a formula typed into a name', function () {
    expect(CsvExport::safe('=SUM(A1:A9)'))->toBe("'=SUM(A1:A9)")
        ->and(CsvExport::safe('+63 917'))->toBe("'+63 917")
        ->and(CsvExport::safe('Maria Reyes'))->toBe('Maria Reyes');
});

/* ------------------------------------------------------------
 | Admin theme
 * ------------------------------------------------------------ */

it('shows the Theme button and saves the Admin colour theme', function () {
    $admin = upAdmin($this);

    $this->get('/admin')->assertOk()->assertSee('id="themeBtn"', false)->assertSee('id="themeDialog"', false);

    $this->putJson('/admin/theme', ['sidebar' => '#14342B', 'accent' => '#2f8f5b', 'preset' => 'forest'])
        ->assertOk()->assertJson(['ok' => true]);

    expect($admin->fresh()->theme)->toBe(['sidebar' => '#14342b', 'accent' => '#2f8f5b', 'preset' => 'forest']);

    // the saved theme is applied the next time a page opens
    $this->get('/admin')->assertSee('#14342b', false);
});

it('refuses a theme that is not a hex colour', function () {
    upAdmin($this);

    $this->putJson('/admin/theme', ['sidebar' => 'red', 'accent' => '#2f8f5b'])->assertStatus(422);
});

it('keeps the theme route for signed-in Admins only', function () {
    $this->putJson('/admin/theme', ['sidebar' => '#14342b', 'accent' => '#2f8f5b'])->assertStatus(401);
});

/* ------------------------------------------------------------
 | Live updates: the page notices changes by itself
 * ------------------------------------------------------------ */

it('loads the live script on the Admin pages', function () {
    upAdmin($this);

    $this->get('/admin')->assertOk()->assertSee('js/live.js', false)->assertSee('data-live-url', false);
});

it('answers the live check with counts and a fingerprint that changes when something happens', function () {
    upAdmin($this);

    $before = $this->getJson('/admin/live')->assertOk()->assertJsonStructure(['stamp', 'requests', 'accounts'])->json();

    expect($before['requests'])->toBe(0);

    upRequest();

    $after = $this->getJson('/admin/live')->assertOk()->json();

    expect($after['requests'])->toBe(1)
        ->and($after['stamp'])->not->toBe($before['stamp']);

    // nothing happened in between: the fingerprint stays the same
    expect($this->getJson('/admin/live')->json('stamp'))->toBe($after['stamp']);
});

it('does not send names or emails through the live check', function () {
    upAdmin($this);
    upRequest();

    $this->getJson('/admin/live')->assertOk()->assertDontSee('Dela Cruz')->assertDontSee('juan@example.com');
});

it('keeps the live check for signed-in Admins only', function () {
    $this->getJson('/admin/live')->assertStatus(401);
});
