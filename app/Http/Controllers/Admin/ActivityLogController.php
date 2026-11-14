<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Support\ActivityArchive;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Admin's view of who did what, where and when, across all barangays. Read-only; can be printed or saved as CSV.
 * This page shows the CURRENT MONTH only, so it starts fresh on the 1st. Nothing is deleted: each finished month is
 * saved as a file in the Log archive (see ActivityArchiveController).
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): View|StreamedResponse
    {
        // Save any finished month that has no file yet (a missed monthly run is made up for here)
        ActivityArchive::catchUp();

        $monthStart = CarbonImmutable::now()->startOfMonth();
        $monthEnd   = $monthStart->endOfMonth();
        $first      = $monthStart->toDateString();
        $last       = $monthEnd->toDateString();

        $filters = $request->validate([
            'barangay_id' => ['nullable', 'integer'],
            'action'      => ['nullable', 'string', 'max:30'],
            'q'           => ['nullable', 'string', 'max:100'],
            'date_from'   => ['nullable', 'date', "after_or_equal:{$first}", "before_or_equal:{$last}"],
            'date_to'     => ['nullable', 'date', 'after_or_equal:date_from', "after_or_equal:{$first}", "before_or_equal:{$last}"],
        ]);

        if ($request->query('export') === 'csv') {
            return $this->csv($filters, $monthStart, $monthEnd);
        }

        $logs = $this->filtered($filters, $monthStart, $monthEnd)
            ->with('barangay')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        // Last month's file, when it exists: a quick link under the page heading
        $before   = $monthStart->subMonth();
        $previous = ActivityArchive::disk()->exists(ActivityArchive::monthPath($before->year, $before->month))
            ? ['year' => $before->year, 'period' => $before->format('m'), 'label' => $before->format('F Y')]
            : null;

        return view('admin.activity', [
            'logs'       => $logs,
            'filters'    => $filters,
            'barangays'  => Barangay::orderBy('name')->get(['id', 'name']),
            'actions'    => ActivityLog::query()
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->select('action')->distinct()->orderBy('action')->pluck('action'),
            'monthStart' => $monthStart,
            'monthEnd'   => $monthEnd,
            'previous'   => $previous,
        ]);
    }

    /** Every line of this month that matches the filters (not only the page on screen), newest first. */
    private function csv(array $filters, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): StreamedResponse
    {
        $rows = $this->filtered($filters, $monthStart, $monthEnd)
            ->with('barangay')
            ->orderByDesc('id')
            ->lazy(500)
            ->map(fn (ActivityLog $log) => ActivityArchive::row($log));

        return CsvExport::download(
            'activity-log-' . $monthStart->format('Y-m') . '.csv',
            ActivityArchive::HEADER,
            $rows
        );
    }

    private function filtered(array $filters, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): Builder
    {
        return ActivityLog::query()
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->when($filters['barangay_id'] ?? null, fn ($q, $id) => $q->where('barangay_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('description', 'like', "%{$term}%")->orWhere('user_name', 'like', "%{$term}%");
                });
            });
    }
}
