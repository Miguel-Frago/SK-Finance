@extends('layouts.app')

@section('title', 'New Supplemental Budget')

@section('content')
    <section class="card card-narrow">
        <h2 class="card-title">Carry over unspent funds</h2>
        <p class="muted">
            A supplemental budget is a separate pot made from the leftover funds of an earlier budget.
            It has its own categories, allocations, balances, and transactions, so it never mixes with the regular budget.
        </p>

        {{-- Choose the source budget (reloads the page with that budget's leftover amounts) --}}
        <form method="GET" action="{{ route('budget.supplemental.create') }}" class="form">
            <div class="form-group">
                <label for="source">Carry over from</label>
                <select id="source" name="source" onchange="this.form.submit()">
                    @foreach ($sources as $s)
                        <option value="{{ $s->id }}" @selected($s->id === $source->id)>{{ $s->display_name }}</option>
                    @endforeach
                </select>
            </div>
        </form>

        <div class="summary-box">
            <h3>Unspent in {{ $source->display_name }}</h3>
            <dl>
                <div><dt>Remaining budget</dt><dd>@peso($preview['total_budget'])</dd></div>
                <div><dt>Bank balance</dt><dd>@peso($preview['opening_bank_balance'])</dd></div>
                <div><dt>Cash on hand</dt><dd>@peso($preview['opening_cash_balance'])</dd></div>
            </dl>
        </div>

        <form method="POST" action="{{ route('budget.supplemental.store') }}" class="form form-grid">
            @csrf
            <input type="hidden" name="source_id" value="{{ $source->id }}">

            <div class="form-group">
                <label for="year">Supplemental for year</label>
                <input id="year" type="number" name="year" min="2000" max="2100" value="{{ old('year', $source->year + 1) }}" required>
                <small class="hint">Must be later than {{ $source->year }}.</small>
            </div>
            <div class="form-group">
                <label for="label">Label (optional)</label>
                <input id="label" type="text" name="label" maxlength="255" value="{{ old('label') }}" placeholder="Supplemental {{ $source->year + 1 }} (from {{ $source->year }})">
            </div>
            <div class="form-group">
                <label for="total_budget">Supplemental budget (₱)</label>
                <input id="total_budget" type="number" name="total_budget" step="0.01" min="0" value="{{ old('total_budget', $preview['total_budget']) }}" required>
            </div>
            <div class="form-group">
                <label for="opening_bank_balance">Opening bank balance (₱)</label>
                <input id="opening_bank_balance" type="number" name="opening_bank_balance" step="0.01" min="0" value="{{ old('opening_bank_balance', $preview['opening_bank_balance']) }}">
            </div>
            <div class="form-group">
                <label for="opening_cash_balance">Opening cash on hand (₱)</label>
                <input id="opening_cash_balance" type="number" name="opening_cash_balance" step="0.01" min="0" value="{{ old('opening_cash_balance', $preview['opening_cash_balance']) }}">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create supplemental budget</button>
                <a href="{{ route('budget.index') }}" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </section>
@endsection
