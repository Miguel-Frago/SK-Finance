<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\User;
use App\Support\CsvExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** All 40 barangays with who currently holds the Treasurer, Chairman and Secretary places, and how many Kagawads. Can be filtered, printed or saved as CSV. */
class BarangayController extends Controller
{
    public function index(Request $request): View|StreamedResponse
    {
        $filter = in_array($request->query('show'), ['vacant', 'active'], true) ? (string) $request->query('show') : 'all';
        $term   = trim((string) $request->query('q', ''));

        $holders = User::where('status', User::STATUS_APPROVED)
            ->where('is_active', true)
            ->whereIn('role', array_keys(User::ROLES))
            ->get()
            ->groupBy('barangay_id');

        // one line per barangay: [barangay, treasurer|null, chairman|null]
        $all = Barangay::orderBy('name')->get()->map(function (Barangay $b) use ($holders) {
            $people = $holders->get($b->id, collect());

            return [
                'barangay'  => $b,
                'treasurer' => $people->firstWhere('role', User::ROLE_TREASURER),
                'chairman'  => $people->firstWhere('role', User::ROLE_CHAIRMAN),
                'secretary' => $people->firstWhere('role', User::ROLE_SECRETARY),
                'kagawads'  => $people->where('role', User::ROLE_KAGAWAD)->count(),
            ];
        });

        $summary = [
            'total'     => $all->count(),
            'treasurer' => $all->filter(fn ($r) => $r['treasurer'])->count(),
            'chairman'  => $all->filter(fn ($r) => $r['chairman'])->count(),
        ];

        $rows = $all
            ->when($filter === 'vacant', fn (Collection $c) => $c->filter(fn ($r) => ! $r['treasurer']))
            ->when($filter === 'active', fn (Collection $c) => $c->filter(fn ($r) => (bool) $r['treasurer']))
            ->when($term !== '', fn (Collection $c) => $c->filter(function ($r) use ($term) {
                $haystack = implode(' ', [$r['barangay']->name, $r['treasurer']?->name, $r['chairman']?->name, $r['secretary']?->name]);

                return mb_stripos($haystack, $term) !== false;
            }))
            ->values();

        if ($request->query('export') === 'csv') {
            return CsvExport::download(
                'barangays-' . now()->format('Y-m-d') . '.csv',
                ['Barangay', 'District', 'SK Treasurer', 'Treasurer email', 'SK Chairman', 'Chairman email', 'SK Secretary', 'Secretary email', 'Kagawads', 'Portal'],
                $rows->map(fn ($r) => [
                    $r['barangay']->name,
                    $r['barangay']->district ?: '',
                    $r['treasurer']?->name ?? 'vacant',
                    $r['treasurer']?->email ?? '',
                    $r['chairman']?->name ?? 'vacant',
                    $r['chairman']?->email ?? '',
                    $r['secretary']?->name ?? 'vacant',
                    $r['secretary']?->email ?? '',
                    $r['kagawads'] . ' of ' . User::SEATS[User::ROLE_KAGAWAD],
                    $r['treasurer'] ? 'Active' : 'Not started',
                ])
            );
        }

        return view('admin.barangays', compact('rows', 'summary', 'filter', 'term'));
    }
}
