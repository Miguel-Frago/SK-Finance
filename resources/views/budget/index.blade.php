@extends('layouts.app')

@section('title', 'Budget & Allocation')

@section('content')

@if (! $budget && ! auth()->user()->can('manage'))
    <section class="card card-narrow">
        <h2 class="card-title">No budget yet</h2>
        <p class="muted">The SK treasurer has not set up the budget yet.</p>
    </section>
@elseif (! $budget)
    {{-- ============ No budget yet: first-time setup ============ --}}
    <section class="card card-narrow">
        <h2 class="card-title">Set up your annual budget</h2>
        <p class="muted">Enter the total budget for the fiscal year and the starting balances of the bank account and cash on hand. You can change these later.</p>

        <form method="POST" action="{{ route('budget.store') }}" class="form form-grid">
            @csrf
            <div class="form-group">
                <label for="year">Fiscal year</label>
                <input id="year" type="number" name="year" min="2000" max="2100" value="{{ old('year', now()->year) }}" required>
            </div>
            <div class="form-group">
                <label for="total_budget">Total budget (₱)</label>
                <input id="total_budget" type="number" name="total_budget" step="0.01" min="0" value="{{ old('total_budget') }}" required>
            </div>
            <div class="form-group">
                <label for="opening_bank_balance">Opening bank balance (₱)</label>
                <input id="opening_bank_balance" type="number" name="opening_bank_balance" step="0.01" min="0" value="{{ old('opening_bank_balance', 0) }}">
            </div>
            <div class="form-group">
                <label for="opening_cash_balance">Opening cash on hand (₱)</label>
                <input id="opening_cash_balance" type="number" name="opening_cash_balance" step="0.01" min="0" value="{{ old('opening_cash_balance', 0) }}">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create budget</button>
            </div>
        </form>
    </section>
