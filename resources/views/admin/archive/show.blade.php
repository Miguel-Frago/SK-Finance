@extends('layouts.admin')

@section('title', 'Log archive')

@section('content')

@php
    $badgeFor = fn (string $action) => match ($action) {
        'created' => 'badge-ok',
        'updated' => 'badge-info',
        'deleted' => 'badge-bad',
        default   => 'badge-purple',
    };
@endphp

<nav class="crumbs no-print" aria-label="Where you are">
    <a href="{{ route('admin.archive.index') }}">Log archive</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="{{ route('admin.archive.year', $year) }}">{{ $year }}</a>
    <span aria-hidden="true">&rsaquo;</span>
    <strong>{{ $label }}</strong>
</nav>

<div class="page-head no-print">
    <div>
        <h2 class="section-title">{{ $label }}</h2>
        <p class="muted"><code>{{ $file }}</code> &middot; {{ number_format($entries) }} {{ $entries === 1 ? 'entry' : 'entries' }}. A saved copy: it cannot be edited.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.archive.download', ['year' => $year, 'period' => $period]) }}" class="btn btn-secondary">Download CSV</a>
        <button type="button" class="btn btn-primary" onclick="window.print()">Print this page</button>
    </div>
</div>

{{-- shown on paper only --}}
<div class="print-only">
    <h2>SK Federation of Luna: activity log, {{ $label }}</h2>
    <p>
        @if ($term !== '') Search: {{ $term }} &middot; @endif
        Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }} &middot; printed {{ now()->format('M d, Y g:i A') }}
    </p>
</div>

<section class="card toolbar-card no-print">
    <form method="GET" action="{{ route('admin.archive.show', ['year' => $year, 'period' => $period]) }}" class="form toolbar-form">
        <div class="form-group">
            <label for="s_q">Search this file</label>
            <input id="s_q" type="text" name="q" value="{{ $term }}" placeholder="Name, barangay, or what happened" maxlength="100">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Search</button>
            @if ($term !== '')<a href="{{ route('admin.archive.show', ['year' => $year, 'period' => $period]) }}" class="btn btn-ghost">Clear</a>@endif
        </div>
    </form>
</section>

<section class="card card-flush">
    @if ($logs->isEmpty())
        <div class="empty-state">
            @if ($term !== '')
                <strong>Nothing matches</strong>
                Try other words, or clear the search.
            @else
                <strong>No activity was recorded in this period</strong>
                The file is empty because nothing happened.
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="table table-admin table-log">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Who</th>
                        <th>Barangay</th>
                        <th>What happened</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $row)
                        @php
                            [$when, $who, $role, $barangay, $action, $description, $details] = array_pad($row, 7, '');
                            $at = rescue(fn () => \Illuminate\Support\Carbon::parse($when), null, false);
                        @endphp
                        <tr>
                            <td class="log-when">
                                @if ($at){{ $at->format('M d, Y') }}<small>{{ $at->format('g:i A') }}</small>@else{{ $when }}@endif
                            </td>
                            <td><span class="cell-main">{{ $who !== '' ? $who : '—' }}</span>@if ($role !== '')<span class="cell-sub">{{ $role }}</span>@endif</td>
                            <td>{{ $barangay !== '' ? $barangay : '—' }}</td>
                            <td>
                                <div class="log-what">
                                    <span class="badge {{ $badgeFor((string) $action) }}">{{ $action }}</span>
                                    <div class="log-text">
                                        {{ $description }}
                                        @if ($details !== '')<small class="log-details">{{ $details }}</small>@endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="result-note no-print">
            Showing {{ $logs->firstItem() }}&ndash;{{ $logs->lastItem() }} of {{ number_format($logs->total()) }}{{ $term !== '' ? ' that match' : '' }}.
            The CSV has every line of the file, not only this page.
        </p>
        @if ($logs->hasPages())<div class="card-pad no-print">{{ $logs->links('partials.pagination') }}</div>@endif
    @endif
</section>

@endsection
