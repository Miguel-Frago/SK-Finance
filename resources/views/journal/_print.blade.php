{{-- Printed report: Journal (every entry that matches the filters) --}}
@php
    use App\Support\PrintCharts as C;
    use App\Support\PrintReport as P;

    $count        = $printEntries->count();
    $chrono       = $newestFirst ? $printEntries->reverse()->values() : $printEntries->values();
    $printOpening = ! $hasFilters && ! $newestFirst;

    // money moved per kind of entry (what came in plus what went out of the two accounts)
    $bySource = [];
    foreach (\App\Support\JournalBook::SOURCES as $key => $label) {
        $rows = $printEntries->where('source', $key);
        $bySource[] = [
            'label'   => $label,
            'value'   => $rows->sum(fn ($r) => abs($r['bank']) + abs($r['cash'])) / ($key === 'withdrawal' || $key === 'deposit' ? 2 : 1),
            'display' => $rows->count() . ' ' . \Illuminate\Support\Str::plural('entry', $rows->count()),
            'color'   => ['expense' => '#dc2626', 'receipt' => '#16a34a', 'withdrawal' => '#f97316', 'deposit' => '#0284c7'][$key],
        ];
    }
    foreach ($bySource as $i => $row) { $bySource[$i]['note'] = P::peso($row['value']); }

    $first = $chrono->first();
    $last  = $chrono->last();
@endphp

@include('print._title', [
    'title'    => 'Journal',
    'subtitle' => $budget->display_name . ' · every expense and fund movement with the bank and cash balance after each entry (debit = money in, credit = money out)',
    'filters'  => $chips,
])

<div class="pr-kpis k4">
    <div class="pr-kpi is-bank"><span>Opening bank</span><strong>{{ P::peso($budget->opening_bank_balance) }}</strong></div>
    <div class="pr-kpi is-cash"><span>Opening cash on hand</span><strong>{{ P::peso($budget->opening_cash_balance) }}</strong></div>
    <div class="pr-kpi is-bank"><span>Bank balance now</span><strong>{{ P::peso($budget->bank_balance) }}</strong></div>
    <div class="pr-kpi is-cash"><span>Cash on hand now</span><strong>{{ P::peso($budget->cash_balance) }}</strong></div>
</div>
<div class="pr-kpis k4">
    <div class="pr-kpi is-alloc"><span>Bank debit (in)</span><strong>{{ P::peso($totals['bank_in']) }}</strong></div>
    <div class="pr-kpi is-spent"><span>Bank credit (out)</span><strong>{{ P::peso($totals['bank_out']) }}</strong></div>
    <div class="pr-kpi is-alloc"><span>Cash debit (in)</span><strong>{{ P::peso($totals['cash_in']) }}</strong></div>
    <div class="pr-kpi is-spent"><span>Cash credit (out)</span><strong>{{ P::peso($totals['cash_out']) }}</strong></div>
</div>

@if ($count === 0)
    <section class="pr-card"><p class="pr-empty">{{ $hasFilters ? 'No journal entries match these filters.' : 'No entries yet.' }}</p></section>
@else
    <div class="pr-pair">
        <section class="pr-card">
            <h2>Balance after each entry</h2>
            {{ C::lines([
                ['label' => 'Bank', 'color' => '#0284c7', 'values' => $chrono->pluck('bank_balance')->all()],
                ['label' => 'Cash on hand', 'color' => '#65a30d', 'values' => $chrono->pluck('cash_balance')->all()],
            ], [$first['date_label'] ?? '', $last['date_label'] ?? '']) }}
        </section>
        <section class="pr-card">
            <h2>Kinds of entries <small>{{ number_format($count) }} in all</small></h2>
            {{ C::bars($bySource, null) }}
            <p class="pr-note">Bars show the amount of each kind; transfers are counted once.</p>
        </section>
    </div>

    <section class="pr-card is-flow">
        <h2>Journal entries</h2>
        <table class="pr-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width:11%">Date</th>
                    <th rowspan="2" style="width:23%">Particulars</th>
                    <th colspan="3" class="ctr">Bank ₱</th>
                    <th colspan="3" class="ctr">Cash on hand ₱</th>
                </tr>
                <tr>
                    <th class="num" style="width:11%">Debit</th><th class="num" style="width:11%">Credit</th><th class="num" style="width:11%">Balance</th>
                    <th class="num" style="width:11%">Debit</th><th class="num" style="width:11%">Credit</th><th class="num" style="width:11%">Balance</th>
                </tr>
            </thead>
            <tbody>
                @if ($printOpening)
                    <tr class="is-open">
                        <td></td><td>Opening balances</td>
                        <td></td><td></td><td class="num">{{ P::num($budget->opening_bank_balance) }}</td>
                        <td></td><td></td><td class="num">{{ P::num($budget->opening_cash_balance) }}</td>
                    </tr>
                @endif
                @foreach ($printEntries as $row)
                    <tr>
                        <td>{{ $row['date_label'] }}</td>
                        <td>
                            {{ $row['particulars'] }}
                            <small>{{ $row['source_label'] }}@if ($row['reference']) · {{ $row['reference'] }}@endif </small>
                        </td>
                        <td class="num">{{ $row['bank'] > 0 ? P::num($row['bank']) : '' }}</td>
                        <td class="num">{{ $row['bank'] < 0 ? P::num(-$row['bank']) : '' }}</td>
                        <td class="num">{{ P::num($row['bank_balance']) }}</td>
                        <td class="num">{{ $row['cash'] > 0 ? P::num($row['cash']) : '' }}</td>
                        <td class="num">{{ $row['cash'] < 0 ? P::num(-$row['cash']) : '' }}</td>
                        <td class="num">{{ P::num($row['cash_balance']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="is-total">
                    <td colspan="2">Totals of {{ number_format($count) }} {{ \Illuminate\Support\Str::plural('entry', $count) }}</td>
                    <td class="num">{{ P::num($totals['bank_in']) }}</td><td class="num">{{ P::num($totals['bank_out']) }}</td><td></td>
                    <td class="num">{{ P::num($totals['cash_in']) }}</td><td class="num">{{ P::num($totals['cash_out']) }}</td><td></td>
                </tr>
            </tfoot>
        </table>
    </section>
@endif

@include('print._sign')
