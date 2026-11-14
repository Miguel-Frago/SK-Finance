<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * php artisan sk:backup            makes one zip: a consistent copy of the database + every uploaded file
 * php artisan sk:backup --list     shows the backups that exist
 *
 * The zip is kept on the server (storage/app/backups), NOT offered for download in the app: it contains every chat, and
 * the Admin is promised that chats are private to the people in them. The file ".env" (passwords) is never put in it.
 * Copy the zip to another computer or drive regularly: a backup on the same disk does not survive that disk failing.
 */
class BackupSystem extends Command
{
    protected $signature = 'sk:backup {--keep= : How many backups to keep (default 14, or SK_BACKUP_KEEP in .env)} {--list : Only show the backups that exist}';

    protected $description = 'Back up the database and all uploaded files into one zip (kept in storage/app/backups)';

    /** Folders that hold uploaded files, and the name each gets inside the zip. */
    protected function folders(): array
    {
        return [
            'files/receipts-and-public' => storage_path('app/public'),          // receipts of expenses
            'files/chat-files'          => storage_path('app/private/messages'), // files sent in chats
            'files/profile-photos'      => public_path('uploads'),              // profile photos
        ];
    }

    public function handle(): int
    {
        $dir = storage_path('app/backups');

        if ($this->option('list')) {
            return $this->showList($dir);
        }

        if (! class_exists(ZipArchive::class)) {
            $this->error('PHP needs its "zip" extension to make a backup. Turn it on in php.ini (extension=zip) and try again.');

            return self::FAILURE;
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create the folder {$dir}.");

            return self::FAILURE;
        }

        $stamp = now()->format('Ymd-His');
        $zipPath = "{$dir}/sk-backup-{$stamp}.zip";
        $snapshot = "{$dir}/.snapshot-{$stamp}-" . bin2hex(random_bytes(4));
        $dbName = null;

        try {
            [$dbName, $dbNote] = $this->snapshotDatabase($snapshot);
            $this->line("Database: {$dbNote}");

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Cannot create the zip file.');
            }

            $zip->addFile($snapshot, $dbName);
            $counts = [];
            $bytes = 0;
            foreach ($this->folders() as $inside => $source) {
                [$n, $b] = $this->addFolder($zip, $source, $inside);
                $counts[$inside] = $n;
                $bytes += $b;
                $this->line(sprintf('Files: %-28s %d file(s)', $inside, $n));
            }

            $zip->addFromString('MANIFEST.txt', $this->manifest($dbName, $snapshot, $counts, $bytes));

            if (! $zip->close()) {
                throw new \RuntimeException('Writing the zip failed (is the disk full?).');
            }
            $this->verifyZip($zipPath, $dbName);
        } catch (\Throwable $e) {
            @unlink($zipPath);
            $this->error('Backup failed: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_file($snapshot)) {
                @unlink($snapshot);
            }
        }

