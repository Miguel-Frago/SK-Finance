@extends('layouts.app')

@section('title', 'Transactions')

@section('content')

@if (! $budget)
    {{-- ============ No budget yet ============ --}}
    <section class="card card-narrow">
        <h2 class="card-title">No budget yet</h2>
        <p class="muted">Create a budget first, then you can record expenses against its items.</p>
        <a href="{{ route('budget.index') }}" class="btn btn-primary">Go to Budget &amp; Allocation</a>
    </section>
@else
    @php
        $hasFilters = collect($filters)->except(['sort', 'dir'])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
        $isSorted   = ($sort !== 'date' || $dir !== 'desc');
        $sortOptions = ['date' => 'Date', 'description' => 'Description', 'item' => 'Item', 'method' => 'Paid from', 'amount' => 'Amount'];

        // Filters that live in the folded "More filters" area, and the chips for everything applied
        $moreCount = collect($filters)->only(['date_from', 'date_to', 'category_id', 'subcategory_id', 'payment_method'])
            ->filter(fn ($value) => $value !== null && $value !== '')->count();

        $chips = [];
        if (! empty($filters['search']))         $chips[] = ['text' => 'Search: ' . $filters['search'], 'keys' => ['search']];
        if (! empty($filters['date_from']))      $chips[] = ['text' => 'From ' . \Carbon\Carbon::parse($filters['date_from'])->format('M d, Y'), 'keys' => ['date_from']];
        if (! empty($filters['date_to']))        $chips[] = ['text' => 'To ' . \Carbon\Carbon::parse($filters['date_to'])->format('M d, Y'), 'keys' => ['date_to']];
        if (! empty($filters['category_id']))    $chips[] = ['text' => 'Category: ' . ($paths[$filters['category_id']] ?? '#' . $filters['category_id']), 'keys' => ['category_id']];
        if (! empty($filters['subcategory_id'])) {
            $itemLabel = collect($catalog)->pluck('items')->flatten(1)->firstWhere('id', (int) $filters['subcategory_id'])['label'] ?? '#' . $filters['subcategory_id'];
            $chips[]   = ['text' => 'Item: ' . $itemLabel, 'keys' => ['subcategory_id']];
        }
        if (! empty($filters['payment_method'])) $chips[] = ['text' => 'Paid from: ' . (\App\Models\Transaction::PAYMENT_METHODS[$filters['payment_method']] ?? $filters['payment_method']), 'keys' => ['payment_method']];
    @endphp

    {{-- ============ Header ============ --}}
    <div class="page-head">
        <div>
            <h2 class="section-title">Expenses in {{ $budget->display_name }}</h2>
            <p class="muted">Every payment is charged to one budget item and paid from the bank or from cash on hand.</p>
        </div>
        <div class="btn-row">
            @include('partials.print-button', ['all' => true])
            @can('manage')
                <a href="{{ route('transactions.create') }}" class="btn btn-primary">Record expense</a>
            @endcan
        </div>
    </div>

    {{-- ============ Totals for the filtered rows ============ --}}
    <div class="metric-grid">
        <div class="metric-card metric-neutral">
            <span class="metric-label">{{ $hasFilters ? 'Matching transactions' : 'Transactions' }}</span>
            <span class="metric-value">{{ number_format($totals->rows_count) }}</span>
        </div>
        <div class="metric-card metric-spent">
            <span class="metric-label">{{ $hasFilters ? 'Filtered total' : 'Total spent' }}</span>
            <span class="metric-value">@peso($totals->total)</span>
        </div>
        <div class="metric-card metric-bank">
            <span class="metric-label">Paid from bank</span>
            <span class="metric-value">@peso($totals->bank_total)</span>
        </div>
        <div class="metric-card metric-cash">
            <span class="metric-label">Paid from cash</span>
            <span class="metric-value">@peso($totals->cash_total)</span>
        </div>
    </div>

    {{-- ============ Search, sort and filters ============ --}}
    <section class="card no-print">
        <form method="GET" action="{{ route('transactions.index') }}" class="form">
            <div class="toolbar-main">
                <div class="form-group toolbar-search">
                    <label for="f_search">Search</label>
                    <input id="f_search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Description, payee or voucher no.">
                </div>
                <div class="form-group">
                    <label for="f_sort">Sort by</label>
                    <select id="f_sort" name="sort">
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="f_dir">Order</label>
                    <select id="f_dir" name="dir">
                        <option value="desc" @selected($dir === 'desc')>Descending (newest / highest first)</option>
                        <option value="asc" @selected($dir === 'asc')>Ascending (oldest / lowest first)</option>
                    </select>
                </div>
                <div class="toolbar-actions">
                    <button type="submit" class="btn btn-primary btn-small">Apply</button>
                    @if ($hasFilters || $isSorted)
                        <a href="{{ route('transactions.index') }}" class="btn btn-ghost btn-small">Reset</a>
                    @endif
                </div>
            </div>

            <details class="toolbar-more" @if ($moreCount > 0) open @endif>
                <summary>More filters @if ($moreCount > 0)<span class="chip-count">{{ $moreCount }}</span>@endif</summary>
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
                        <label for="f_category">Category (includes nested)</label>
                        <select id="f_category" name="category_id">
                            <option value="">All categories</option>
                            @foreach ($paths as $categoryId => $path)
                                <option value="{{ $categoryId }}" @selected((string) ($filters['category_id'] ?? '') === (string) $categoryId)>{{ $path }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="f_item">Item</label>
                        <select id="f_item" name="subcategory_id">
                            <option value="">All items</option>
                            @foreach ($catalog as $group)
                                <optgroup label="{{ $group['path'] }}">
                                    @foreach ($group['items'] as $item)
                                        <option value="{{ $item['id'] }}" @selected((string) ($filters['subcategory_id'] ?? '') === (string) $item['id'])>{{ $item['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
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
                </div>
            </details>

            @include('partials.active-filters', ['chips' => $chips])
        </form>
    </section>

    {{-- ============ Table ============ --}}
    <section class="card">
        @if ($transactions->isEmpty())
            <p class="empty">
                @if ($hasFilters)
                    No transactions match these filters.
                @else
                    No expenses recorded yet. Use <strong>Record expense</strong> to add the first one.
                @endif
            </p>
        @else
            <p class="no-print voided-toggle">
                <a href="{{ request()->fullUrlWithQuery(['voided' => request()->boolean('voided') ? null : 1, 'page' => null]) }}" class="link-action">
                    {{ request()->boolean('voided') ? 'Hide voided' : 'Show voided' }}
                </a>
                <span class="hint">Voided records stay in the books but never count in a total or a report.</span>
            </p>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            @include('partials.sort-th', ['label' => 'Date', 'column' => 'date', 'first' => 'desc'])
                            @include('partials.sort-th', ['label' => 'Description', 'column' => 'description'])
                            @include('partials.sort-th', ['label' => 'Item', 'column' => 'item'])
                            @include('partials.sort-th', ['label' => 'Paid from', 'column' => 'method'])
                            @include('partials.sort-th', ['label' => 'Amount', 'column' => 'amount', 'class' => 'num', 'first' => 'desc'])
                            <th>Receipt</th>
                            @can('manage')<th class="actions-col">Actions</th>@endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transactions as $transaction)
                            <tr @class(['is-voided' => $transaction->isVoided()])>
                                <td class="nowrap">{{ $transaction->date->format('M d, Y') }}</td>
                                <td>
                                    <strong>{{ $transaction->description }}</strong>
                                    @if ($transaction->isVoided())
                                        <small class="void-note block"><span class="badge badge-void">Voided</span> {{ $transaction->void_reason }} &middot; {{ $transaction->voided_at->format('M d, Y') }}</small>
                                    @endif
                                    <small class="hint block">
                                        {{ $transaction->payee ?: 'No payee' }}
                                        @if ($transaction->voucher_number) &middot; Voucher {{ $transaction->voucher_number }}@endif
                                        @if ($transaction->check_number) &middot; Check {{ $transaction->check_number }}@endif
                                    </small>
                                </td>
                                <td>
                                    {{ $transaction->subcategory->display_name }}
                                    <small class="hint block">{{ $paths[$transaction->category_id] ?? $transaction->category->name }}</small>
                                </td>
                                <td>
                                    <span class="badge {{ $transaction->payment_method === 'bank' ? 'badge-bank' : 'badge-cash' }}">{{ $transaction->payment_method_label }}</span>
                                </td>
                                <td class="num">@peso($transaction->amount)</td>
                                <td>
                                    @php $files = $transaction->attachments; @endphp
                                    @if ($files->isEmpty())
                                        <span class="hint">&mdash;</span>
                                    @elseif ($files->count() === 1)
                                        <a href="{{ $files->first()->url }}" target="_blank" rel="noopener">View</a>
                                    @else
                                        <span class="hint">{{ $files->count() }} files:</span>
                                        <span class="receipt-links">
                                            @foreach ($files as $file)
                                                <a href="{{ $file->url }}" target="_blank" rel="noopener" title="{{ $file->display_name }}">{{ $loop->iteration }}</a>
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                                @can('manage')
                                <td class="actions-col">
                                    @unless ($transaction->isVoided())
                                        <a href="{{ route('transactions.edit', $transaction) }}" class="link-action">Edit</a>
                                        @include('partials.void-form', ['action' => route('transactions.void', $transaction), 'what' => 'expense'])
                                    @endunless
                                </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="list-footer">
                <small class="hint">Showing {{ $transactions->firstItem() }}&ndash;{{ $transactions->lastItem() }} of {{ $transactions->total() }}</small>
                {{ $transactions->links('partials.pagination') }}
            </div>
        @endif
    </section>
@endif

@endsection

@if ($budget)
    @section('print')
        @include('transactions._print')
    @endsection
@endif
