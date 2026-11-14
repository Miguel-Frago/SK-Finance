{{-- Printed report: Dashboard (budget overview) --}}
@php
    use App\Support\PrintCharts as C;
    use App\Support\PrintReport as P;

    $peak = collect($months)->sortByDesc('total')->first();
    $bankShare = [
        ['label' => 'Paid from bank', 'value' => $bankSpent, 'color' => '#0284c7'],
        ['label' => 'Paid from cash', 'value' => $cashSpent, 'color' => '#65a30d'],
    ];
@endphp

@include('print._title', ['title' => 'Budget Overview', 'subtitle' => $budget->display_name . ' · where the money is, how much is spent and what happened recently'])

<div class="pr-kpis">
    <div class="pr-kpi is-total"><span>Total budget</span><strong>{{ P::peso($budget->total_budget) }}</strong></div>
    <div class="pr-kpi is-spent"><span>Spent so far</span><strong>{{ P::peso($spent) }}</strong><small>{{ $spentPct }}% of the budget</small></div>
    <div class="pr-kpi is-left"><span>Budget left</span><strong>{{ P::peso($left) }}</strong><small>{{ round(100 - $spentPct, 1) }}% still available</small></div>
    <div class="pr-kpi is-bank"><span>Bank balance</span><strong>{{ P::peso($budget->bank_balance) }}</strong></div>
    <div class="pr-kpi is-cash"><span>Cash on hand</span><strong>{{ P::peso($budget->cash_balance) }}</strong></div>
    <div class="pr-kpi is-neutral"><span>Total funds on hand</span><strong>{{ P::peso($budget->total_funds_on_hand) }}</strong></div>
</div>

<section class="pr-card">
    <h2>Budget used</h2>
    {{ C::split([
        ['label' => 'Spent', 'value' => $spent, 'color' => \App\Support\Usage::color($spentPct)],
        ['label' => 'Left', 'value' => $left, 'color' => \App\Support\Donut::LEFT_COLOR],
    ]) }}
    <p class="pr-note">Paid from bank {{ P::peso($bankSpent) }} · paid from cash {{ P::peso($cashSpent) }}</p>
</section>

<div class="pr-pair">
    <section class="pr-card">
        <h2>Spending by month <small>{{ $budget->year }}</small></h2>
        {{ C::columns($months) }}
        <p class="pr-note">
            @if ($peak['total'] > 0) Highest month: <b>{{ $peak['name'] }}</b> ({{ P::peso($peak['total']) }}). @else No expenses recorded yet. @endif
        </p>
    </section>
    <section class="pr-card">
        <h2>Spent by category</h2>
        {{ C::donut($categories, $spentPct . '%', 'spent') }}
    </section>
</div>

<section class="pr-card is-flow">
    <h2>Categories <small>allocation against spending</small></h2>
    @if (count($printCategories) === 0)
        <p class="pr-empty">No categories yet.</p>
    @else
        <table class="pr-table">
            <thead>
                <tr><th style="width:36%">Category</th><th class="num" style="width:16%">Allocated ₱</th><th class="num" style="width:16%">Spent ₱</th><th class="num" style="width:16%">Left ₱</th><th style="width:16%">Used</th></tr>
            </thead>
            <tbody>
                @foreach ($printCategories as $row)
                    <tr class="{{ $row['depth'] === 0 ? 'is-cat' : '' }}">
                        <td class="indent-{{ min($row['depth'], 4) }}">{{ $row['name'] }}</td>
                        <td class="num">{{ P::num($row['allocated']) }}</td>
                        <td class="num">{{ P::num($row['spent']) }}</td>
                        <td class="num {{ $row['balance'] < 0 ? 'over' : '' }}">{{ P::num($row['balance']) }}</td>
                        <td>{{ C::meter($row['percent']) }}<small>{{ $row['percent'] }}%</small></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

@if ($nearlyUsed->isNotEmpty())
    <section class="pr-card">
        <h2>Items nearly used up <small>80% or more of the allocation</small></h2>
        {{ C::bars($nearlyUsed->map(fn ($item) => [
            'label'   => $item->display_name,
            'value'   => min(100, $item->utilization_percent),
            'display' => $item->utilization_percent . '%  (left ' . P::peso($item->balance) . ')',
            'color'   => \App\Support\Usage::color($item->utilization_percent),
        ])->all(), 100) }}
    </section>
@endif

<div class="pr-pair">
    <section class="pr-card is-flow">
        <h2>Recent expenses</h2>
        @if ($recentExpenses->isEmpty())
            <p class="pr-empty">No expenses recorded yet.</p>
        @else
            <table class="pr-table">
                <thead><tr><th style="width:17%">Date</th><th>Description</th><th class="num" style="width:26%">₱</th></tr></thead>
                <tbody>
                    @foreach ($recentExpenses as $expense)
                        <tr>
                            <td>{{ $expense->date->format('M d') }}</td>
                            <td>{{ $expense->description }}<small>{{ $expense->subcategory->display_name }} · {{ $expense->payment_method_label }}</small></td>
                            <td class="num">{{ P::num($expense->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
    <section class="pr-card is-flow">
        <h2>Recent fund movements</h2>
        @if ($recentMovements->isEmpty())
            <p class="pr-empty">No fund movements recorded yet.</p>
        @else
            <table class="pr-table">
                <thead><tr><th style="width:17%">Date</th><th>Type</th><th class="num" style="width:26%">₱</th></tr></thead>
                <tbody>
                    @foreach ($recentMovements as $movement)
                        <tr>
                            <td>{{ $movement->date->format('M d') }}</td>
                            <td>{{ $movement->type_label }}@if ($movement->account_label)<small>Into {{ $movement->account_label }}</small>@endif</td>
                            <td class="num">{{ P::num($movement->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>

@include('print._sign')
