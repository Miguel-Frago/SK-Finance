<?php

namespace App\Http\Controllers;

use App\Models\SkFundAllocation;
use App\Models\SkFundTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class SkFundController extends Controller
{
    public function index(Request $request): View
    {
        $year = (int) $request->input('year', now()->year);
        abort_if($year < 2000 || $year > 2200, 404);

        $allocation = SkFundAllocation::with(['transfers' => fn ($q) => $q->orderBy('transfer_date')->orderBy('id')])
            ->where('year', $year)->first();

        return view('sk-fund.index', [
            'year' => $year,
            'allocation' => $allocation,
            'years' => SkFundAllocation::orderByDesc('year')->pluck('year')->unique(),
            'transfers' => $allocation?->transfers ?? collect(),
            'canManage' => $request->user()->canManageFinance(),
        ]);
    }

    public function saveAllocation(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageFinance(), 403);

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2200'],
            'allocation_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999.99'],
            'monthly_allocation' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999.99'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $allocation = SkFundAllocation::firstOrNew(['year' => $data['year']]);
        $allocation->fill($data);
        $allocation->save();

        return redirect()->route('sk-fund.index', ['year' => $allocation->year])
            ->with('success', 'SK Fund allocation saved.');
    }

    public function storeTransfer(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageFinance(), 403);

        $data = $request->validate([
            'sk_fund_allocation_id' => ['required', 'integer', Rule::exists('sk_fund_allocations', 'id')->where('barangay_id', $request->user()->portalId())],
            'transfer_date' => ['required', 'date'],
            'source' => ['required', Rule::in(['sk_fund', 'sk_surplus'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'reference' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $allocation = SkFundAllocation::findOrFail($data['sk_fund_allocation_id']);
        if ($data['source'] === 'sk_fund') {
            $transferred = (float) $allocation->transfers()->where('source', 'sk_fund')->sum('amount');
            if ($transferred + (float) $data['amount'] > (float) $allocation->allocation_amount) {
                return back()->withErrors(['amount' => 'This transfer exceeds the remaining 10% SK Fund allocation.'])->withInput();
            }
        }

        $transfer = new SkFundTransfer($data);
        $transfer->save();

        return redirect()->route('sk-fund.index', ['year' => $allocation->year])
            ->with('success', 'Fund transfer recorded.');
    }
}
