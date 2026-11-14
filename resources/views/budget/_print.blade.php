{{-- Printed report: Budget & Allocation --}}
@php
    use App\Support\PrintCharts as C;
    use App\Support\PrintReport as P;

    $overCount = collect($printRows)->where('type', 'item')->where('over', true)->count();
@endphp

@include('print._title', [
    'title'    => 'Budget & Allocation',
    'subtitle' => $budget->display_name . ($budget->is_supplemental ? ' (supplemental)' : '') . ($budget->carriedFrom ? ' · carried over from ' . $budget->carriedFrom->display_name : ''),
])

<div class="pr-kpis k5">
    <div class="pr-kpi is-total"><span>Total budget</span><strong>{{ P::peso($total) }}</strong></div>
    <div class="pr-kpi is-alloc"><span>Allocated</span><strong>{{ P::peso($allocated) }}</strong><small>{{ $allocPct }}% of total</small></div>
    <div class="pr-kpi is-unalloc"><span>Unallocated</span><strong>{{ P::peso($unallocated) }}</strong></div>
    <div class="pr-kpi is-spent"><span>Spent so far</span><strong>{{ P::peso($spent) }}</strong><small>{{ $spentPct }}% of total</small></div>
    <div class="pr-kpi is-left"><span>Budget left</span><strong>{{ P::peso($left) }}</strong><small>{{ $leftPct }}%</small></div>
</div>

<section class="pr-card">
    <h2>Budget at a glance</h2>
    {{ C::split([
        ['label' => 'Spent', 'value' => $spent, 'color' => \App\Support\Usage::color($spentPct)],
        ['label' => 'Allocated, not yet spent', 'value' => max($allocated - $spent, 0), 'color' => '#16a34a'],
        ['label' => 'Unallocated', 'value' => $unallocated, 'color' => \App\Support\Donut::UNALLOCATED_COLOR],
    ]) }}
    <p class="pr-note">Opening balances: bank {{ P::peso($budget->opening_bank_balance) }} · cash on hand {{ P::peso($budget->opening_cash_balance) }}.</p>
</section>

<div class="pr-pair">
    <section class="pr-card">
        <h2>Allocation by category</h2>
        {{ C::donut($overallSegments, $allocPct . '%', 'allocated') }}
    </section>
    <section class="pr-card">
        <h2>Spending by category</h2>
        {{ C::donut($spentSegments, $spentPct . '%', 'spent') }}
    </section>
</div>

<section class="pr-card is-flow">
    <h2>Allocation by category and item
        @if ($overCount > 0)<small>{{ $overCount }} {{ \Illuminate\Support\Str::plural('item', $overCount) }} over the allocation</small>@endif
    </h2>
    @if (count($printRows) === 0)
        <p class="pr-empty">No categories have been added yet.</p>
    @else
        <table class="pr-table">
            <thead>
                <tr><th style="width:38%">Category / item</th><th class="num" style="width:15%">Allocated ₱</th><th class="num" style="width:15%">Spent ₱</th><th class="num" style="width:15%">Balance ₱</th><th style="width:17%">Used</th></tr>
            </thead>
            <tbody>
                @foreach ($printRows as $row)
                    <tr class="{{ $row['type'] === 'category' ? 'is-cat' : '' }}">
                        <td class="indent-{{ min($row['depth'], 4) }}">
                            @if ($row['code'])<small style="display:inline">{{ $row['code'] }}</small> @endif{{ $row['name'] }}
                            @if ($row['type'] === 'item' && $row['over'])<span class="pr-flag">Over</span>@endif
                        </td>
                        <td class="num">{{ P::num($row['allocated']) }}</td>
                        <td class="num">{{ P::num($row['spent']) }}</td>
                        <td class="num {{ $row['balance'] < 0 ? 'over' : '' }}">{{ P::num($row['balance']) }}</td>
                        <td>{{ C::meter($row['percent']) }}<small class="lvl-{{ $row['level'] }}">{{ $row['percent'] }}%</small></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="is-total">
                    <td>Total</td>
                    <td class="num">{{ P::num($allocated) }}</td>
                    <td class="num">{{ P::num($spent) }}</td>
                    <td class="num">{{ P::num($allocated - $spent) }}</td>
                    <td>{{ P::percent($spent, $allocated) }}%</td>
                </tr>
            </tfoot>
        </table>
        <p class="pr-note">Category rows include everything inside them (nested categories and items); the total counts every item once.</p>
    @endif
</section>

@include('print._sign')
