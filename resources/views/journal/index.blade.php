@extends('layouts.app')

@section('title', 'Journal')

@section('content')

@if (! $budget)
    {{-- ============ No budget yet ============ --}}
    <section class="card card-narrow">
        <h2 class="card-title">No budget yet</h2>
        <p class="muted">Create a budget first. The journal lists its expenses and fund movements.</p>
        <a href="{{ route('budget.index') }}" class="btn btn-primary">Go to Budget &amp; Allocation</a>
    </section>
@else
    @php
        $hasFilters  = collect($filters)->except('order')->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
        $newestFirst = ($filters['order'] ?? 'asc') === 'desc';
        $showOpening = ! $hasFilters && ! $newestFirst && $entries->onFirstPage();

        $moreCount = collect($filters)->only(['date_from', 'date_to', 'source', 'account'])
            ->filter(fn ($value) => $value !== null && $value !== '')->count();

        $chips = [];
        if (! empty($filters['search']))    $chips[] = ['text' => 'Search: ' . $filters['search'], 'keys' => ['search']];
        if (! empty($filters['date_from'])) $chips[] = ['text' => 'From ' . \Carbon\Carbon::parse($filters['date_from'])->format('M d, Y'), 'keys' => ['date_from']];
        if (! empty($filters['date_to']))   $chips[] = ['text' => 'To ' . \Carbon\Carbon::parse($filters['date_to'])->format('M d, Y'), 'keys' => ['date_to']];
        if (! empty($filters['source']))    $chips[] = ['text' => 'Entry: ' . (\App\Support\JournalBook::SOURCES[$filters['source']] ?? $filters['source']), 'keys' => ['source']];
        if (! empty($filters['account']))   $chips[] = ['text' => 'Account: ' . ($filters['account'] === 'bank' ? 'Bank' : 'Cash on hand'), 'keys' => ['account']];
    @endphp

    {{-- ============ Header ============ --}}
    <div class="page-head">
        <div>
            <h2 class="section-title">Journal of {{ $budget->display_name }}</h2>
            <p class="muted">
                Every expense and fund movement in date order, with the bank and cash balance after each entry.
                Debit is money in, credit is money out. Entries are recorded in Transactions and Fund Movements.
            </p>
        </div>
        <div class="btn-row">
            @include('partials.print-button', ['all' => true])
        </div>
    </div>

    {{-- ============ Opening and current balances ============ --}}
    <div class="metric-grid">
        <div class="metric-card metric-bank">
            <span class="metric-label">Opening bank</span>
            <span class="metric-value">@peso($budget->opening_bank_balance)</span>
        </div>
        <div class="metric-card metric-cash">
            <span class="metric-label">Opening cash</span>
            <span class="metric-value">@peso($budget->opening_cash_balance)</span>
        </div>
        <div class="metric-card metric-bank">
            <span class="metric-label">Current bank</span>
            <span class="metric-value">@peso($budget->bank_balance)</span>
        </div>
        <div class="metric-card metric-cash">
            <span class="metric-label">Current cash on hand</span>
            <span class="metric-value">@peso($budget->cash_balance)</span>
        </div>
    </div>

    {{-- ============ Totals for the filtered rows ============ --}}
    <div class="metric-grid">
        <div class="metric-card metric-neutral">
            <span class="metric-label">{{ $hasFilters ? 'Matching entries' : 'Entries' }}</span>
            <span class="metric-value">{{ number_format($totals['rows']) }}</span>
        </div>
        <div class="metric-card metric-bank">
            <span class="metric-label">Bank debits</span>
            <span class="metric-value">@peso($totals['bank_in'])</span>
        </div>
        <div class="metric-card metric-bank">
            <span class="metric-label">Bank credits</span>
            <span class="metric-value">@peso($totals['bank_out'])</span>
        </div>
        <div class="metric-card metric-cash">
            <span class="metric-label">Cash debits</span>
            <span class="metric-value">@peso($totals['cash_in'])</span>
        </div>
        <div class="metric-card metric-cash">
            <span class="metric-label">Cash credits</span>
            <span class="metric-value">@peso($totals['cash_out'])</span>
        </div>
    </div>

    {{-- ============ Search, order and filters ============ --}}
    <section class="card no-print">
        <form method="GET" action="{{ route('journal.index') }}" class="form">
            <div class="toolbar-main toolbar-main-3">
                <div class="form-group toolbar-search">
                    <label for="f_search">Search</label>
                    <input id="f_search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Reference, particulars, payee or item">
                </div>
                <div class="form-group">
                    <label for="f_order">Order by date</label>
                    <select id="f_order" name="order">
                        <option value="asc" @selected(! $newestFirst)>Oldest first</option>
                        <option value="desc" @selected($newestFirst)>Newest first</option>
                    </select>
                </div>
                <div class="toolbar-actions">
                    <button type="submit" class="btn btn-primary btn-small">Apply</button>
                    @if ($hasFilters || $newestFirst)
                        <a href="{{ route('journal.index') }}" class="btn btn-ghost btn-small">Reset</a>
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
                        <label for="f_source">Entry type</label>
                        <select id="f_source" name="source">
                            <option value="">All entries</option>
                            @foreach (\App\Support\JournalBook::SOURCES as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="f_account">Account moved</label>
                        <select id="f_account" name="account">
                            <option value="">Bank and cash</option>
                            <option value="bank" @selected(($filters['account'] ?? '') === 'bank')>Bank</option>
                            <option value="cash" @selected(($filters['account'] ?? '') === 'cash')>Cash on hand</option>
                        </select>
                    </div>
                </div>
            </details>

            @include('partials.active-filters', ['chips' => $chips])
        </form>
    </section>

    {{-- ============ Table ============ --}}
    <section class="card">
        @if ($entries->isEmpty())
            <p class="empty">
                @if ($hasFilters)
                    No entries match these filters.
                @else
                    Nothing in the journal yet. Expenses and fund movements appear here once they are recorded.
                @endif
            </p>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="sortable {{ 'is-sorted' }}" aria-sort="{{ $newestFirst ? 'descending' : 'ascending' }}">
                                <a href="{{ request()->fullUrlWithQuery(['order' => $newestFirst ? 'asc' : 'desc', 'page' => null]) }}" title="Flip the date order">
                                    Date<span class="sort-icon" aria-hidden="true">{{ $newestFirst ? '▼' : '▲' }}</span>
                                </a>
                            </th>
                            <th>Entry</th>
                            <th>Reference</th>
                            <th>Particulars</th>
                            <th class="num">Bank debit</th>
                            <th class="num">Bank credit</th>
                            <th class="num">Bank balance</th>
                            <th class="num">Cash debit</th>
                            <th class="num">Cash credit</th>
                            <th class="num">Cash balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($showOpening)
                            <tr>
                                <td></td>
                                <td colspan="3"><strong>Opening balances</strong></td>
                                <td></td>
                                <td></td>
                                <td class="num"><strong>@peso($budget->opening_bank_balance)</strong></td>
                                <td></td>
                                <td></td>
                                <td class="num"><strong>@peso($budget->opening_cash_balance)</strong></td>
                            </tr>
                        @endif

                        @foreach ($entries as $row)
                            <tr>
                                <td class="nowrap">{{ $row['date_label'] }}</td>
                                <td class="nowrap"><span class="badge {{ $row['source'] === 'expense' ? 'badge-purple' : 'badge-bank' }}">{{ $row['source_label'] }}</span></td>
                                <td class="nowrap">{{ $row['reference'] ?: '—' }}</td>
                                <td>
                                    <a href="{{ $row['url'] }}">{{ $row['particulars'] }}</a>
                                    @if ($row['detail'])
                                        <small class="hint block">{{ $row['detail'] }}</small>
                                    @endif
                                </td>
                                <td class="num">@if ($row['bank'] > 0)@peso($row['bank'])@endif</td>
                                <td class="num">@if ($row['bank'] < 0)@peso(-$row['bank'])@endif</td>
                                <td class="num">@peso($row['bank_balance'])</td>
                                <td class="num">@if ($row['cash'] > 0)@peso($row['cash'])@endif</td>
                                <td class="num">@if ($row['cash'] < 0)@peso(-$row['cash'])@endif</td>
                                <td class="num">@peso($row['cash_balance'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="list-footer">
                <small class="hint">Showing {{ $entries->firstItem() }}&ndash;{{ $entries->lastItem() }} of {{ $entries->total() }}</small>
                {{ $entries->links('partials.pagination') }}
            </div>
        @endif
    </section>
@endif

@endsection

@if ($budget)
    @section('print')
        @include('journal._print')
    @endsection
@endif