        $this->info(sprintf('Backup saved: %s (%s)', $zipPath, $this->size((int) filesize($zipPath))));
        $removed = $this->prune($dir, max(1, (int) ($this->option('keep') ?: env('SK_BACKUP_KEEP', 14))));
        if ($removed) {
            $this->line("Removed {$removed} older backup(s).");
        }
        $this->warn('Copy this file to another computer or drive too: a backup on the same disk is lost if the disk fails.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ the database

    /** @return array{0:string,1:string} name inside the zip, and a note for the screen */
    protected function snapshotDatabase(string $target): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $pdo = $connection->getPdo();
            // VACUUM INTO writes a consistent copy even while the app is in use (it cannot run inside a transaction)
            $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $target) . "'");

            $check = new \PDO('sqlite:' . $target);
            $result = (string) $check->query('PRAGMA integrity_check')->fetchColumn();
            $check = null;
            if ($result !== 'ok') {
                throw new \RuntimeException("The database copy failed its integrity check ({$result}).");
            }

            return ['database.sqlite', 'SQLite copy made and checked'];
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $c = config("database.connections.{$connection->getName()}");
            $process = new Process(
                ['mysqldump', '--single-transaction', '--routines', '-h', (string) $c['host'], '-P', (string) $c['port'], '-u', (string) $c['username'], (string) $c['database'], '--result-file=' . $target],
                null,
                ['MYSQL_PWD' => (string) ($c['password'] ?? '')],
                null,
                300,
            );
            $process->run();
            if (! $process->isSuccessful() || ! is_file($target) || filesize($target) === 0) {
                throw new \RuntimeException('mysqldump failed or is not installed: ' . trim($process->getErrorOutput()));
            }

            return ['database.sql', 'MySQL dump made'];
        }

        throw new \RuntimeException("Backups of a {$driver} database are not supported yet.");
    }

    // ------------------------------------------------------------------ the files

    /** @return array{0:int,1:int} number of files, and their size */
    protected function addFolder(ZipArchive $zip, string $source, string $inside): array
    {
        if (! is_dir($source)) {
            return [0, 0];
        }

        $count = 0;
        $bytes = 0;
        $root = realpath($source);
        $skip = realpath(storage_path('app/backups'));
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink() || $file->getFilename() === '.gitignore') {
                continue;
            }
            $real = $file->getRealPath();
            if ($real === false || ($skip && str_starts_with($real, $skip))) {
                continue;
            }

            $name = $inside . '/' . str_replace('\\', '/', ltrim(substr($real, strlen($root)), '\\/'));
            $zip->addFile($real, $name);
            // photos, videos and documents are already compressed: store them as they are (much faster, same size)
            if (preg_match('/\.(jpe?g|png|gif|webp|mp4|m4v|mov|webm|zip|pdf|docx|xlsx|pptx)$/i', $name)) {
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            $count++;
            $bytes += (int) $file->getSize();
        }

        return [$count, $bytes];
    }

    protected function manifest(string $dbName, string $snapshot, array $counts, int $bytes): string
    {
        $lines = [
            'SK Finance backup',
            'Made:       ' . now()->format('Y-m-d H:i:s') . ' (' . config('app.timezone') . ')',
            'Database:   ' . $dbName . '  sha256 ' . hash_file('sha256', $snapshot),
        ];
        foreach ($counts as $folder => $n) {
            $lines[] = sprintf('%-26s %d file(s)', $folder . ':', $n);
        }
        $lines[] = 'Uploaded files total: ' . $this->size($bytes);
        $lines[] = '';
        $lines[] = 'NOT included: .env (passwords and keys), vendor/, the program code.';
        $lines[] = 'To restore: stop the app, put database.sqlite where database/database.sqlite is, copy the folders under files/ back';
        $lines[] = '(receipts-and-public -> storage/app/public, chat-files -> storage/app/private/messages, profile-photos -> public/uploads),';
        $lines[] = 'then run: php artisan migrate   (see README.md, "Backups").';

        return implode("\n", $lines) . "\n";
    }

    protected function verifyZip(string $path, string $dbName): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('The finished zip could not be opened again, so it is not trusted.');
        }
        $ok = $zip->locateName($dbName) !== false && $zip->locateName('MANIFEST.txt') !== false;
        $zip->close();

        if (! $ok) {
            throw new \RuntimeException('The finished zip is missing the database or the manifest.');
        }
    }

    // ------------------------------------------------------------------ the list

    protected function backups(string $dir): array
    {
        $files = glob($dir . '/sk-backup-*.zip') ?: [];
        usort($files, fn ($a, $b) => strcmp(basename($b), basename($a)));     // newest first (the name holds the time)

        return $files;
    }

    protected function prune(string $dir, int $keep): int
    {
        $removed = 0;
        foreach (array_slice($this->backups($dir), $keep) as $old) {
            if (@unlink($old)) {
                $removed++;
            }
        }

        return $removed;
    }

    protected function showList(string $dir): int
    {
        $files = $this->backups($dir);
        if (! $files) {
            $this->warn('No backup yet. Make one with: php artisan sk:backup');

            return self::SUCCESS;
        }

        $this->table(['Backup', 'Size', 'Made'], array_map(fn ($f) => [basename($f), $this->size((int) filesize($f)), date('Y-m-d H:i', (int) filemtime($f))], $files));

        return self::SUCCESS;
    }

    protected function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max($bytes, 1) / 1024, 0) . ' KB';
    }
}
