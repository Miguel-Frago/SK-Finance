<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\PeriodLock;
use App\Models\User;
use App\Support\PeriodLocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The quarter lock page. Everyone who can see finance can see it; the Treasurer and the Chairman act on it. */
class PeriodLockController extends Controller
{
    public function index(): View
    {
        $budget = Budget::current();
        $year = (int) ($budget?->year ?: now()->year);

        $rows = PeriodLock::query()->where('year', $year)->get()->keyBy('quarter');
        $quarters = [];
        foreach ([1, 2, 3, 4] as $q) {
            [$start, $end] = PeriodLock::bounds($year, $q);
            $lock = $rows->get($q) ?? new PeriodLock(['year' => $year, 'quarter' => $q, 'status' => PeriodLock::OPEN]);
            $quarters[] = ['lock' => $lock, 'q' => $q, 'start' => $start, 'end' => $end, 'ended' => $end->lt(today())];
        }

        return view('period-locks.index', ['year' => $year, 'quarters' => $quarters, 'role' => auth()->user()->role]);
    }

    public function requestLock(int $year, int $quarter): RedirectResponse
    {
        $lock = PeriodLocks::requestLock(auth()->user(), $year, $quarter);

        return back()->with('success', 'Asked the SK Chairman to approve the lock of ' . $lock->label() . '.');
    }

    public function withdraw(int $year, int $quarter): RedirectResponse
    {
        PeriodLocks::withdrawLock(auth()->user(), $this->lock($year, $quarter));

        return back()->with('success', 'Request taken back.');
    }

    public function approve(int $year, int $quarter): RedirectResponse
    {
        $lock = $this->lock($year, $quarter);
        PeriodLocks::approveLock(auth()->user(), $lock);

        return back()->with('success', $lock->label() . ' is now locked.');
    }

    public function reject(Request $request, int $year, int $quarter): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']], ['note.required' => 'Write a short reason for turning it down.', 'note.min' => 'Write a short reason for turning it down.']);
        PeriodLocks::rejectLock(auth()->user(), $this->lock($year, $quarter), trim($data['note']));

        return back()->with('success', 'Turned down. The quarter stays open.');
    }

    public function requestUnlock(Request $request, int $year, int $quarter): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], ['reason.required' => 'Explain why the quarter must be opened again.', 'reason.min' => 'Explain why in at least 10 letters: the Admin decides from this.']);
        PeriodLocks::requestUnlock(auth()->user(), $this->lock($year, $quarter), trim($data['reason']));

        return back()->with('success', 'Asked the Admin to unlock it. The quarter stays locked until the Admin allows it.');
    }

    private function lock(int $year, int $quarter): PeriodLock
    {
        return PeriodLock::query()->where('year', $year)->where('quarter', $quarter)->firstOrFail();   // only this barangay's (scope)
    }
}
