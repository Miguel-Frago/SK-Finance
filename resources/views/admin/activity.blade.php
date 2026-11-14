@extends('layouts.admin')

@section('title', 'Activity log')

@section('content')

@php
    $filtered = collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty();
    $query    = collect($filters)->filter(fn ($v) => filled($v))->all();
    $monthName = $monthStart->format('F Y');
@endphp

<div class="page-head no-print">
    <div>
        <h2 class="section-title">Who did what in {{ $monthName }}</h2>
        <p class="muted">Sign-ins, and every record added, changed or deleted in any barangay, with the date and the person. It cannot be edited.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.activity', $query + ['export' => 'csv']) }}" class="btn btn-secondary">Download CSV</a>
        <button type="button" class="btn btn-primary" onclick="window.print()">Print this page</button>
    </div>
</div>

{{-- how the monthly reset works --}}
<div class="month-banner no-print">
    <div class="month-banner-text">
        <span class="month-chip">{{ $monthName }}</span>
        <span>
            This page shows this month only. On the 1st it starts fresh, and the finished month is saved as a file in the
            <a href="{{ route('admin.archive.index') }}">Log archive</a>, where you can view or download it. Nothing is deleted.
        </span>
    </div>
    <div class="month-banner-actions">
        @if ($previous)
            <a href="{{ route('admin.archive.show', ['year' => $previous['year'], 'period' => $previous['period']]) }}" class="btn btn-secondary btn-small">View {{ $previous['label'] }}</a>
        @endif
        <a href="{{ route('admin.archive.index') }}" class="btn btn-secondary btn-small">Open the archive</a>
    </div>
</div>

{{-- shown on paper only: what this report contains --}}
<div class="print-only">
    <h2>SK Federation of Luna: activity log, {{ $monthName }}</h2>
    <p>
        @if ($filtered) Filtered &middot; @endif
        @if (! empty($filters['date_from']) || ! empty($filters['date_to']))
            {{ $filters['date_from'] ?? 'the start' }} to {{ $filters['date_to'] ?? 'today' }} &middot;
        @endif
        Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }} &middot; printed {{ now()->format('M d, Y g:i A') }}
    </p>
</div>

<section class="card toolbar-card no-print">
    <form method="GET" action="{{ route('admin.activity') }}" class="form toolbar-form toolbar-form-grid">
        <div class="form-group">
            <label for="f_q">Search</label>
            <input id="f_q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or what happened" maxlength="100">
        </div>
        <div class="form-group">
            <label for="f_b">Barangay</label>
            <select id="f_b" name="barangay_id">
                <option value="">All barangays</option>
                @foreach ($barangays as $b)
                    <option value="{{ $b->id }}" @selected((int) ($filters['barangay_id'] ?? 0) === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="f_a">What</label>
            <select id="f_a" name="action">
                <option value="">Anything</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group form-group-date">
            <label for="f_from">From</label>
            <input id="f_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" min="{{ $monthStart->toDateString() }}" max="{{ $monthEnd->toDateString() }}">
        </div>
        <div class="form-group form-group-date">
            <label for="f_to">To</label>
            <input id="f_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" min="{{ $monthStart->toDateString() }}" max="{{ $monthEnd->toDateString() }}">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Filter</button>
            @if ($filtered)<a href="{{ route('admin.activity') }}" class="btn btn-ghost">Clear</a>@endif
        </div>
    </form>
</section>

<section class="card card-flush">
    @if ($logs->isEmpty())
        <div class="empty-state">
            @if ($filtered)
                <strong>Nothing matches</strong>
                Try a wider date range, or clear the filter.
            @else
                <strong>No activity yet in {{ $monthName }}</strong>
                New sign-ins and changes will appear here as they happen.
            @endif
        </div>
    @else
        @include('admin._log-table', ['logs' => $logs])
        <p class="result-note no-print">Showing {{ $logs->firstItem() }}&ndash;{{ $logs->lastItem() }} of {{ $logs->total() }} in {{ $monthName }}. The CSV has every line that matches, not only this page.</p>
        @if ($logs->hasPages())<div class="card-pad no-print">{{ $logs->links('partials.pagination') }}</div>@endif
    @endif
</section>

@endsection
