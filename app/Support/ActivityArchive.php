<?php

namespace App\Support;

use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The Admin's monthly activity-log files, kept inside the system. Nothing is deleted from the database:
 * the live Activity log page simply shows the current month, and every finished month is saved as a file.
 *
 *   storage/app/private/activity-logs/2026/2026-01-January.csv    one file per month
 *   storage/app/private/activity-logs/2026/2026-02-February.csv
 *   ...
 *   storage/app/private/activity-logs/2026/2026-whole-year.csv    every month of that year together
 *
 * sync() saves each month as soon as it has ended. The Admin pages call it, and so does the monthly
 * "activity:archive" command, so a month is never missed even if the command did not run.
 */
class ActivityArchive
{
    public const ROOT = 'activity-logs';

    public const HEADER = ['When', 'Who', 'Role', 'Barangay', 'Action', 'What happened', 'Details'];

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    /* ---------- names and paths ---------- */

    /** "October" (always English, so the file names are the same on every computer). */
    public static function monthName(int $month): string
    {
        return CarbonImmutable::create(2000, $month, 1)->format('F');
    }

    public static function monthPath(int $year, int $month): string
    {
        return sprintf('%s/%04d/%04d-%02d-%s.csv', self::ROOT, $year, $year, $month, self::monthName($month));
    }

    public static function yearPath(int $year): string
    {
        return sprintf('%s/%04d/%04d-whole-year.csv', self::ROOT, $year, $year);
    }

    /** $period is "01" to "12" for a month, or "year" for the whole-year file. */
    public static function pathFor(int $year, string $period): string
    {
        return $period === 'year' ? self::yearPath($year) : self::monthPath($year, (int) $period);
    }

    public static function labelFor(int $year, string $period): string
    {
        return $period === 'year' ? 'Whole year ' . $year : self::monthName((int) $period) . ' ' . $year;
    }

    /* ---------- one line of the log, as the columns of the file ---------- */

    /** The same columns as the "Download CSV" button on the Activity log page. */
    public static function row(ActivityLog $log): array
    {
        $details = '';

        if (is_array($log->details)) {
            $parts = [];
            foreach ($log->details as $field => $pair) {
                $parts[] = $field . ': ' . (is_array($pair)
                    ? self::text($pair[0] ?? null) . ' → ' . self::text($pair[1] ?? null)
                    : self::text($pair));
            }
            $details = implode('; ', $parts);
        }

        return [
            $log->created_at?->format('Y-m-d H:i:s'),
            $log->user_name ?? '',
            $log->user_role ?? '',
            $log->barangay?->name ?? '',
            $log->action,
            $log->description,
            $details,
        ];
    }

    private static function text(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /* ---------- writing the files ---------- */

    /** Write (or write again) one month's file from the database. Returns how many lines it holds. */
    public static function writeMonth(int $year, int $month): int
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfMonth();

        return self::write(self::monthPath($year, $month), $from, $from->endOfMonth());
    }

    /** Write (or write again) the whole-year file: every month of that year, oldest line first. */
    public static function writeYear(int $year): int
    {
        $from = CarbonImmutable::create($year, 1, 1)->startOfYear();

        return self::write(self::yearPath($year), $from, $from->endOfYear());
    }

    private static function write(string $path, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");   // byte-order mark: Excel reads the accents and the ñ correctly
        fputcsv($stream, self::HEADER, ',', '"', '');

        $count = 0;

        ActivityLog::query()
            ->with('barangay')
            ->whereBetween('created_at', [$from, $to])
            ->lazyById(500)
            ->each(function (ActivityLog $log) use ($stream, &$count) {
                fputcsv($stream, array_map([CsvExport::class, 'safe'], self::row($log)), ',', '"', '');
                $count++;
            });

        rewind($stream);
        $saved = self::disk()->writeStream($path, $stream);
        fclose($stream);

        if (! $saved) {
            throw new RuntimeException("Could not save the activity log file {$path}. Check that storage/app is writable.");
        }

        return $count;
    }

