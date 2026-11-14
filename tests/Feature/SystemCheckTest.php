<?php

use App\Support\SystemCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| php artisan sk:check
|--------------------------------------------------------------------------
*/

function checkRow(array $results, string $name): array
{
    return collect($results)->firstWhere('name', $name) ?? [];
}

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/sk-check-test-' . bin2hex(random_bytes(4));
    File::makeDirectory($this->root, 0775, true);
});
afterEach(fn () => File::deleteDirectory($this->root));

it('reads PHP sizes the way php.ini writes them', function () {
    expect(SystemCheck::bytes('64M'))->toBe(64 * 1024 * 1024)
        ->and(SystemCheck::bytes('2G'))->toBe(2 * 1024 ** 3)
        ->and(SystemCheck::bytes('512K'))->toBe(512 * 1024)
        ->and(SystemCheck::bytes('8388608'))->toBe(8388608)
        ->and(SystemCheck::bytes('-1'))->toBe(PHP_INT_MAX);
});

it('fails when PHP cannot take a 50 MB file, and says how to fix it', function () {
    $check = new SystemCheck(fn ($key) => ['upload_max_filesize' => '2M', 'post_max_size' => '8M'][$key], $this->root);
    $row = checkRow($check->run(), 'Upload limits');

    expect($row['status'])->toBe(SystemCheck::FAIL)
        ->and($row['detail'])->toContain('50 MB')->toContain('2 MB')->toContain('upload_max_filesize = 64M');
    expect(SystemCheck::failures($check->run()))->toBeGreaterThanOrEqual(1);
});

it('passes when PHP allows 50 MB, but not when the whole request is smaller than the file', function () {
    $fits = new SystemCheck(fn ($key) => ['upload_max_filesize' => '64M', 'post_max_size' => '70M'][$key], $this->root);
    expect(checkRow($fits->run(), 'Upload limits')['status'])->toBe(SystemCheck::OK);

    $postTooSmall = new SystemCheck(fn ($key) => ['upload_max_filesize' => '64M', 'post_max_size' => '50M'][$key], $this->root);
    expect(checkRow($postTooSmall->run(), 'Upload limits')['status'])->toBe(SystemCheck::FAIL);

    $unlimited = new SystemCheck(fn ($key) => '-1', $this->root);
    expect(checkRow($unlimited->run(), 'Upload limits')['status'])->toBe(SystemCheck::OK);
});

it('follows the configured file size', function () {
    config(['messaging.max_file_mb' => 100]);
    $check = new SystemCheck(fn ($key) => ['upload_max_filesize' => '64M', 'post_max_size' => '70M'][$key], $this->root);

    expect(checkRow($check->run(), 'Upload limits')['status'])->toBe(SystemCheck::FAIL);
});

it('reminds about backups: none, fresh, and old', function () {
    $ini = fn ($key) => '64M';

    expect(checkRow((new SystemCheck($ini, $this->root))->run(), 'Backups')['status'])->toBe(SystemCheck::WARN);

    File::makeDirectory($this->root . '/storage/app/backups', 0775, true, true);
    $zip = $this->root . '/storage/app/backups/sk-backup-20260101-000000.zip';
    file_put_contents($zip, 'x');
    expect(checkRow((new SystemCheck($ini, $this->root))->run(), 'Backups')['status'])->toBe(SystemCheck::OK);

    touch($zip, time() - 5 * 86400);
    $row = checkRow((new SystemCheck($ini, $this->root))->run(), 'Backups');
    expect($row['status'])->toBe(SystemCheck::WARN)->and($row['detail'])->toContain('scheduler');
});

it('warns about a file with passwords lying in the project folder, without showing what is in it', function () {
    file_put_contents($this->root . '/Email notes.txt', "gmail: someone@example.com\npassword: hunter2-secret");
    file_put_contents($this->root . '/shopping.txt', 'milk and eggs');
    file_put_contents($this->root . '/README.md', 'password reset instructions');           // known project documents are ignored

    $row = checkRow((new SystemCheck(fn ($k) => '64M', $this->root))->run(), 'Files with passwords');

    expect($row['status'])->toBe(SystemCheck::WARN)
        ->and($row['detail'])->toContain('Email notes.txt')
        ->and($row['detail'])->not->toContain('shopping.txt')->not->toContain('README.md')
        ->and($row['detail'])->not->toContain('hunter2');
});

it('checks that the folders can be written to, and makes the missing ones', function () {
    $results = (new SystemCheck(fn ($k) => '64M', $this->root))->run();

    expect(checkRow($results, 'Folder storage/app/private')['status'])->toBe(SystemCheck::OK)
        ->and(is_dir($this->root . '/storage/app/backups'))->toBeTrue()
        ->and(checkRow($results, 'Receipt pictures link')['status'])->toBe(SystemCheck::WARN);   // no public/storage link in the sandbox
});

it('notices a missing Admin and an unapplied database update', function () {
    $results = (new SystemCheck(fn ($k) => '64M', $this->root))->run();

    expect(checkRow($results, 'Admin account')['status'])->toBe(SystemCheck::WARN)
        ->and(checkRow($results, 'Database updates')['status'])->toBe(SystemCheck::OK);
});

it('runs as a command and prints the table', function () {
    $this->artisan('sk:check')
        ->expectsOutputToContain('Upload limits')
        ->expectsOutputToContain('Debug mode')
        ->expectsOutputToContain('Backups');
});

it('shows the Admin the system check, and nobody else', function () {
    \App\Models\Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);
    $this->post('/admin/login', ['email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    $this->get(route('admin.system'))->assertOk()
        ->assertSee('System check')
        ->assertSee('Upload limits')
        ->assertSee('upload_max_filesize')
        ->assertSee('never accounts, money or messages');

    // a signed-in barangay account cannot open it
    \Illuminate\Support\Facades\Auth::guard('admin')->logout();
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user)->get('/admin/system')->assertRedirect();
});

it('does not open to a visitor who is not signed in', function () {
    $this->get('/admin/system')->assertRedirect();
});
