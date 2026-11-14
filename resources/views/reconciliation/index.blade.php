@extends('layouts.app')

@section('title', 'Bank reconciliation')

@section('content')
@if (! $budget)
    <section class="card card-narrow">
        <h2 class="card-title">No budget yet</h2>
        <p class="muted">Create a budget first. A reconciliation compares the bank statement with that budget's books.</p>
    </section>
@else
<div class="page-head">
    <div>
        <h2 class="section-title">Bank reconciliation</h2>
        <p class="muted">{{ $budget->display_name }} &middot; the books say the bank account holds <strong>@peso($today)</strong> today. Compare it with the bank statement; a saved reconciliation is never changed, so the history is your evidence.</p>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-error"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

@can('reconcile')
<section class="card no-print">
    <h3 class="card-title">New reconciliation</h3>
    <form method="POST" action="{{ route('reconciliation.store') }}" class="form">
        @csrf
        <div class="form-fields">
            <div class="form-group">
                <label for="statement_date">Statement date</label>
                <input type="date" id="statement_date" name="statement_date" value="{{ old('statement_date', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required>
            </div>
            <div class="form-group">
                <label for="statement_balance">Balance on the bank statement</label>
                <input type="number" step="0.01" min="0" id="statement_balance" name="statement_balance" value="{{ old('statement_balance') }}" required>
            </div>
            <div class="form-group">
                <label for="outstanding_checks">Checks written, not yet cleared</label>
                <input type="number" step="0.01" min="0" id="outstanding_checks" name="outstanding_checks" value="{{ old('outstanding_checks', 0) }}">
            </div>
            <div class="form-group">
                <label for="deposits_in_transit">Deposits made, not yet on the statement</label>
                <input type="number" step="0.01" min="0" id="deposits_in_transit" name="deposits_in_transit" value="{{ old('deposits_in_transit', 0) }}">
            </div>
            <div class="form-group form-wide">
                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="2" maxlength="500">{{ old('notes') }}</textarea>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Compare and save</button>
    </form>
</section>
@endcan

<section class="card">
    <h3 class="card-title">History</h3>
    @if ($history->isEmpty())
        <p class="muted">Nothing saved yet.</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Statement date</th><th class="num">Statement</th><th class="num">Adjusted</th><th class="num">Books said</th><th class="num">Difference</th><th>Result</th><th>Prepared by</th></tr></thead>
                <tbody>
                @foreach ($history as $r)
                    <tr>
                        <td class="nowrap">{{ $r->statement_date->format('M d, Y') }}</td>
                        <td class="num">@peso($r->statement_balance)</td>
                        <td class="num">@peso($r->adjustedBalance())
                            @if ((float) $r->outstanding_checks || (float) $r->deposits_in_transit)
                                <small class="hint block">&minus; checks @peso($r->outstanding_checks) &plus; deposits @peso($r->deposits_in_transit)</small>
                            @endif
                        </td>
                        <td class="num">@peso($r->system_balance)</td>
                        <td class="num">@peso($r->difference)</td>
                        <td>
                            @if ($r->matches())
                                <span class="badge badge-bank">Matched</span>
                            @else
                                <span class="badge badge-void">Difference</span>
                            @endif
                            @if ($r->notes)<small class="hint block">{{ $r->notes }}</small>@endif
                        </td>
                        <td>{{ $r->preparedBy?->name ?? '—' }}<small class="hint block">{{ $r->created_at->format('M d, Y g:i A') }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endif
@endsection
