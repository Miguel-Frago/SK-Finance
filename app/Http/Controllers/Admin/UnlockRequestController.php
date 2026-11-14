<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PeriodLock;
use App\Models\User;
use App\Support\PeriodLocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The Admin decides whether a locked quarter may be opened again. The Admin sees the barangay, the quarter, who asked and why:
 * never a single peso, and never a record. That is what makes the unlock a real second check from outside the barangay.
 */
class UnlockRequestController extends Controller
{
    public function index(): View
    {
        $requests = PeriodLock::withoutGlobalScopes()->with('barangay')->where('status', PeriodLock::UNLOCK_REQUESTED)->orderBy('unlock_requested_at')->get();
        $askers = User::query()->whereIn('id', $requests->pluck('unlock_requested_by'))->get()->keyBy('id');

        return view('admin.unlocks', ['requests' => $requests, 'askers' => $askers]);
    }

    public function approve(int $id): RedirectResponse
    {
        PeriodLocks::approveUnlock(Auth::guard('admin')->user(), $this->find($id));

        return back()->with('success', 'Unlocked. The barangay can change that quarter again, and must ask again to lock it.');
    }

    public function deny(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']], ['note.required' => 'Write a short reason, so the barangay knows why.', 'note.min' => 'Write a short reason, so the barangay knows why.']);
        PeriodLocks::denyUnlock(Auth::guard('admin')->user(), $this->find($id), trim($data['note']));

        return back()->with('success', 'Turned down. The quarter stays locked.');
    }

    private function find(int $id): PeriodLock
    {
        return PeriodLock::withoutGlobalScopes()->with('barangay')->findOrFail($id);
    }
}
