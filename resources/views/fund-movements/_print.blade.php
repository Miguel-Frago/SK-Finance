{{-- Printed report: Fund Movements (every row that matches the filters) --}}
@php
    use App\Support\PrintCharts as C;
    use App\Support\PrintReport as P;

    $count = $printRows->count();
    $flow  = [
        ['label' => 'Funds received', 'value' => $totals->received, 'color' => '#16a34a'],
        ['label' => 'Withdrawn from bank (to cash)', 'value' => $totals->withdrawn, 'color' => '#f97316'],
        ['label' => 'Deposited to bank (from cash)', 'value' => $totals->deposited, 'color' => '#0284c7'],
    ];
    $months = P::monthly($printRows);
@endphp

@include('print._title', [
    'title'    => 'Fund Movements Report',
    'subtitle' => $budget->display_name . ' · money received, withdrawn from the bank into cash, and deposited back',
    'filters'  => $chips,
])

<div class="pr-kpis k4">
    <div class="pr-kpi is-bank"><span>Bank balance</span><strong>{{ P::peso($balances['bank']) }}</strong></div>
    <div class="pr-kpi is-cash"><span>Cash on hand</span><strong>{{ P::peso($balances['cash']) }}</strong></div>
    <div class="pr-kpi is-neutral"><span>Total funds on hand</span><strong>{{ P::peso($balances['total']) }}</strong></div>
    <div class="pr-kpi is-left"><span>Budget not yet spent</span><strong>{{ P::peso($balances['unspent']) }}</strong></div>
</div>

@if ($count === 0)
    <section class="pr-card"><p class="pr-empty">No fund movements match these filters.</p></section>
@else
    <div class="pr-pair">
        <section class="pr-card">
            <h2>{{ $hasFilters ? 'Matching movements' : 'All movements' }} <small>{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('row', $count) }}</small></h2>
            {{ C::bars(array_map(fn ($part) => $part + ['display' => P::peso($part['value'])], $flow)) }}
            <p class="pr-note">Withdrawals and deposits are transfers between the bank and cash on hand, not expenses.</p>
        </section>
        <section class="pr-card">
            <h2>Movements by month</h2>
            {{ C::columns($months, '#16a34a') }}
        </section>
    </div>

    <section class="pr-card is-flow">
        <h2>List of fund movements</h2>
        <table class="pr-table">
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th style="width:13%">Date</th>
                    <th style="width:25%">Type</th>
                    <th style="width:12%">Account</th>
                    <th style="width:29%">Reference / remarks</th>
                    <th class="num" style="width:16%">Amount ₱</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($printRows as $movement)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $movement->date->format('M d, Y') }}</td>
                        <td>{{ $movement->type_label }}</td>
                        <td>{{ $movement->account_label ?: '—' }}</td>
                        <td>{{ $movement->reference ?: '—' }}@if ($movement->remarks)<small>{{ $movement->remarks }}</small>@endif</td>
                        <td class="num">{{ P::num($movement->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="is-total"><td colspan="3">Funds received</td><td colspan="2"></td><td class="num">{{ P::num($totals->received) }}</td></tr>
                <tr class="is-total"><td colspan="3">Withdrawn from bank</td><td colspan="2"></td><td class="num">{{ P::num($totals->withdrawn) }}</td></tr>
                <tr class="is-total"><td colspan="3">Deposited to bank</td><td colspan="2"></td><td class="num">{{ P::num($totals->deposited) }}</td></tr>
            </tfoot>
        </table>
    </section>
@endif

@include('print._sign')
