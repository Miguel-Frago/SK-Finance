@extends('layouts.admin')

@section('title', 'Barangays')

@section('content')

@php
    $filtered = $filter !== 'all' || $term !== '';
    $query    = array_filter(['show' => $filter !== 'all' ? $filter : null, 'q' => $term]);
@endphp

<div class="page-head no-print">
    <div>
        <h2 class="section-title">The {{ $summary['total'] }} barangays of Luna</h2>
        <p class="muted">Who holds the SK Treasurer, SK Chairman and SK Secretary places now, and how many of the {{ \App\Models\User::SEATS['kagawad'] }} Kagawad places are filled. A portal is active once its Treasurer is approved. Districts will be assigned later.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.barangays', $query + ['export' => 'csv']) }}" class="btn btn-secondary">Download CSV</a>
        <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
    </div>
</div>

{{-- shown on paper only --}}
<div class="print-only">
    <h2>SK Federation of Luna: barangay coverage</h2>
    <p>
        {{ $summary['treasurer'] }} of {{ $summary['total'] }} barangays have an active SK Treasurer &middot; {{ $summary['chairman'] }} have an SK Chairman
        @if ($filter === 'vacant') &middot; showing barangays without a Treasurer @elseif ($filter === 'active') &middot; showing barangays with a Treasurer @endif
        &middot; printed {{ now()->format('M d, Y g:i A') }}
    </p>
</div>

<div class="summary-pills">
    <div class="summary-pill pill-ok"><strong>{{ $summary['treasurer'] }} of {{ $summary['total'] }}</strong>have an active Treasurer</div>
    <div class="summary-pill pill-warn"><strong>{{ $summary['total'] - $summary['treasurer'] }}</strong>Treasurer place vacant</div>
    <div class="summary-pill pill-info"><strong>{{ $summary['chairman'] }} of {{ $summary['total'] }}</strong>have an active Chairman</div>
</div>

<section class="card toolbar-card no-print">
    <form method="GET" action="{{ route('admin.barangays') }}" class="form toolbar-form">
        <div class="form-group">
            <label for="b_q">Search</label>
            <input id="b_q" type="text" name="q" value="{{ $term }}" placeholder="Barangay or person" maxlength="100">
        </div>
        <div class="form-group">
            <label for="b_show">Show</label>
            <select id="b_show" name="show">
                <option value="all" @selected($filter === 'all')>All barangays</option>
                <option value="vacant" @selected($filter === 'vacant')>Vacant only (no Treasurer)</option>
                <option value="active" @selected($filter === 'active')>With a Treasurer</option>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            @if ($filtered)<a href="{{ route('admin.barangays') }}" class="btn btn-ghost">Clear</a>@endif
        </div>
    </form>
</section>

<section class="card card-flush">
    @if ($rows->isEmpty())
        <div class="empty-state">
            <strong>No barangay matches</strong>
            Try a different search, or clear the filter.
        </div>
    @else
        <div class="table-wrap">
            <table class="table table-admin">
                <thead>
                    <tr>
                        <th>Barangay</th>
                        <th>District</th>
                        <th>SK Treasurer</th>
                        <th>SK Chairman</th>
                        <th>SK Secretary</th>
                        <th>Kagawads</th>
                        <th>Portal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $b         = $row['barangay'];
                            $treasurer = $row['treasurer'];
                            $chairman  = $row['chairman'];
                            $secretary = $row['secretary'];
                        @endphp
                        <tr>
                            <td class="cell-main">{{ $b->name }}</td>
                            <td>{{ $b->district ?: '—' }}</td>
                            <td>@if ($treasurer)<a href="{{ route('admin.accounts.show', $treasurer) }}">{{ $treasurer->name }}</a>@else <span class="muted" style="margin:0;">vacant</span>@endif</td>
                            <td>@if ($chairman)<a href="{{ route('admin.accounts.show', $chairman) }}">{{ $chairman->name }}</a>@else <span class="muted" style="margin:0;">vacant</span>@endif</td>
                            <td>@if ($secretary)<a href="{{ route('admin.accounts.show', $secretary) }}">{{ $secretary->name }}</a>@else <span class="muted" style="margin:0;">vacant</span>@endif</td>
                            <td>{{ $row['kagawads'] }} of {{ \App\Models\User::SEATS['kagawad'] }}</td>
                            <td>@if ($treasurer)<span class="badge badge-ok">Active</span>@else<span class="badge badge-off">Not started</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="result-note no-print">Showing {{ $rows->count() }} of {{ $summary['total'] }} barangays</p>
    @endif
</section>

@endsection