    /**
     * Save every month that has ended and has no file yet (from the first month with a log line up to last month),
     * then write the whole-year file of every year that got a new month. With $force, every file is written again.
     * Does nothing when everything is already saved.
     *
     * @return array{months: int, years: int} how many files were written
     */
    public static function sync(bool $force = false): array
    {
        $written = ['months' => 0, 'years' => 0];

        $first = ActivityLog::query()->min('created_at');

        if (! $first) {
            return $written;
        }

        $thisMonth = CarbonImmutable::now()->startOfMonth();
        $cursor    = CarbonImmutable::parse($first)->startOfMonth();
        $years     = [];   // year => true when its whole-year file must be written again

        while ($cursor->lt($thisMonth)) {
            $years[$cursor->year] = $years[$cursor->year] ?? false;

            if ($force || ! self::disk()->exists(self::monthPath($cursor->year, $cursor->month))) {
                self::writeMonth($cursor->year, $cursor->month);
                $written['months']++;
                $years[$cursor->year] = true;
            }

            $cursor = $cursor->addMonth();
        }

        foreach ($years as $year => $changed) {
            if ($changed || ! self::disk()->exists(self::yearPath($year))) {
                self::writeYear($year);
                $written['years']++;
            }
        }

        return $written;
    }

    /** sync() for the pages: it never breaks the page that calls it. */
    public static function catchUp(): void
    {
        try {
            self::sync();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /* ---------- what is saved ---------- */

    /** The years that have a folder, newest first (this year is always there). */
    public static function years(): array
    {
        return collect(self::disk()->directories(self::ROOT))
            ->map(fn ($dir) => basename($dir))
            ->filter(fn ($name) => preg_match('/^\d{4}$/', $name) === 1)
            ->map(fn ($name) => (int) $name)
            ->push((int) CarbonImmutable::now()->format('Y'))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * The files of one year folder.
     * Each file: period ("01".."12" or "year"), label, file name, size (bytes), saved_at, and entries (when $counts is true).
     *
     * @return array{year: ?array, months: array<int, array>}  months is keyed by the month number, in order
     */
    public static function folder(int $year, bool $counts = true): array
    {
        $folder = ['year' => null, 'months' => []];

        foreach (self::disk()->files(sprintf('%s/%04d', self::ROOT, $year)) as $path) {
            $name = basename($path);

            if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])-[A-Za-z]+\.csv$/', $name, $m) && (int) $m[1] === $year) {
                $folder['months'][(int) $m[2]] = self::info($path, $year, $m[2], $counts);
            } elseif ($name === sprintf('%04d-whole-year.csv', $year)) {
                $folder['year'] = self::info($path, $year, 'year', $counts);
            }
        }

        ksort($folder['months']);

        return $folder;
    }

    private static function info(string $path, int $year, string $period, bool $counts): array
    {
        return [
            'period'   => $period,
            'label'    => self::labelFor($year, $period),
            'file'     => basename($path),
            'size'     => self::disk()->size($path),
            'saved_at' => CarbonImmutable::createFromTimestamp(self::disk()->lastModified($path))->setTimezone(config('app.timezone')),
            'entries'  => $counts ? self::countRows($path) : null,
        ];
    }

    /** How many log lines a saved file holds (not counting its first, heading line). */
    public static function countRows(string $path): int
    {
        $stream = self::disk()->readStream($path);

        if (! is_resource($stream)) {
            return 0;
        }

        $rows = -1;   // the first line is the heading

        while (fgetcsv($stream, 0, ',', '"', '') !== false) {
            $rows++;
        }

        fclose($stream);

        return max($rows, 0);
    }

    /**
     * One page of a saved file, optionally only the lines that contain $term (any column, capitals ignored).
     * The file is read line by line, so a big year does not fill the memory.
     *
     * @return array{0: array<int, array<int, string>>, 1: int}  the lines of the page, and how many lines match in all
     */
    public static function read(string $path, ?string $term, int $page, int $perPage): array
    {
        $stream = self::disk()->readStream($path);

        if (! is_resource($stream)) {
            return [[], 0];
        }

        $term   = ($term !== null && $term !== '') ? mb_strtolower($term) : null;
        $offset = max(0, ($page - 1) * $perPage);
        $rows   = [];
        $total  = 0;
        $first  = true;

        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($first) {      // the heading line
                $first = false;
                continue;
            }

            if ($term !== null && mb_strpos(mb_strtolower(implode(' ', $row)), $term) === false) {
                continue;
            }

            if ($total >= $offset && count($rows) < $perPage) {
                $rows[] = $row;
            }

            $total++;
        }

        fclose($stream);

        return [$rows, $total];
    }
}
