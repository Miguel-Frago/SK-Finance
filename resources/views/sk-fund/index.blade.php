@extends('layouts.app')

@section('title', 'SK Fund Allocation & Transfers')

@section('content')
<div class="page-header">
    <div>
        <h1>SK Fund Allocation &amp; Transfers</h1>
        <p class="muted">{{ auth()->user()->portal()?->name }} · Municipality of Luna, La Union</p>
    </div>
    <form method="GET" action="{{ route('sk-fund.index') }}" class="inline-form">
        <label for="year">Calendar year</label>
        <input id="year" type="number" name="year" min="2000" max="2200" value="{{ $year }}">
        <button class="btn btn-secondary" type="submit">View</button>
    </form>
</div>

@if ($allocation)
    <div class="stats-grid">
        <section class="stat-card"><span>10% SK Fund Allocation</span><strong>@peso($allocation->allocation_amount)</strong></section>
        <section class="stat-card"><span>Transferred from 10% SK Fund</span><strong>@peso($allocation->sk_fund_transferred)</strong></section>
        <section class="stat-card"><span>Remaining to be transferred</span><strong>@peso($allocation->remaining_sk_fund)</strong></section>
        <section class="stat-card"><span>Transferred from SK Surplus</span><strong>@peso($allocation->surplus_transferred)</strong></section>
    </div>
    <section class="panel">
        <h2>Allocation details · CY {{ $year }}</h2>
        <dl class="details-grid">
            <div><dt>Monthly allocation</dt><dd>@if($allocation->monthly_allocation) @peso($allocation->monthly_allocation) @else — @endif</dd></div>
            <div><dt>Transfer progress</dt><dd>{{ $allocation->allocation_amount > 0 ? number_format(min(100, $allocation->sk_fund_transferred / $allocation->allocation_amount * 100), 2) : '0.00' }}%</dd></div>
            <div class="wide"><dt>Remarks</dt><dd>{{ $allocation->remarks ?: '—' }}</dd></div>
        </dl>
    </section>
@else
    <div class="empty-state"><h2>No allocation recorded for {{ $year }}</h2><p>Enter the approved 10% SK Fund allocation to begin tracking transfers.</p></div>
@endif

@if ($canManage)
<section class="panel">
    <h2>{{ $allocation ? 'Update allocation' : 'Set annual allocation' }}</h2>
    <form method="POST" action="{{ route('sk-fund.allocation') }}" class="form-grid">
        @csrf
        <div class="form-group"><label for="allocation_year">Calendar year</label><input id="allocation_year" name="year" type="number" min="2000" max="2200" value="{{ old('year', $year) }}" required></div>
        <div class="form-group"><label for="allocation_amount">10% SK Fund allocation (₱)</label><input id="allocation_amount" name="allocation_amount" type="number" min="0" step="0.01" value="{{ old('allocation_amount', $allocation?->allocation_amount) }}" required></div>
        <div class="form-group"><label for="monthly_allocation">Monthly allocation (₱)</label><input id="monthly_allocation" name="monthly_allocation" type="number" min="0" step="0.01" value="{{ old('monthly_allocation', $allocation?->monthly_allocation) }}"></div>
        <div class="form-group wide"><label for="allocation_remarks">Remarks</label><textarea id="allocation_remarks" name="remarks" rows="2">{{ old('remarks', $allocation?->remarks) }}</textarea></div>
        <div class="wide"><button class="btn btn-primary" type="submit">Save allocation</button></div>
    </form>
</section>
@endif

@if ($allocation && $canManage)
<section class="panel">
    <h2>Record a transfer</h2>
    <form method="POST" action="{{ route('sk-fund.transfers.store') }}" class="form-grid">
        @csrf
        <input type="hidden" name="sk_fund_allocation_id" value="{{ $allocation->id }}">
        <div class="form-group"><label for="transfer_date">Transfer date</label><input id="transfer_date" name="transfer_date" type="date" value="{{ old('transfer_date', now()->toDateString()) }}" required></div>
        <div class="form-group"><label for="source">Fund source</label><select id="source" name="source" required><option value="sk_fund">10% SK Fund</option><option value="sk_surplus">SK Surplus</option></select></div>
        <div class="form-group"><label for="amount">Amount (₱)</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" value="{{ old('amount') }}" required></div>
        <div class="form-group"><label for="reference">Reference / bank transaction no.</label><input id="reference" name="reference" maxlength="100" value="{{ old('reference') }}"></div>
        <div class="form-group wide"><label for="transfer_remarks">Remarks</label><textarea id="transfer_remarks" name="remarks" rows="2">{{ old('remarks') }}</textarea></div>
        <div class="wide"><button class="btn btn-primary" type="submit">Record transfer</button></div>
    </form>
</section>
@endif

<section class="panel">
    <h2>Transfer history</h2>
    @if ($transfers->isEmpty())
        <p class="muted">No transfers recorded for this year.</p>
    @else
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Date</th><th>Source</th><th>Reference</th><th>Remarks</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
            @foreach ($transfers as $transfer)
                <tr><td>{{ $transfer->transfer_date->format('M d, Y') }}</td><td>{{ $transfer->source_label }}</td><td>{{ $transfer->reference ?: '—' }}</td><td>{{ $transfer->remarks ?: '—' }}</td><td class="text-right">@peso($transfer->amount)</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><th colspan="4">Total transferred (all sources)</th><th class="text-right">@peso($allocation->sk_fund_transferred + $allocation->surplus_transferred)</th></tr></tfoot>
        </table></div>
    @endif
</section>
@endsection
