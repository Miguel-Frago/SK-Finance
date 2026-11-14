<?php

use App\Models\Admin;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| php artisan sk:backup
|--------------------------------------------------------------------------
| No RefreshDatabase here on purpose: SQLite cannot make its consistent copy (VACUUM INTO) from inside a transaction,
| and the real command never runs inside one. The test builds its own in-memory database with the real migrations.
*/

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/sk-backup-test-' . bin2hex(random_bytes(4));
    File::makeDirectory($this->sandbox . '/storage/app/public/attachments', 0775, true);
    File::makeDirectory($this->sandbox . '/storage/app/private/messages/b1/u2', 0775, true);
    File::makeDirectory($this->sandbox . '/public/uploads/profile', 0775, true);

    $this->originalStorage = app()->storagePath();
    $this->originalPublic = app()->publicPath();
    app()->useStoragePath($this->sandbox . '/storage');
    app()->usePublicPath($this->sandbox . '/public');

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});

afterEach(function () {
    app()->useStoragePath($this->originalStorage);
    app()->usePublicPath($this->originalPublic);
    File::deleteDirectory($this->sandbox);
});

function backupZips(): array
{
    return glob(storage_path('app/backups/sk-backup-*.zip')) ?: [];
}

it('puts the database and every uploaded file in one zip, and leaves the passwords out', function () {
    Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    file_put_contents(storage_path('app/public/attachments/receipt.pdf'), '%PDF receipt');
    file_put_contents(storage_path('app/private/messages/b1/u2/01abc.png'), 'png bytes');
    file_put_contents(public_path('uploads/profile/me.jpg'), 'jpg bytes');
    file_put_contents(base_path('.env.backup-test-marker'), 'must never be in a backup');   // not in any backed-up folder
    file_put_contents(storage_path('app/public/.gitignore'), "*\n");                            // housekeeping files are skipped

    $this->artisan('sk:backup')->assertSuccessful();
    @unlink(base_path('.env.backup-test-marker'));

    $zips = backupZips();
    expect($zips)->toHaveCount(1);

    $zip = new ZipArchive();
    expect($zip->open($zips[0]))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    expect($names)->toContain('database.sqlite', 'MANIFEST.txt', 'files/receipts-and-public/attachments/receipt.pdf',
        'files/chat-files/b1/u2/01abc.png', 'files/profile-photos/profile/me.jpg')
        ->and(collect($names)->contains(fn ($n) => str_contains($n, '.env')))->toBeFalse()
        ->and(collect($names)->contains(fn ($n) => str_contains($n, '.gitignore')))->toBeFalse()
        ->and($zip->getFromName('files/chat-files/b1/u2/01abc.png'))->toBe('png bytes');

    $manifest = $zip->getFromName('MANIFEST.txt');
    expect($manifest)->toContain('sha256')->toContain('NOT included: .env')->toContain('chat-files');

    // the database inside is a real, readable copy with the data in it
    $copy = tempnam(sys_get_temp_dir(), 'sk-db');
    file_put_contents($copy, $zip->getFromName('database.sqlite'));
    $zip->close();
    $pdo = new PDO('sqlite:' . $copy);
    expect((int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn())->toBe(1)
        ->and((string) $pdo->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok')
        ->and((int) $pdo->query('SELECT COUNT(*) FROM barangays')->fetchColumn())->toBeGreaterThan(30);
    $pdo = null;
    @unlink($copy);

    // the temporary database copy does not stay behind
    expect(glob(storage_path('app/backups/.snapshot-*')))->toBe([]);
});

it('keeps only the newest backups', function () {
    $dir = storage_path('app/backups');
    File::makeDirectory($dir, 0775, true);
    foreach (['20250101-000000', '20250102-000000', '20250103-000000', '20250104-000000'] as $stamp) {
        file_put_contents("{$dir}/sk-backup-{$stamp}.zip", 'old');
    }

    $this->artisan('sk:backup', ['--keep' => 2])->assertSuccessful();

    $left = array_map('basename', backupZips());
    rsort($left);
    expect($left)->toHaveCount(2)->and($left[1])->toBe('sk-backup-20250104-000000.zip');     // the new one + the newest old one
});

it('lists the backups that exist, or says there are none', function () {
    $this->artisan('sk:backup', ['--list' => true])->expectsOutputToContain('No backup yet')->assertSuccessful();

    $this->artisan('sk:backup')->assertSuccessful();
    $this->artisan('sk:backup', ['--list' => true])->expectsOutputToContain('sk-backup-')->assertSuccessful();
});

it('does not leave a half-made zip behind when something goes wrong', function () {
    // an unsupported database driver stops the backup before anything is written
    config(['database.default' => 'bogus', 'database.connections.bogus' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/x.sqlite']]);

    $this->artisan('sk:backup')->assertFailed();
    expect(backupZips())->toBe([]);
});
