@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

@if (! $budget)
    {{-- ============ No budget yet ============ --}}
    <section class="card card-narrow">
        <h2 class="card-title">Welcome, {{ auth()->user()->name }}</h2>
        <p class="muted">No budget has been set up yet. Start with the annual budget.</p>
        <a href="{{ route('budget.index') }}" class="btn btn-primary">Go to Budget &amp; Allocation</a>
    </section>
@else
    {{-- ============ Header ============ --}}
    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $budget->display_name }}</h2>
            <p class="muted">Where the money is, how much of the budget is spent, and what happened recently.</p>
        </div>
        <div class="btn-row">
            @include('partials.print-button')
            @can('manage')
                <a href="{{ route('transactions.create') }}" class="btn btn-primary">Record expense</a>
                <a href="{{ route('fund-movements.create') }}" class="btn btn-secondary">Record fund movement</a>
            @endcan
        </div>
    </div>

    @php($spentLevel = \App\Support\Usage::level($spentPct))   {{-- low = yellow, medium = orange, high = red --}}

    {{-- ============ Summary cards ============ --}}
    <div class="metric-grid">
        <div class="metric-card metric-total">
            <span class="metric-label">Total budget</span>
            <span class="metric-value">@peso($budget->total_budget)</span>
        </div>
        <div class="metric-card metric-spent">
            <span class="metric-label">Spent so far</span>
            <span class="metric-value">@peso($spent)</span>
        </div>
        <div class="metric-card metric-left">
            <span class="metric-label">Budget left</span>
            <span class="metric-value">@peso($left)</span>
        </div>
    </div>
    <div class="metric-grid">
        <div class="metric-card metric-bank">
            <span class="metric-label">Bank balance</span>
            <span class="metric-value">@peso($budget->bank_balance)</span>
        </div>
        <div class="metric-card metric-cash">
            <span class="metric-label">Cash on hand</span>
            <span class="metric-value">@peso($budget->cash_balance)</span>
        </div>
        <div class="metric-card metric-neutral">
            <span class="metric-label">Total funds on hand</span>
            <span class="metric-value">@peso($budget->total_funds_on_hand)</span>
        </div>
    </div>

    {{-- Do the money records agree? Bank + Cash is compared with the budget still left (a plain note, not an error:
         the two can differ when money has not been recorded as received yet) --}}
    @php($fundsGap = round((float) $budget->total_funds_on_hand - (float) $left, 2))
    <p class="funds-check {{ $fundsGap == 0 ? 'is-ok' : 'is-note' }}" role="status">
        @if ($fundsGap == 0)
            <span aria-hidden="true">&#10003;</span> Bank + Cash equals Budget left. Your records balance.
        @elseif ($fundsGap < 0)
            <span aria-hidden="true">i</span> Total funds on hand are @peso(abs($fundsGap)) below Budget left. That is normal while some of the budget has not been recorded as received yet (see Fund Movements).
        @else
            <span aria-hidden="true">i</span> Total funds on hand are @peso($fundsGap) above Budget left. Check that every receipt was recorded against this budget.
        @endif
    </p>

    {{-- ============ Budget used ============ --}}
    <section class="card">
        <h3 class="card-title">Budget used</h3>
        <div class="pool-bar pool-bar-spent" role="img" aria-label="{{ $spentPct }}% of the budget has been spent">
            <span class="pool-allocated fill-{{ $spentLevel }}" style="width: {{ $spentPct }}%"></span>
        </div>
        <div class="pool-legend">
            <span><i class="dot fill-{{ $spentLevel }}"></i> Spent @peso($spent) ({{ $spentPct }}%)</span>
            <span><i class="dot dot-left"></i> Left @peso($left) ({{ round(100 - $spentPct, 1) }}%)</span>
            <span>Paid from bank @peso($bankSpent)</span>
            <span>Paid from cash @peso($cashSpent)</span>
        </div>
    </section>

    {{-- ============ Charts: spending by month and by category ============ --}}
    <div class="overview-pair">
        <section class="card">
            <h3 class="card-title">Spending by month, {{ $budget->year }}</h3>
            <div class="month-chart" role="img" aria-label="Amount spent in each month of {{ $budget->year }}">
                @foreach ($months as $month)
                    <div class="month-col" title="{{ $month['name'] }}: ₱{{ number_format($month['total'], 2) }}">
                        <div class="month-track">
                            <span class="month-bar {{ $month['total'] > 0 ? 'level-' . $month['level'] : 'is-empty' }}" style="height: {{ $month['pct'] }}%"></span>
                        </div>
                        <span class="month-label">{{ $month['label'] }}</span>
                    </div>
                @endforeach
            </div>
            @php($peak = collect($months)->sortByDesc('total')->first())
            <p class="chart-note">
                @if ($peak['total'] > 0)
                    Highest month: <strong>{{ $peak['name'] }}</strong> (@peso($peak['total'])). Bars are coloured against the highest month: yellow is light, orange is medium, red is close to the peak. Hover a bar to see its amount.
                @else
                    No expenses recorded yet.
                @endif
            </p>
        </section>

        <section class="card">
            <h3 class="card-title">Spent by category</h3>
            @include('budget._donut', [
                'segments'     => $categories,
                'centerTop'    => $spentPct . '%',
                'centerBottom' => 'spent',
                'centerLevel'  => $spentLevel,
                'chartLabel'   => 'Share of the total budget spent, by category, and the budget left',
                'chartSize'    => 'md',
            ])
        </section>
    </div>

    {{-- ============ Items close to their allocation ============ --}}
    @if ($nearlyUsed->isNotEmpty())
        <section class="card">
            <h3 class="card-title">Items nearly used up</h3>
            <p class="muted">These items have used 80% or more of their allocation.</p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Allocated</th>
                            <th class="num">Spent</th>
                            <th class="num">Left</th>
                            <th class="num">Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($nearlyUsed as $item)
                            <tr>
                                <td>{{ $item->display_name }}</td>
                                <td class="num">@peso($item->allocated_amount)</td>
                                <td class="num">@peso($item->spent)</td>
                                <td class="num">@peso($item->balance)</td>
                                <td class="num pct-{{ \App\Support\Usage::level($item->utilization_percent) }}">{{ $item->utilization_percent }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ============ Recent activity ============ --}}
    <div class="overview-pair">
        <section class="card">
            <div class="dash-grid-head">
                <h3 class="card-title">Recent expenses</h3>
                <a href="{{ route('transactions.index') }}" class="link-action">View all</a>
            </div>
            @if ($recentExpenses->isEmpty())
                <p class="empty">No expenses recorded yet.</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentExpenses as $expense)
                                <tr>
                                    <td class="nowrap">{{ $expense->date->format('M d') }}</td>
                                    <td>
                                        {{ $expense->description }}
                                        <small class="hint block">{{ $expense->subcategory->display_name }} &middot; {{ $expense->payment_method_label }}</small>
                                    </td>
                                    <td class="num">@peso($expense->amount)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card">
            <div class="dash-grid-head">
                <h3 class="card-title">Recent fund movements</h3>
                <a href="{{ route('fund-movements.index') }}" class="link-action">View all</a>
            </div>
            @if ($recentMovements->isEmpty())
                <p class="empty">No fund movements recorded yet.</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentMovements as $movement)
                                <tr>
                                    <td class="nowrap">{{ $movement->date->format('M d') }}</td>
                                    <td>
                                        {{ $movement->type_label }}
                                        @if ($movement->account_label)
                                            <small class="hint block">Into {{ $movement->account_label }}</small>
                                        @endif
                                    </td>
                                    <td class="num">@peso($movement->amount)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endif

@endsection

@if ($budget)
    @section('print')
        @include('dashboard._print')
    @endsection
@endif
