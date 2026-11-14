{{-- Printed report: Ledger (one item with its running balance, or a summary of all items / one category) --}}
@php
    use App\Support\PrintCharts as C;
    use App\Support\PrintReport as P;

    $filterLines = [];
    if (! empty($filters['date_from']))      $filterLines[] = ['text' => 'From ' . \Carbon\Carbon::parse($filters['date_from'])->format('M d, Y')];
    if (! empty($filters['date_to']))        $filterLines[] = ['text' => 'To ' . \Carbon\Carbon::parse($filters['date_to'])->format('M d, Y')];
    if (! empty($filters['payment_method'])) $filterLines[] = ['text' => 'Paid from: ' . (\App\Models\Transaction::PAYMENT_METHODS[$filters['payment_method']] ?? $filters['payment_method'])];
    if (! empty($filters['search']))         $filterLines[] = ['text' => 'Search: ' . $filters['search']];
@endphp

@if ($item)
    {{-- ================= ONE ITEM ================= --}}
    @php
        $count     = $printRows->count();
        $overBy    = abs($item->balance);
        $level     = $item->is_overspent ? 'high' : \App\Support\Usage::level($item->utilization_percent);
        $bank      = $printRows->where('payment_method', 'bank')->sum(fn ($e) => (float) $e->amount);
        $cash      = $printRows->where('payment_method', 'cash_on_hand')->sum(fn ($e) => (float) $e->amount);
        $showOpen  = ! $hasFilters;
        // balance after each expense, starting from the allocation
        $balances  = array_merge([(float) $item->allocated_amount], $printRows->pluck('running_balance')->map(fn ($v) => (float) $v)->all());
        $unfiltered = ! $hasFilters;
    @endphp

    @include('print._title', [
        'title'    => 'Item Ledger' . ($item->code ? ' · ' . $item->code : ''),
        'subtitle' => $item->name . ' · ' . ($paths[$item->category_id] ?? '') . ' · ' . $budget->display_name,
        'filters'  => $filterLines,
    ])

    <div class="pr-kpis k4">
        <div class="pr-kpi is-alloc"><span>Allocated</span><strong>{{ P::peso($item->allocated_amount) }}</strong></div>
        <div class="pr-kpi is-spent"><span>Spent</span><strong>{{ P::peso($item->spent) }}</strong></div>
        <div class="pr-kpi is-left {{ $item->is_overspent ? 'is-over' : '' }}"><span>{{ $item->is_overspent ? 'Overspent by' : 'Balance' }}</span><strong>{{ P::peso($item->is_overspent ? $overBy : $item->balance) }}</strong></div>
        <div class="pr-kpi is-neutral"><span>Used</span><strong class="lvl-{{ $level }}">{{ $item->utilization_percent }}%</strong><small>{{ $entryCount }} {{ \Illuminate\Support\Str::plural('expense', $entryCount) }} in all</small></div>
    </div>

    <div class="pr-pair">
        <section class="pr-card">
            <h2>How much of the allocation is used</h2>
            {{ C::split([
                ['label' => 'Spent', 'value' => min($item->spent, (float) $item->allocated_amount) ?: $item->spent, 'color' => \App\Support\Usage::color($item->is_overspent ? 100 : $item->utilization_percent)],
                ['label' => 'Left', 'value' => max($item->balance, 0), 'color' => \App\Support\Donut::LEFT_COLOR],
            ]) }}
            @if ($item->is_overspent)<p class="pr-note over"><b>Overspent</b> by {{ P::peso($overBy) }}.</p>@endif
            <p class="pr-note">Paid from bank {{ P::peso($bank) }} · paid from cash {{ P::peso($cash) }}@if ($hasFilters) (matching expenses only)@endif</p>
        </section>
        <section class="pr-card">
            <h2>Balance after each expense</h2>
            {{ C::lines([['label' => 'Balance left', 'color' => '#f97316', 'values' => $balances]], ['Allocation', 'Latest']) }}
        </section>
    </div>

    <section class="pr-card is-flow">
        <h2>Ledger entries</h2>
        @if ($hasFilters)<p class="pr-note" style="margin:0 0 4px">Filtered view: the balance column still shows the true balance of the item after each expense.</p>@endif
        @if ($printRows->isEmpty() && $hasFilters)
            <p class="pr-empty">No expenses match these filters.</p>
        @else
            <table class="pr-table">
                <thead>
                    <tr>
                        <th style="width:12%">Date</th><th style="width:13%">Voucher / check</th><th style="width:18%">Payee</th><th style="width:25%">Description</th><th style="width:8%">Paid</th><th class="num" style="width:12%">Amount ₱</th><th class="num" style="width:12%">Balance ₱</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($showOpen)
                        <tr class="is-open"><td colspan="3"></td><td>Allocation (opening balance)</td><td colspan="2"></td><td class="num">{{ P::num($item->allocated_amount) }}</td></tr>
                    @endif
                    @foreach ($printRows as $entry)
                        @php $over = $entry->running_balance < 0; @endphp
                        <tr>
                            <td>{{ $entry->date->format('M d, Y') }}</td>
                            <td>{{ $entry->voucher_number ?: '—' }}@if ($entry->check_number)<small>Check {{ $entry->check_number }}</small>@endif</td>
                            <td>{{ $entry->payee ?: '—' }}</td>
                            <td>{{ $entry->description }}</td>
                            <td>{{ $entry->payment_method === 'bank' ? 'Bank' : 'Cash' }}</td>
                            <td class="num">{{ P::num($entry->amount) }}</td>
                            <td class="num {{ $over ? 'over' : '' }}">{{ P::num($entry->running_balance) }}@if ($over)<small class="over">Overspent</small>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="is-total"><td colspan="5">{{ $hasFilters ? 'Total of matching expenses' : 'Total spent' }}</td><td class="num">{{ P::num($totals['spent']) }}</td><td></td></tr>
                    <tr class="is-total"><td colspan="5">Remaining balance of the item</td><td></td><td class="num {{ $item->is_overspent ? 'over' : '' }}">{{ P::num($item->balance) }}</td></tr>
                </tfoot>
            </table>
        @endif
    </section>
