@extends('layouts.app')

@section('title', 'Ledger')

@section('content')

@if (! $budget)
    {{-- ============ No budget yet ============ --}}
    <section class="card card-narrow">
        <h2 class="card-title">No budget yet</h2>
        <p class="muted">Create a budget first. The ledger shows how each of its items is being spent.</p>
        <a href="{{ route('budget.index') }}" class="btn btn-primary">Go to Budget &amp; Allocation</a>
    </section>
@else
    @php
        $hasFilters = collect($filters)
            ->only(['date_from', 'date_to', 'payment_method', 'search'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->isNotEmpty();
    @endphp

    {{-- ============ Header ============ --}}
    <div class="page-head">
        <div>
            <h2 class="section-title">Ledger of {{ $budget->display_name }}</h2>
            <p class="muted">
                One ledger per budget item: the allocation first, then each expense with the balance left after it.
                Entries are recorded in <a href="{{ route('transactions.index') }}">Transactions</a>.
            </p>
        </div>
        <div class="btn-row">
            @include('partials.print-button', ['all' => true])
        </div>
    </div>

    {{-- ============ Pick an item or a category ============ --}}
    <section class="card no-print">
        <form method="GET" action="{{ route('ledger.index') }}" class="form">
            <div class="filter-grid">
                <div class="form-group">
                    <label for="l_item">Budget item</label>
                    <select id="l_item" name="subcategory_id"
                            onchange="if (this.value) { document.getElementById('l_category').value = ''; }">
                        <option value="">All items (summary)</option>
                        @foreach ($paths as $categoryId => $path)
                            @php $options = $itemsByCategory->get($categoryId); @endphp
                            @if ($options)
                                <optgroup label="{{ $path }}">
                                    @foreach ($options as $option)
                                        <option value="{{ $option->id }}" @selected((int) ($filters['subcategory_id'] ?? 0) === (int) $option->id)>{{ $option->display_name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="l_category">Category (includes nested)</label>
                    <select id="l_category" name="category_id"
                            onchange="if (this.value) { document.getElementById('l_item').value = ''; }">
                        <option value="">No category</option>
                        @foreach ($paths as $categoryId => $path)
                            <option value="{{ $categoryId }}" @selected((int) ($filters['category_id'] ?? 0) === (int) $categoryId && ! $item)>{{ $path }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary btn-small">Show ledger</button>
                @if ($item || $category)
                    <a href="{{ route('ledger.index') }}" class="btn btn-ghost btn-small">All items</a>
                @endif
            </div>
        </form>
    </section>

    @if ($item)
        {{-- ======================================================
             ITEM LEDGER
             ====================================================== --}}
        @php
            $overBy    = abs($item->balance);
            // low = yellow, medium = orange, high (or overspent) = red
            $usedLevel = $item->is_overspent ? 'high' : \App\Support\Usage::level($item->utilization_percent);
            $barLevel  = $item->is_overspent ? 'over' : $usedLevel;
            $barWidth  = min(100, (float) $item->utilization_percent);
        @endphp

        <div class="page-head">
            <div>
                <h3 class="section-title">
                    @if ($item->code)<span class="code-tag">{{ $item->code }}</span>@endif
                    {{ $item->name }}
                    @if ($item->is_overspent)<span class="badge badge-over">Overspent</span>@endif
                </h3>
                <p class="muted">{{ $paths[$item->category_id] ?? '' }}</p>
            </div>
            <div class="btn-row">
                <a href="{{ route('transactions.index', ['subcategory_id' => $item->id]) }}" class="btn btn-secondary btn-small">View in Transactions</a>
            </div>
        </div>

        @if ($item->is_overspent)
            <div class="alert alert-error">
                <strong>Overspent.</strong>
                Spending on this item is @peso($overBy) more than its allocation of @peso($item->allocated_amount).
            </div>
        @endif

        {{-- ============ Cards ============ --}}
        <div class="metric-grid">
            <div class="metric-card metric-allocated">
                <span class="metric-label">Allocated</span>
                <span class="metric-value">@peso($item->allocated_amount)</span>
            </div>
            <div class="metric-card metric-spent">
                <span class="metric-label">Spent</span>
                <span class="metric-value">@peso($item->spent)</span>
            </div>
            <div class="metric-card metric-left {{ $item->is_overspent ? 'is-over' : '' }}">
                <span class="metric-label">{{ $item->is_overspent ? 'Overspent by' : 'Balance' }}</span>
                @if ($item->is_overspent)
                    <span class="metric-value neg">@peso($overBy)</span>
                @else
                    <span class="metric-value">@peso($item->balance)</span>
                @endif
            </div>
            <div class="metric-card metric-used-{{ $usedLevel }}">
                <span class="metric-label">Used</span>
                <span class="metric-value">{{ $item->utilization_percent }}%</span>
            </div>
        </div>

        {{-- ============ How much of the allocation is used ============ --}}
        <section class="card">
            <h4 class="mini-title">Allocation used</h4>
            <div class="pool-bar pool-bar-spent" role="img" aria-label="{{ $item->utilization_percent }}% of this item's allocation is used">
                <span class="pool-allocated fill-{{ $barLevel }}" style="width: {{ $barWidth }}%"></span>
            </div>
            <div class="pool-legend">
                <span><i class="dot fill-{{ $barLevel }}"></i> Spent @peso($item->spent) ({{ $item->utilization_percent }}%)</span>
                <span><i class="dot dot-left"></i> {{ $item->is_overspent ? 'Over the allocation' : 'Left' }} @peso($overBy)</span>
            </div>
        </section>

        {{-- ============ Filters ============ --}}
        <section class="card no-print">
            <form method="GET" action="{{ route('ledger.index') }}" class="form">
                <input type="hidden" name="subcategory_id" value="{{ $item->id }}">
                <div class="filter-grid">
                    <div class="form-group">
                        <label for="f_from">Date from</label>
                        <input id="f_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="form-group">
                        <label for="f_to">Date to</label>
                        <input id="f_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="form-group">
                        <label for="f_method">Payment method</label>
                        <select id="f_method" name="payment_method">
                            <option value="">Bank and cash</option>
                            @foreach (\App\Models\Transaction::PAYMENT_METHODS as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['payment_method'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="f_search">Search</label>
                        <input id="f_search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Description, payee, voucher or check no.">
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary btn-small">Apply filters</button>
                    @if ($hasFilters)
                        <a href="{{ route('ledger.index', ['subcategory_id' => $item->id]) }}" class="btn btn-ghost btn-small">Reset</a>
                    @endif
                </div>
            </form>
        </section>

        {{-- ============ Ledger table ============ --}}
        <section class="card">
            @if ($hasFilters)
                <p class="hint">Filtered view: the balance column still shows the true balance of the item after each expense.</p>
            @endif

            @if ($rows->isEmpty() && $hasFilters)
                <p class="empty">No expenses match these filters.</p>
            @else
                @php
                    $showOpening = ! $hasFilters && $rows->onFirstPage();
                @endphp

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Voucher / Check no.</th>
                                <th>Payee</th>
                                <th>Description</th>
                                <th>Paid from</th>
                                <th class="num">Amount</th>
                                <th class="num">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if ($showOpening)
                                <tr class="row-opening">
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td><strong>Allocation (opening balance)</strong></td>
                                    <td></td>
                                    <td></td>
                                    <td class="num c-allocated"><strong>@peso($item->allocated_amount)</strong></td>
                                </tr>
                            @endif

                            @foreach ($rows as $entry)
                                @php $over = $entry->running_balance < 0; @endphp
                                <tr class="{{ $over ? 'row-over' : '' }}">
                                    <td class="nowrap">{{ $entry->date->format('M d, Y') }}</td>
                                    <td class="nowrap">
                                        {{ $entry->voucher_number ?: '—' }}
                                        @if ($entry->check_number)
                                            <small class="hint block">Check {{ $entry->check_number }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $entry->payee ?: '—' }}</td>
                                    <td>@can('manage')<a href="{{ route('transactions.edit', $entry) }}">{{ $entry->description }}</a>@else{{ $entry->description }}@endcan</td>
                                    <td>
                                        <span class="badge {{ $entry->payment_method === 'bank' ? 'badge-bank' : 'badge-cash' }}">{{ $entry->payment_method_label }}</span>
                                    </td>
                                    <td class="num c-spent">@peso($entry->amount)</td>
                                    <td class="num c-left {{ $over ? 'neg' : '' }}">
                                        @peso($entry->running_balance)
                                        @if ($over)
                                            <small class="block"><span class="badge badge-over">Overspent</span></small>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5">{{ $hasFilters ? 'Total of matching expenses (all pages)' : 'Total spent (all pages)' }}</td>
                                <td class="num c-spent">@peso($totals['spent'])</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="5">{{ $hasFilters ? 'Remaining balance of the item (all expenses)' : 'Remaining balance' }}</td>
                                <td></td>
                                <td class="num c-left {{ $item->is_overspent ? 'neg' : '' }}">
                                    @peso($item->balance)
                                    @if ($item->is_overspent)
                                        <small class="block"><span class="badge badge-over">Overspent</span></small>
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if ($entryCount === 0)
                    <p class="empty">No expenses recorded against this item yet. Record them in Transactions.</p>
                @elseif ($rows->count() > 0)
                    <div class="list-footer">
                        <small class="hint">Showing {{ $rows->firstItem() }}&ndash;{{ $rows->lastItem() }} of {{ $rows->total() }}</small>
                        {{ $rows->links('partials.pagination') }}
                    </div>
                @endif
            @endif
        </section>
    @else
        {{-- ======================================================
             SUMMARY OF ITEMS (all items, or one category with its nested categories)
             ====================================================== --}}
        @if ($category)
            <div class="page-head">
                <div>
                    <h3 class="section-title">{{ $paths[$category->id] ?? $category->name }}</h3>
                    <p class="muted">Items in this category and in the categories nested inside it.</p>
                </div>
            </div>
        @endif

        @if ($summary['overspent'] > 0)
            <div class="alert alert-error">
                <strong>Overspent:</strong>
                {{ $summary['overspent'] }} {{ \Illuminate\Support\Str::plural('item', $summary['overspent']) }}
                {{ $summary['overspent'] === 1 ? 'is' : 'are' }} over {{ $summary['overspent'] === 1 ? 'its' : 'their' }} allocation. They are flagged below.
            </div>
        @endif

        <div class="metric-grid">
            <div class="metric-card metric-neutral">
                <span class="metric-label">Items</span>
                <span class="metric-value">{{ number_format($summary['items']) }}</span>
            </div>
            <div class="metric-card metric-allocated">
                <span class="metric-label">Allocated</span>
                <span class="metric-value">@peso($summary['allocated'])</span>
            </div>
            <div class="metric-card metric-spent">
                <span class="metric-label">Spent</span>
                <span class="metric-value">@peso($summary['spent'])</span>
            </div>
            <div class="metric-card metric-left {{ $summary['balance'] < 0 ? 'is-over' : '' }}">
                <span class="metric-label">Balance</span>
                <span class="metric-value">@peso($summary['balance'])</span>
            </div>
            <div class="metric-card metric-used-{{ \App\Support\Usage::level($summary['percent']) }}">
                <span class="metric-label">Used</span>
                <span class="metric-value">{{ $summary['percent'] }}%</span>
            </div>
        </div>

        <section class="card">
            @if ($summaryRows->isEmpty())
                <p class="empty">
                    @if ($category)
                        This category has no budget items yet.
                    @else
                        No budget items yet. Add them under Budget &amp; Allocation.
                    @endif
                </p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th class="num">Allocated</th>
                                <th class="num">Spent</th>
                                <th class="num">Balance</th>
                                <th>Used</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summaryRows as $row)
                                <tr class="{{ $row->is_overspent ? 'row-over' : '' }}">
                                    <td>
                                        <a href="{{ route('ledger.index', ['subcategory_id' => $row->id]) }}">{{ $row->display_name }}</a>
                                        @if ($row->is_overspent)
                                            <span class="badge badge-over">Overspent</span>
                                        @endif
                                    </td>
                                    <td><small class="hint">{{ $paths[$row->category_id] ?? '—' }}</small></td>
                                    <td class="num c-allocated">@peso($row->allocated_amount)</td>
                                    <td class="num c-spent">@peso($row->spent)</td>
                                    <td class="num c-left {{ $row->is_overspent ? 'neg' : '' }}">@peso($row->balance)</td>
                                    <td>
                                        @php
                                            $noAllocation = (float) $row->allocated_amount <= 0 && $row->spent > 0;
                                            $pct          = $row->utilization_percent;
                                            // low = yellow, medium = orange, high = red; overspent or spent with no allocation = dark red
                                            $state        = ($row->is_overspent || $noAllocation) ? 'over' : \App\Support\Usage::level($pct);
                                            $usedLabel    = $noAllocation ? 'No allocation' : $pct . '% used';
                                        @endphp
                                        <div class="progress progress-{{ $state }}" title="{{ $usedLabel }}">
                                            <span style="width: {{ $noAllocation ? 100 : min(100, $pct) }}%"></span>
                                        </div>
                                        <small class="pct-{{ $state === 'over' ? 'high' : $state }}">{{ $usedLabel }}</small>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">Total of {{ number_format($summary['items']) }} {{ \Illuminate\Support\Str::plural('item', $summary['items']) }} (all pages)</td>
                                <td class="num c-allocated">@peso($summary['allocated'])</td>
                                <td class="num c-spent">@peso($summary['spent'])</td>
                                <td class="num c-left {{ $summary['balance'] < 0 ? 'neg' : '' }}">@peso($summary['balance'])</td>
                                <td class="pct-{{ \App\Support\Usage::level($summary['percent']) }}">{{ $summary['percent'] }}%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="list-footer">
                    <small class="hint">Showing {{ $summaryRows->firstItem() }}&ndash;{{ $summaryRows->lastItem() }} of {{ $summaryRows->total() }}</small>
                    {{ $summaryRows->links('partials.pagination') }}
                </div>
            @endif
        </section>
    @endif
@endif

@endsection

@if ($budget)
    @section('print')
        @include('ledger._print')
    @endsection
@endif