@else
    @php
        $allocated   = $budget->total_allocated;
        $unallocated = $budget->unallocated;
        $spent       = $budget->total_spent;
        $total       = (float) $budget->total_budget;
        $allocPct    = $total > 0 ? min(100, round($allocated / $total * 100, 1)) : 0;
        $left        = $budget->remaining_budget;
        $spentPct    = $total > 0 ? min(100, round($spent / $total * 100, 1)) : 0;
        $leftPct     = round(100 - $spentPct, 1);
        $spentLevel  = \App\Support\Usage::level($spentPct);   // low = yellow, medium = orange, high = red

        // Overall donut: one slice per top-level category, plus whatever is still unallocated
        $overallSegments = [];
        foreach ($tree as $index => $root) {
            $overallSegments[] = ['label' => $root->name, 'value' => $root->tree_allocated, 'color' => \App\Support\Donut::color($index)];
        }
        if ($unallocated > 0) {
            $overallSegments[] = ['label' => 'Unallocated', 'value' => $unallocated, 'color' => \App\Support\Donut::UNALLOCATED_COLOR];
        }

        // Spending donut: money spent per top-level category, plus the budget still left
        $spentSegments = [];
        foreach ($tree as $index => $root) {
            $spentSegments[] = ['label' => $root->name, 'value' => $root->tree_spent, 'color' => \App\Support\Donut::color($index)];
        }
        if ($left > 0) {
            $spentSegments[] = ['label' => 'Budget left', 'value' => $left, 'color' => \App\Support\Donut::LEFT_COLOR];
        }
    @endphp

    {{-- ============ Header ============ --}}
    <div class="page-head">
        <div>
            <h2 class="section-title">
                {{ $budget->display_name }}
                @if ($budget->is_supplemental)
                    <span class="badge badge-purple">Supplemental</span>
                @endif
            </h2>
            @if ($budget->carriedFrom)
                <p class="muted">Carried over from {{ $budget->carriedFrom->display_name }}. Tracked separately from the regular budget.</p>
            @endif
        </div>
        <div class="btn-row">
            @include('partials.print-button')
            @can('manage')
                <a href="#edit-budget" class="btn btn-secondary">Edit budget</a>
                <a href="{{ route('budget.supplemental.create', ['source' => $budget->id]) }}" class="btn btn-secondary">New supplemental</a>
                <a href="#add-category" class="btn btn-primary">Add category</a>
            @endcan
        </div>
    </div>

    {{-- ============ Metric cards ============ --}}
    <div class="metric-grid">
        <div class="metric-card metric-total">
            <span class="metric-label">Total Budget</span>
            <span class="metric-value">@peso($total)</span>
        </div>
        <div class="metric-card metric-allocated">
            <span class="metric-label">Allocated</span>
            <span class="metric-value">@peso($allocated)</span>
        </div>
        <div class="metric-card metric-unallocated">
            <span class="metric-label">Unallocated</span>
            <span class="metric-value">@peso($unallocated)</span>
        </div>
        <div class="metric-card metric-spent">
            <span class="metric-label">Spent so far</span>
            <span class="metric-value">@peso($spent)</span>
        </div>
        <div class="metric-card metric-left">
            <span class="metric-label">Budget left</span>
            <span class="metric-value">@peso($left)</span>
        </div>
    </div>

    {{-- ============ Overview cards side by side: allocation (left) and spending (right) ============ --}}
    <div class="overview-pair">
    {{-- ============ Overall picture: pool bar + donut by category ============ --}}
    <section class="card">
        <h3 class="card-title">Budget overview</h3>
        <div class="overview-grid">
            <div>
                <h4 class="mini-title">Allocated vs. unallocated pool</h4>
                <div class="pool-bar pool-bar-alloc" role="img" aria-label="{{ $allocPct }}% of the budget is allocated">
                    <span class="pool-allocated" style="width: {{ $allocPct }}%"></span>
                </div>
                <div class="pool-legend">
                    <span><i class="dot dot-allocated"></i> Allocated @peso($allocated) ({{ $allocPct }}%)</span>
                    <span><i class="dot dot-unallocated"></i> Unallocated @peso($unallocated) ({{ round(100 - $allocPct, 1) }}%)</span>
                </div>
            </div>
            <div>
                <h4 class="mini-title">Share of the budget by category</h4>
                @include('budget._donut', [
                    'segments'     => $overallSegments,
                    'centerTop'    => $allocPct . '%',
                    'centerBottom' => 'allocated',
                    'chartLabel'   => 'Share of the total budget by category',
                    'chartSize'    => 'md',
                ])
            </div>
        </div>
    </section>

    {{-- ============ Spending: budget spent vs. budget left, and spending by category ============ --}}
    <section class="card">
        <h3 class="card-title">Spending overview</h3>
        <div class="overview-grid">
            <div>
                <h4 class="mini-title">Budget spent vs. budget left</h4>
                <div class="pool-bar pool-bar-spent" role="img" aria-label="{{ $spentPct }}% of the budget has been spent">
                    <span class="pool-allocated fill-{{ $spentLevel }}" style="width: {{ $spentPct }}%"></span>
                </div>
                <div class="pool-legend">
                    <span><i class="dot fill-{{ $spentLevel }}"></i> Spent @peso($spent) ({{ $spentPct }}%)</span>
                    <span><i class="dot dot-left"></i> Left @peso($left) ({{ $leftPct }}%)</span>
                </div>
            </div>
            <div>
                <h4 class="mini-title">Spent by category</h4>
                @include('budget._donut', [
                    'segments'     => $spentSegments,
                    'centerTop'    => $spentPct . '%',
                    'centerBottom' => 'spent',
                    'centerLevel'  => $spentLevel,
                    'chartLabel'   => 'Share of the total budget spent, by category, and the budget left',
                    'chartSize'    => 'md',
                ])
            </div>
        </div>
    </section>

    </div>

    @can('manage')
    {{-- ============ Edit budget (opens with the "Edit budget" button) ============ --}}
    <section id="edit-budget" class="card panel">
        <h3 class="card-title">Edit budget</h3>
        <form method="POST" action="{{ route('budget.update', $budget) }}" class="form form-grid">
            @csrf
            @method('PUT')
            <div class="form-group">
                <label for="label">Label (optional)</label>
                <input id="label" type="text" name="label" value="{{ $budget->label }}" maxlength="255">
            </div>
            <div class="form-group">
                <label for="edit_total">Total budget (₱)</label>
                <input id="edit_total" type="number" name="total_budget" step="0.01" min="0" value="{{ $budget->total_budget }}" required>
            </div>
            <div class="form-group">
                <label for="edit_bank">Opening bank balance (₱)</label>
                <input id="edit_bank" type="number" name="opening_bank_balance" step="0.01" min="0" value="{{ $budget->opening_bank_balance }}">
            </div>
            <div class="form-group">
                <label for="edit_cash">Opening cash on hand (₱)</label>
                <input id="edit_cash" type="number" name="opening_cash_balance" step="0.01" min="0" value="{{ $budget->opening_cash_balance }}">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save changes</button>
                <a href="#" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </section>

    {{-- ============ Add category (top level, or placed inside another one) ============ --}}
    <section id="add-category" class="card panel">
        <h3 class="card-title">Add category</h3>
        <form method="POST" action="{{ route('budget.categories.store', $budget) }}" class="form form-grid form-grid-3">
            @csrf
            <div class="form-group">
                <label for="cat_parent">Place under</label>
                <select id="cat_parent" name="parent_id">
                    <option value="">— Top level —</option>
                    @foreach ($parentOptions as $optionId => $optionLabel)
                        <option value="{{ $optionId }}">{{ $optionLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="cat_code">Code (optional)</label>
                <input id="cat_code" type="text" name="code" maxlength="50" placeholder="e.g. VIII">
            </div>
            <div class="form-group">
                <label for="cat_name">Category name</label>
                <input id="cat_name" type="text" name="name" maxlength="255" required>
            </div>
            <div class="form-group" style="grid-column: 1 / -1">
                <label for="cat_desc">Description (optional)</label>
                <input id="cat_desc" type="text" name="description" maxlength="1000">
            </div>
            @include('budget._item-rows', ['itemRows' => 4])
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Add category</button>
                <a href="#" class="btn btn-ghost">Cancel</a>
                <small class="hint">Items are optional; you can add them later. Unallocated pool: @peso($unallocated)</small>
            </div>
        </form>
    </section>

    @endcan

    {{-- ============ Categories (nested), each with its items, usage bars and donut ============ --}}
    @forelse ($tree as $category)
        @include('budget._category', [
            'category'    => $category,
            'budget'      => $budget,
            'unallocated' => $unallocated,
            'depth'       => 0,
        ])
    @empty
        <section class="card">
            <p class="empty">No categories yet.@can('manage') Use <strong>Add category</strong> to create the first one (for example, General Administration Program).@endcan</p>
        </section>
    @endforelse
@endif

@endsection

@if ($budget)
    @section('print')
        @include('budget._print')
    @endsection
@endif