@else
    {{-- ================= SUMMARY OF ITEMS ================= --}}
    @php
        $topUsed = $printItems->filter(fn ($row) => (float) $row->allocated_amount > 0 || $row->spent > 0)
            ->sortByDesc(fn ($row) => (float) $row->allocated_amount > 0 ? $row->utilization_percent : 999)->take(8)->values();
        $lastCat = null;
    @endphp

    @include('print._title', [
        'title'    => 'Ledger' . ($category ? ' · ' . ($paths[$category->id] ?? $category->name) : ' · All items'),
        'subtitle' => $budget->display_name . ' · allocation, spending and balance of every budget item',
        'filters'  => [],
    ])

    <div class="pr-kpis k5">
        <div class="pr-kpi is-neutral"><span>Items</span><strong>{{ number_format($summary['items']) }}</strong></div>
        <div class="pr-kpi is-alloc"><span>Allocated</span><strong>{{ P::peso($summary['allocated']) }}</strong></div>
        <div class="pr-kpi is-spent"><span>Spent</span><strong>{{ P::peso($summary['spent']) }}</strong></div>
        <div class="pr-kpi is-left {{ $summary['balance'] < 0 ? 'is-over' : '' }}"><span>Balance</span><strong>{{ P::peso($summary['balance']) }}</strong></div>
        <div class="pr-kpi is-neutral"><span>Used</span><strong>{{ $summary['percent'] }}%</strong>@if ($summary['overspent'] > 0)<small class="over">{{ $summary['overspent'] }} over allocation</small>@endif</div>
    </div>

    @if ($printItems->isEmpty())
        <section class="pr-card"><p class="pr-empty">{{ $category ? 'This category has no budget items yet.' : 'No budget items yet.' }}</p></section>
    @else
        <div class="pr-pair">
            <section class="pr-card">
                <h2>Allocated and spent</h2>
                {{ C::split([
                    ['label' => 'Spent', 'value' => min($summary['spent'], $summary['allocated']) ?: $summary['spent'], 'color' => \App\Support\Usage::color($summary['percent'])],
                    ['label' => 'Left', 'value' => max($summary['balance'], 0), 'color' => \App\Support\Donut::LEFT_COLOR],
                ]) }}
                @if ($summary['overspent'] > 0)<p class="pr-note over"><b>{{ $summary['overspent'] }}</b> {{ \Illuminate\Support\Str::plural('item', $summary['overspent']) }} over the allocation (flagged below).</p>@endif
            </section>
            <section class="pr-card">
                <h2>Most used items <small>top {{ $topUsed->count() }}</small></h2>
                {{ C::bars($topUsed->map(fn ($row) => [
                    'label'   => $row->display_name,
                    'value'   => min(100, (float) $row->allocated_amount > 0 ? $row->utilization_percent : 100),
                    'display' => (float) $row->allocated_amount > 0 ? $row->utilization_percent . '%' : 'No allocation',
                    'color'   => ($row->is_overspent) ? '#991b1b' : \App\Support\Usage::color($row->utilization_percent),
                ])->all(), 100) }}
            </section>
        </div>

        <section class="pr-card is-flow">
            <h2>All items</h2>
            <table class="pr-table">
                <thead>
                    <tr><th style="width:34%">Item</th><th class="num" style="width:16%">Allocated ₱</th><th class="num" style="width:16%">Spent ₱</th><th class="num" style="width:16%">Balance ₱</th><th style="width:18%">Used</th></tr>
                </thead>
                <tbody>
                    @foreach ($printItems as $row)
                        @if ($row->category_id !== $lastCat)
                            @php $lastCat = $row->category_id; @endphp
                            <tr class="is-cat"><td colspan="5">{{ $paths[$row->category_id] ?? '—' }}</td></tr>
                        @endif
                        @php
                            $noAllocation = (float) $row->allocated_amount <= 0 && $row->spent > 0;
                            $pct          = $row->utilization_percent;
                        @endphp
                        <tr>
                            <td class="indent-1">{{ $row->display_name }}@if ($row->is_overspent) <span class="pr-flag">Over</span>@endif</td>
                            <td class="num">{{ P::num($row->allocated_amount) }}</td>
                            <td class="num">{{ P::num($row->spent) }}</td>
                            <td class="num {{ $row->is_overspent ? 'over' : '' }}">{{ P::num($row->balance) }}</td>
                            <td>{{ C::meter($noAllocation ? 100 : $pct) }}<small>{{ $noAllocation ? 'No allocation' : $pct . '%' }}</small></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="is-total">
                        <td>Total of {{ number_format($summary['items']) }} {{ \Illuminate\Support\Str::plural('item', $summary['items']) }}</td>
                        <td class="num">{{ P::num($summary['allocated']) }}</td>
                        <td class="num">{{ P::num($summary['spent']) }}</td>
                        <td class="num {{ $summary['balance'] < 0 ? 'over' : '' }}">{{ P::num($summary['balance']) }}</td>
                        <td>{{ $summary['percent'] }}%</td>
                    </tr>
                </tfoot>
            </table>
        </section>
    @endif
@endif

@include('print._sign')
