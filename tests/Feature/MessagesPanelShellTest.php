<?php

use App\Models\Admin;
use App\Models\Barangay;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4A: the right-side Messages panel (shell), the movable SK Guide, the equal top bar, the funds note
|--------------------------------------------------------------------------
*/

function shellUser(string $role): User
{
    $user = User::factory()->create(['name' => ucfirst($role) . ' Shell', 'email' => "{$role}-shell@example.com"]);
    $user->forceFill(['role' => $role, 'barangay_id' => Barangay::defaultId()])->save();

    return $user;
}

it('shows the Messages box and the message icon (no edge tab) to every barangay role', function (string $role) {
    $this->actingAs(shellUser($role));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('id="msgPanel"', false)
        ->assertSee('data-msg-toggle', false)
        ->assertSee('data-msg-badge', false)
        ->assertSee('js/messages-panel.js', false)
        ->assertDontSee('msg-edge', false)
        ->assertDontSee('guide-drag.js', false);
})->with(['treasurer', 'chairman', 'secretary', 'kagawad']);

it('shows the same box and message icon on the Admin pages', function () {
    Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);
    $this->post('/admin/login', ['email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('id="msgPanel"', false)
        ->assertSee('data-msg-toggle', false)
        ->assertSee('js/messages-panel.js', false)
        ->assertDontSee('msg-edge', false)
        ->assertDontSee('guide-drag.js', false);
});

it('keeps the SK Guide button fixed (not movable) in both layouts', function () {
    $this->actingAs(shellUser('treasurer'));
    $this->get(route('dashboard'))
        ->assertSee('sk-guide-fab', false)
        ->assertDontSee('data-guide-reset', false);

    Auth::forgetGuards();
    Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);
    $this->post('/admin/login', ['email' => 'fed@example.com', 'password' => 'Passw0rd123']);
    $this->get(route('admin.dashboard'))
        ->assertSee('sk-guide-fab', false)
        ->assertDontSee('data-guide-reset', false);
});

it('puts the message icon after the Budget switch in the top bar', function () {
    $this->actingAs(shellUser('treasurer'));

    Budget::create([
        'year' => 2026, 'type' => 'regular', 'total_budget' => 60000,
        'opening_bank_balance' => 50000, 'opening_cash_balance' => 10000,
    ]);

    $html = $this->get(route('dashboard'))->assertOk()->getContent();

    expect(strpos($html, 'class="budget-switcher"'))->toBeInt()
        ->and(strpos($html, 'data-msg-toggle'))->toBeInt()
        ->and(strpos($html, 'class="budget-switcher"'))->toBeLessThan(strpos($html, 'data-msg-toggle'));
});

it('ships the scripts and the fixed top-bar height', function () {
    expect(public_path('js/messages-panel.js'))->toBeFile();

    $css = file_get_contents(public_path('css/custom.css'));

    // one height for every role (the Budget switch only exists for barangay users)
    expect($css)->toContain('height: 4.5rem')
        ->and($css)->toContain('.msg-panel')
        ->and($css)->not->toContain('guide-left');
});

it('says the records balance when Bank + Cash equals Budget left', function () {
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));

    Budget::create([
        'year' => 2026, 'type' => 'regular', 'total_budget' => 60000,
        'opening_bank_balance' => 50000, 'opening_cash_balance' => 10000,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Bank + Cash equals Budget left')
        ->assertSee('funds-check is-ok', false);
});

it('gives a plain note, not an error, when funds on hand are below Budget left', function () {
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));

    // ₱100,000 budget but only ₱60,000 recorded as received
    Budget::create([
        'year' => 2026, 'type' => 'regular', 'total_budget' => 100000,
        'opening_bank_balance' => 50000, 'opening_cash_balance' => 10000,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('below Budget left')
        ->assertSee('funds-check is-note', false)
        ->assertDontSee('funds-check is-ok', false);
});
