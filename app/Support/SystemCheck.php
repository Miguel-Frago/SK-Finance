<?php

namespace App\Support;

use App\Models\Admin;
use Closure;
use Illuminate\Support\Facades\Schema;

/**
 * The checks behind "php artisan sk:check": things that are easy to forget before other people use the system.
 * Each check gives OK, WARN (worth fixing) or FAIL (will break something), and says what to do.
 */
final class SystemCheck
{
    public const OK = 'OK';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';

    /** @param  Closure(string):mixed|null  $ini  how PHP settings are read (replaced in tests) */
    public function __construct(private ?Closure $ini = null, private ?string $root = null)
    {
        $this->ini ??= fn (string $key) => ini_get($key);
        $this->root ??= base_path();
    }

    /** @return array<int,array{name:string,status:string,detail:string}> */
    public function run(): array
    {
        return array_merge(
            $this->environment(),
            $this->php(),
            $this->folders(),
            $this->backups(),
            $this->project(),
        );
    }

    public static function failures(array $results): int
    {
        return count(array_filter($results, fn ($r) => $r['status'] === self::FAIL));
    }

    /** "64M", "2G", "512K" or plain bytes -> bytes. -1 / 0 mean "no limit". */
    public static function bytes(string|int|false|null $value): int
    {
        $v = trim((string) $value);
        if ($v === '' || $v === '-1' || $v === '0') {
            return PHP_INT_MAX;
        }
        $n = (float) $v;

        return (int) match (strtoupper(substr($v, -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }

    private function row(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }

    private function environment(): array
    {
        $out = [];
        $debug = (bool) config('app.debug');

        if (app()->isProduction() && $debug) {
            $out[] = $this->row('Debug mode', self::FAIL, 'APP_DEBUG is true on a live system: error pages show passwords and code to anyone. Set APP_DEBUG=false in .env.');
        } elseif ($debug) {
            $out[] = $this->row('Debug mode', self::WARN, 'APP_DEBUG=true is fine on your own computer. Set APP_DEBUG=false and APP_ENV=production before other people can reach the app.');
        } else {
            $out[] = $this->row('Debug mode', self::OK, 'off');
        }

        $tz = (string) config('app.timezone');
        $out[] = $tz === 'Asia/Manila'
            ? $this->row('Time zone', self::OK, $tz)
            : $this->row('Time zone', self::WARN, "{$tz}: times on screen and in reports expect Asia/Manila (APP_TIMEZONE in .env).");

        $mailer = (string) config('mail.default');
        $out[] = app()->isProduction() && in_array($mailer, ['log', 'array'], true)
            ? $this->row('Email', self::WARN, "MAIL_MAILER={$mailer}: secret codes and notices are only written to a log file, nobody receives them.")
            : $this->row('Email', self::OK, $mailer);

        return $out;
    }

    private function php(): array
    {
        $out = [];

        foreach (['fileinfo' => [self::FAIL, 'needed to recognise what an uploaded file really is'], 'mbstring' => [self::FAIL, 'needed for names and messages'], 'zip' => [self::WARN, 'needed by sk:backup']] as $ext => [$level, $why]) {
            $out[] = extension_loaded($ext)
                ? $this->row("PHP extension {$ext}", self::OK, 'loaded')
                : $this->row("PHP extension {$ext}", $level, "missing: {$why}. Turn it on in php.ini.");
        }

        // a 50 MB file needs PHP to accept 50 MB in one request
        $file = MessageFiles::maxBytes();
        $upload = self::bytes(($this->ini)('upload_max_filesize'));
        $post = self::bytes(($this->ini)('post_max_size'));
        $mb = fn (int $b) => $b === PHP_INT_MAX ? 'no limit' : round($b / 1048576, 1) . ' MB';

        if ($upload < $file || $post < $file + 1048576) {
            $out[] = $this->row('Upload limits', self::FAIL, sprintf(
                'Chat files can be up to %s, but PHP allows only %s per file and %s per request, so bigger files fail. In php.ini set upload_max_filesize = 64M and post_max_size = 64M (Laravel Herd: Herd > PHP settings).',
                $mb($file), $mb($upload), $mb($post),
            ));
        } else {
            $out[] = $this->row('Upload limits', self::OK, sprintf('a file of %s fits (PHP allows %s per file, %s per request)', $mb($file), $mb($upload), $mb($post)));
        }

        return $out;
    }

    private function folders(): array
    {
        $out = [];
        foreach (['storage/app/public', 'storage/app/private', 'storage/app/backups', 'storage/logs', 'public/uploads'] as $relative) {
            $path = $this->root . '/' . $relative;
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            $out[] = is_dir($path) && is_writable($path)
                ? $this->row("Folder {$relative}", self::OK, 'writable')
                : $this->row("Folder {$relative}", self::FAIL, 'cannot be written to: uploads, logs or backups will fail. Fix the folder permissions.');
        }

        $link = $this->root . '/public/storage';
        $out[] = is_link($link) || is_dir($link) || file_exists($link) || @scandir($link) !== false
            ? $this->row('Receipt pictures link', self::OK, 'public/storage exists')
            : $this->row('Receipt pictures link', self::WARN, 'public/storage is missing, so receipt pictures will not show. Run: php artisan storage:link');

        return $out;
    }

    private function backups(): array
    {
        $files = glob($this->root . '/storage/app/backups/sk-backup-*.zip') ?: [];
        if (! $files) {
            return [$this->row('Backups', self::WARN, 'No backup yet. Run: php artisan sk:backup (and keep the scheduler running so it happens every night).')];
        }

        rsort($files);
        $age = (time() - (int) filemtime($files[0])) / 3600;

        return [$age > 48
            ? $this->row('Backups', self::WARN, sprintf('The newest backup is %d hours old. Is the scheduler running (php artisan schedule:work)?', $age))
            : $this->row('Backups', self::OK, sprintf('%d kept, newest %d hour(s) old', count($files), $age))];
    }

    private function project(): array
    {
        $out = [];

        try {
            $adminCount = Schema::hasTable('admins') ? Admin::query()->count() : 0;
            $out[] = $adminCount > 0
                ? $this->row('Admin account', self::OK, 'exists')
                : $this->row('Admin account', self::WARN, 'There is no Admin yet. Run: php artisan db:seed --class=AdminSeeder (after setting SK_ADMIN_EMAIL and SK_ADMIN_PASSWORD in .env).');

            $migrator = app('migrator');
            $ran = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
            $pending = count(array_diff($files, $ran));
            $out[] = $pending === 0
                ? $this->row('Database updates', self::OK, 'all migrations are applied')
                : $this->row('Database updates', self::FAIL, "{$pending} migration(s) not applied yet. Run: php artisan migrate");
        } catch (\Throwable $e) {
            $out[] = $this->row('Database', self::FAIL, 'cannot be read: ' . $e->getMessage());
        }

        // a stray file with passwords in the project folder gets zipped, mailed or uploaded by accident
        $stray = [];
        foreach (glob($this->root . '/*') ?: [] as $path) {
            $name = basename($path);
            if (is_file($path) && preg_match('/\.(txt|md|csv|json|env|bak)$/i', $name) && ! in_array($name, ['README.md', 'SK-Finance-Network-Notes.md', 'composer.json', 'package.json'], true)) {
                $head = (string) @file_get_contents($path, false, null, 0, 4096);
                if (preg_match('/pass(word|wd)|secret|app[ _-]?pass/i', $head)) {
                    $stray[] = $name;
                }
            }
        }
        $out[] = $stray
            ? $this->row('Files with passwords', self::WARN, 'These files in the project folder mention passwords: ' . implode(', ', $stray) . '. Move them out of the project and change those passwords if the folder was ever shared.')
            : $this->row('Files with passwords', self::OK, 'none found in the project folder');

        return $out;
    }
}
