{{-- Printed report: Transactions (every row that matches the filters) --}}
@php
    use App\Support\PrintCharts as C;
    use App\Support\PrintReport as P;

    $count   = $printRows->count();
    $total   = (float) $totals->total;
    $average = $count > 0 ? $total / $count : 0;
    $largest = $printRows->sortByDesc(fn ($t) => (float) $t->amount)->first();

    // spending per top-level category and per item
    $byCategory = $printRows->groupBy(fn ($t) => explode(' › ', $paths[$t->category_id] ?? $t->category->name)[0])
        ->map(fn ($rows) => $rows->sum(fn ($t) => (float) $t->amount))->sortDesc();
    $byItem = $printRows->groupBy('subcategory_id')
        ->map(fn ($rows) => ['label' => $rows->first()->subcategory->display_name, 'note' => last(explode(' › ', $paths[$rows->first()->category_id] ?? '')), 'value' => $rows->sum(fn ($t) => (float) $t->amount)])
        ->sortByDesc('value')->take(8)->values();

    $palette = \App\Support\Donut::PALETTE;
    $catBars = $byCategory->map(fn ($value, $label) => ['label' => $label, 'value' => $value, 'display' => P::peso($value) . ' · ' . P::percent($value, $total) . '%'])->values()->all();
    foreach ($catBars as $i => $bar) { $catBars[$i]['color'] = $palette[$i % count($palette)]; }

    $months = P::monthly($printRows);
@endphp

@include('print._title', [
    'title'    => 'Transactions Report',
    'subtitle' => $budget->display_name . ' · expenses sorted by ' . ($sortOptions[$sort] ?? 'Date') . ($dir === 'asc' ? ' (ascending)' : ' (descending)'),
    'filters'  => $chips,
])

<div class="pr-kpis k4">
    <div class="pr-kpi is-neutral"><span>{{ $hasFilters ? 'Matching transactions' : 'Transactions' }}</span><strong>{{ number_format($count) }}</strong></div>
    <div class="pr-kpi is-spent"><span>{{ $hasFilters ? 'Filtered total' : 'Total spent' }}</span><strong>{{ P::peso($total) }}</strong></div>
    <div class="pr-kpi is-bank"><span>Paid from bank</span><strong>{{ P::peso($totals->bank_total) }}</strong></div>
    <div class="pr-kpi is-cash"><span>Paid from cash</span><strong>{{ P::peso($totals->cash_total) }}</strong></div>
</div>

@if ($count === 0)
    <section class="pr-card"><p class="pr-empty">No transactions match these filters.</p></section>
@else
    <div class="pr-pair">
        <section class="pr-card">
            <h2>How it was paid</h2>
            {{ C::split([
                ['label' => 'Bank', 'value' => $totals->bank_total, 'color' => '#0284c7'],
                ['label' => 'Cash', 'value' => $totals->cash_total, 'color' => '#65a30d'],
            ]) }}
            <p class="pr-note">Average expense {{ P::peso($average) }}@if ($largest) · largest {{ P::peso($largest->amount) }} ({{ $largest->description }})@endif</p>
        </section>
        <section class="pr-card">
            <h2>Spending by month</h2>
            {{ C::columns($months) }}
        </section>
    </div>

    <div class="pr-pair">
        <section class="pr-card">
            <h2>By category</h2>
            {{ C::bars($catBars) }}
        </section>
        <section class="pr-card">
            <h2>Biggest items <small>top {{ $byItem->count() }}</small></h2>
            {{ C::bars($byItem->all(), null, '#7c3aed') }}
        </section>
    </div>

    <section class="pr-card is-flow">
        <h2>List of transactions <small>{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('row', $count) }}</small></h2>
        <table class="pr-table">
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th style="width:11%">Date</th>
                    <th style="width:33%">Description</th>
                    <th style="width:26%">Item</th>
                    <th style="width:9%">Paid from</th>
                    <th class="num" style="width:16%">Amount ₱</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($printRows as $transaction)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $transaction->date->format('M d, Y') }}</td>
                        <td>
                            {{ $transaction->description }}
                            <small>{{ $transaction->payee ?: 'No payee' }}@if ($transaction->voucher_number) · Voucher {{ $transaction->voucher_number }}@endif @if ($transaction->check_number) · Check {{ $transaction->check_number }}@endif @if ($transaction->attachments->isNotEmpty()) · {{ $transaction->attachments->count() }} {{ \Illuminate\Support\Str::plural('receipt', $transaction->attachments->count()) }} attached @endif</small>
                        </td>
                        <td>{{ $transaction->subcategory->display_name }}<small>{{ $paths[$transaction->category_id] ?? $transaction->category->name }}</small></td>
                        <td>{{ $transaction->payment_method === 'bank' ? 'Bank' : 'Cash' }}</td>
                        <td class="num">{{ P::num($transaction->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="is-total"><td colspan="5">Total of {{ number_format($count) }} {{ \Illuminate\Support\Str::plural('transaction', $count) }}</td><td class="num">{{ P::num($total) }}</td></tr>
            </tfoot>
        </table>
        <p class="pr-note">Receipts and proof of payment are printed with the Report Generation page, on the last pages.</p>
    </section>
@endif

@include('print._sign')
