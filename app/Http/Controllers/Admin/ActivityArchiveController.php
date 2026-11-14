<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ActivityArchive;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Admin's Log archive: one folder per year (2026), inside it one activity-log file per month and a whole-year file.
 * The Admin can open any file on screen, print it, or download it as CSV.
 */
class ActivityArchiveController extends Controller
{
    private const PER_PAGE = 40;

    /** All the year folders. */
    public function index(): View
    {
        ActivityArchive::catchUp();

        $years = collect(ActivityArchive::years())->map(function (int $year) {
            $folder = ActivityArchive::folder($year, false);
            $months = array_keys($folder['months']);

            return [
                'year'   => $year,
                'months' => count($months),
                'from'   => $months ? ActivityArchive::monthName(min($months)) : null,
                'to'     => $months ? ActivityArchive::monthName(max($months)) : null,
                'whole'  => $folder['year'] !== null,
            ];
        });

        return view('admin.archive.index', [
            'years' => $years,
            'now'   => CarbonImmutable::now(),
        ]);
    }

    /** One year folder: the whole-year file and the month files. */
    public function year(int $year): View
    {
        ActivityArchive::catchUp();

        $now    = CarbonImmutable::now();
        $folder = ActivityArchive::folder($year);

        abort_if($year > $now->year || ($year < $now->year && $folder['months'] === [] && $folder['year'] === null), 404);

        return view('admin.archive.year', [
            'year'   => $year,
            'folder' => $folder,
            'now'    => $now,
        ]);
    }

    /** Open a file on screen: $period is "01".."12", or "year". */
    public function show(Request $request, int $year, string $period): View|RedirectResponse
    {
        ActivityArchive::catchUp();

        if ($redirect = $this->prepare($year, $period)) {
            return $redirect;
        }

        $path = ActivityArchive::pathFor($year, $period);
        $term = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));

        [$rows, $total] = ActivityArchive::read($path, $term, $page, self::PER_PAGE);

        // a page number beyond the end (for example after a new search): show the last page instead
        if ($rows === [] && $total > 0 && $page > 1) {
            $page = (int) ceil($total / self::PER_PAGE);
            [$rows, $total] = ActivityArchive::read($path, $term, $page, self::PER_PAGE);
        }

        $logs = new LengthAwarePaginator($rows, $total, self::PER_PAGE, $page, [
            'path'  => $request->url(),
            'query' => $request->query(),
        ]);

        return view('admin.archive.show', [
            'year'    => $year,
            'period'  => $period,
            'label'   => ActivityArchive::labelFor($year, $period),
            'file'    => basename($path),
            'logs'    => $logs,
            'term'    => $term,
            'entries' => $term === '' ? $total : ActivityArchive::countRows($path),
        ]);
    }

    public function download(int $year, string $period): StreamedResponse|RedirectResponse
    {
        ActivityArchive::catchUp();

        if ($redirect = $this->prepare($year, $period)) {
            return $redirect;
        }

        $path = ActivityArchive::pathFor($year, $period);

        return ActivityArchive::disk()->download($path, basename($path), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Makes sure the file that was asked for exists. Returns a redirect to the live page when it is the month
     * that is still going on (it is saved when the month ends); stops with "not found" for anything that cannot exist.
     */
    private function prepare(int $year, string $period): ?RedirectResponse
    {
        $now = CarbonImmutable::now();

        abort_if($year < 2000 || $year > $now->year, 404);

        if ($period === 'year') {
            if ($year === $now->year) {
                // this year's file is written again each time, so it also holds the month in progress
                ActivityArchive::writeYear($year);
            } elseif (! ActivityArchive::disk()->exists(ActivityArchive::yearPath($year))) {
                abort_if(ActivityArchive::folder($year, false)['months'] === [], 404);
                ActivityArchive::writeYear($year);
            }

            return null;
        }

        $month = (int) $period;

        abort_if($year === $now->year && $month > $now->month, 404);

        if ($year === $now->year && $month === $now->month) {
            return redirect()->route('admin.activity')
                ->with('warning', 'This month is still going on. It is saved as a file when the month ends.');
        }

        abort_unless(ActivityArchive::disk()->exists(ActivityArchive::monthPath($year, $month)), 404);

        return null;
    }
}
