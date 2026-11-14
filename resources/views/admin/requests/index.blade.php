@extends('layouts.admin')

@section('title', 'Access requests')

@section('content')

@php
    $tabs = [
        'pending'    => 'Waiting for review',
        'approved'   => 'Code sent',
        'registered' => 'Account created',
        'denied'     => 'Denied',
    ];
    $badge    = ['pending' => 'badge-warn', 'approved' => 'badge-ok', 'registered' => 'badge-ok', 'denied' => 'badge-bad'];
    $filtered = $filters['q'] !== '' || $filters['barangay_id'] > 0;
    // the tabs keep the search and the barangay
    $keep     = array_filter(['q' => $filters['q'], 'barangay_id' => $filters['barangay_id'] ?: null]);
@endphp

<div class="page-head">
    <div>
        <h2 class="section-title">People asking to join</h2>
        <p class="muted">Check each request. Approving emails the person a secret code. A message keeps the request waiting while they fix a mistake.</p>
    </div>
</div>

<section class="card toolbar-card">
    <form method="GET" action="{{ route('admin.requests.index') }}" class="form toolbar-form">
        <input type="hidden" name="status" value="{{ $status }}">
        <div class="form-group">
            <label for="r_q">Search</label>
            <input id="r_q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Name or email" maxlength="100">
        </div>
        <div class="form-group">
            <label for="r_b">Barangay</label>
            <select id="r_b" name="barangay_id">
                <option value="">All barangays</option>
                @foreach ($barangays as $b)
                    <option value="{{ $b->id }}" @selected($filters['barangay_id'] === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Search</button>
            @if ($filtered)<a href="{{ route('admin.requests.index', ['status' => $status]) }}" class="btn btn-ghost">Clear</a>@endif
        </div>
    </form>
</section>

<nav class="tabs" aria-label="Request status">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('admin.requests.index', $keep + ['status' => $key]) }}" class="tab {{ $status === $key ? 'is-active' : '' }}" @if ($status === $key) aria-current="page" @endif>
            {{ $label }} <span class="tab-count">{{ $counts[$key] ?? 0 }}</span>
        </a>
    @endforeach
</nav>

<section class="card card-flush">
    @if ($requests->isEmpty())
        <div class="empty-state">
            <strong>No requests here</strong>
            {{ $filtered ? 'Nothing matches the search. Clear it to see every request in this tab.' : 'When someone asks to join, the request shows up in this list.' }}
        </div>
    @else
        <div class="table-wrap">
            <table class="table table-admin">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Barangay</th>
                        <th>Position</th>
                        <th>Sent</th>
                        <th>Status</th>
                        <th class="actions-col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $r)
                        <tr>
                            <td><span class="cell-main">{{ $r->list_name }}</span><span class="cell-sub">{{ $r->email }}</span></td>
                            <td>{{ $r->barangay->name }}</td>
                            <td>{{ $r->role_label }}</td>
                            <td style="white-space:nowrap;">{{ $r->created_at->format('M d, Y') }}</td>
                            <td><span class="badge {{ $badge[$r->status] ?? '' }}">{{ $r->status_label }}</span></td>
                            <td class="actions-col"><a href="{{ route('admin.requests.show', $r) }}" class="btn btn-small btn-secondary">Review</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="result-note">Showing {{ $requests->firstItem() }}&ndash;{{ $requests->lastItem() }} of {{ $requests->total() }}</p>
        @if ($requests->hasPages())<div class="card-pad">{{ $requests->links('partials.pagination') }}</div>@endif
    @endif
</section>

@endsection
