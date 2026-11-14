@extends('layouts.app')

@section('title', 'Fund Movements')

@section('content')

@if (! $budget)
    {{-- ============ No budget yet ============ --}}
    <section class="card card-narrow">
        <h2 class="card-title">No budget yet</h2>
        <p class="muted">Create a budget first, then you can record the money coming in and moving between the bank and cash on hand.</p>
        <a href="{{ route('budget.index') }}" class="btn btn-primary">Go to Budget &amp; Allocation</a>
    </section>
@else
    @php
        $hasFilters = collect($filters)->except(['sort', 'dir'])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
        $isSorted   = ($sort !== 'date' || $dir !== 'desc');
        $sortOptions = ['date' => 'Date', 'type' => 'Type', 'account' => 'Account', 'reference' => 'Reference', 'amount' => 'Amount'];

        $moreCount = collect($filters)->only(['date_from', 'date_to', 'type', 'account'])
            ->filter(fn ($value) => $value !== null && $value !== '')->count();

        $chips = [];
        if (! empty($filters['search']))    $chips[] = ['text' => 'Search: ' . $filters['search'], 'keys' => ['search']];
        if (! empty($filters['date_from'])) $chips[] = ['text' => 'From ' . \Carbon\Carbon::parse($filters['date_from'])->format('M d, Y'), 'keys' => ['date_from']];
        if (! empty($filters['date_to']))   $chips[] = ['text' => 'To ' . \Carbon\Carbon::parse($filters['date_to'])->format('M d, Y'), 'keys' => ['date_to']];
        if (! empty($filters['type']))      $chips[] = ['text' => 'Type: ' . (\App\Models\FundMovement::TYPES[$filters['type']] ?? $filters['type']), 'keys' => ['type']];
        if (! empty($filters['account']))   $chips[] = ['text' => 'Account: ' . (\App\Models\FundMovement::ACCOUNTS[$filters['account']] ?? $filters['account']), 'keys' => ['account']];
    @endphp

    {{-- ============ Header ============ --}}
    <div class="page-head">
        <div>
            <h2 class="section-title">Fund movements in {{ $budget->display_name }}</h2>
            <p class="muted">Money received, withdrawn from the bank into cash, and deposited back. Withdrawals and deposits are transfers, not expenses.</p>
        </div>
        <div class="btn-row">
            @include('partials.print-button', ['all' => true])
            @can('manage')
                <a href="{{ route('fund-movements.create') }}" class="btn btn-primary">Record movement</a>
            @endcan
        </div>
    </div>

    {{-- ============ Current balances (not affected by the filters) ============ --}}
    <div class="metric-grid">
        <div class="metric-card metric-bank">
            <span class="metric-label">Bank balance</span>
            <span class="metric-value">@peso($balances['bank'])</span>
        </div>
        <div class="metric-card metric-cash">
            <span class="metric-label">Cash on hand</span>
            <span class="metric-value">@peso($balances['cash'])</span>
        </div>
        <div class="metric-card metric-neutral">
            <span class="metric-label">Total funds on hand</span>
            <span class="metric-value">@peso($balances['total'])</span>
        </div>
        <div class="metric-card metric-left">
            <span class="metric-label">Budget not yet spent</span>
            <span class="metric-value">@peso($balances['unspent'])</span>
        </div>
    </div>
    <p class="hint">Withdrawing cash from the bank is only a transfer: the bank goes down and cash on hand goes up, while total funds and the unspent budget stay the same until an expense is recorded.</p>

    {{-- ============ Totals for the filtered rows ============ --}}
    <div class="metric-grid">
        <div class="metric-card">
            <span class="metric-label">{{ $hasFilters ? 'Matching movements' : 'Movements' }}</span>
            <span class="metric-value">{{ number_format($totals->rows_count) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Funds received</span>
            <span class="metric-value">@peso($totals->received)</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Withdrawn from bank</span>
            <span class="metric-value">@peso($totals->withdrawn)</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Deposited to bank</span>
            <span class="metric-value">@peso($totals->deposited)</span>
        </div>
    </div>

    {{-- ============ Search, sort and filters ============ --}}
    <section class="card no-print">
        <form method="GET" action="{{ route('fund-movements.index') }}" class="form">
            <div class="toolbar-main">
                <div class="form-group toolbar-search">
                    <label for="f_search">Search</label>
                    <input id="f_search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Reference or remarks">
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
                        <a href="{{ route('fund-movements.index') }}" class="btn btn-ghost btn-small">Reset</a>
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
                        <label for="f_type">Type</label>
                        <select id="f_type" name="type">
                            <option value="">All types</option>
                            @foreach (\App\Models\FundMovement::TYPES as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="f_account">Account (funds received)</label>
                        <select id="f_account" name="account">
                            <option value="">Any account</option>
                            @foreach (\App\Models\FundMovement::ACCOUNTS as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['account'] ?? '') === $value)>{{ $label }}</option>
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
        @if ($movements->isEmpty())
            <p class="empty">
                @if ($hasFilters)
                    No fund movements match these filters.
                @else
                    No fund movements recorded yet. Use <strong>Record movement</strong> when funds are received or moved between the bank and cash.
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
                            @include('partials.sort-th', ['label' => 'Type', 'column' => 'type'])
                            @include('partials.sort-th', ['label' => 'Account', 'column' => 'account'])
                            @include('partials.sort-th', ['label' => 'Reference', 'column' => 'reference'])
                            @include('partials.sort-th', ['label' => 'Amount', 'column' => 'amount', 'class' => 'num', 'first' => 'desc'])
                            @can('manage')<th class="actions-col">Actions</th>@endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($movements as $movement)
                            <tr @class(['is-voided' => $movement->isVoided()])>
                                <td class="nowrap">{{ $movement->date->format('M d, Y') }}</td>
                                <td>
                                    {{ $movement->type_label }}
                                    @if ($movement->isVoided())
                                        <small class="void-note block"><span class="badge badge-void">Voided</span> {{ $movement->void_reason }} &middot; {{ $movement->voided_at->format('M d, Y') }}</small>
                                    @endif
                                </td>
                                <td>
                                    @if ($movement->account)
                                        <span class="badge {{ $movement->account === 'bank' ? 'badge-bank' : 'badge-cash' }}">{{ $movement->account_label }}</span>
                                    @else
                                        <span class="hint">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $movement->reference ?: '—' }}
                                    @if ($movement->remarks)
                                        <small class="hint block">{{ $movement->remarks }}</small>
                                    @endif
                                </td>
                                <td class="num">@peso($movement->amount)</td>
                                @can('manage')
                                <td class="actions-col">
                                    @unless ($movement->isVoided())
                                        <a href="{{ route('fund-movements.edit', $movement) }}" class="link-action">Edit</a>
                                        @include('partials.void-form', ['action' => route('fund-movements.void', $movement), 'what' => 'movement'])
                                    @endunless
                                </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="list-footer">
                <small class="hint">Showing {{ $movements->firstItem() }}&ndash;{{ $movements->lastItem() }} of {{ $movements->total() }}</small>
                {{ $movements->links('partials.pagination') }}
            </div>
        @endif
    </section>
@endif

@endsection

@if ($budget)
    @section('print')
        @include('fund-movements._print')
    @endsection
@endif
