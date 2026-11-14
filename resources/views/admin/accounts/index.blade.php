@extends('layouts.admin')

@section('title', 'Accounts')

@section('content')

@php
    $tabs     = ['pending' => 'Waiting for approval', 'active' => 'Active', 'off' => 'Switched off', 'all' => 'All'];
    $filtered = $filters['q'] !== '' || $filters['barangay_id'] > 0;
    // the tabs keep the search and the barangay
    $keep     = array_filter(['q' => $filters['q'], 'barangay_id' => $filters['barangay_id'] ?: null]);
@endphp

<div class="page-head">
    <div>
        <h2 class="section-title">Barangay accounts</h2>
        <p class="muted">Approve new accounts, and switch an account off when a term ends. Switching off keeps everything the account recorded.</p>
    </div>
</div>

<section class="card toolbar-card">
    <form method="GET" action="{{ route('admin.accounts.index') }}" class="form toolbar-form">
        <input type="hidden" name="status" value="{{ $filter }}">
        <div class="form-group">
            <label for="a_q">Search</label>
            <input id="a_q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Name or email" maxlength="100">
        </div>
        <div class="form-group">
            <label for="a_b">Barangay</label>
            <select id="a_b" name="barangay_id">
                <option value="">All barangays</option>
                @foreach ($barangays as $b)
                    <option value="{{ $b->id }}" @selected($filters['barangay_id'] === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Search</button>
            @if ($filtered)<a href="{{ route('admin.accounts.index', ['status' => $filter]) }}" class="btn btn-ghost">Clear</a>@endif
        </div>
    </form>
</section>

<nav class="tabs" aria-label="Account status">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('admin.accounts.index', $keep + ['status' => $key]) }}" class="tab {{ $filter === $key ? 'is-active' : '' }}" @if ($filter === $key) aria-current="page" @endif>
            {{ $label }} <span class="tab-count">{{ $counts[$key] }}</span>
        </a>
    @endforeach
</nav>

<section class="card card-flush">
    @if ($accounts->isEmpty())
        <div class="empty-state">
            <strong>No accounts here</strong>
            {{ $filtered ? 'Nothing matches the search. Clear it to see every account in this tab.' : 'Accounts show up here after someone uses their secret code.' }}
        </div>
    @else
        <div class="table-wrap">
            <table class="table table-admin">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Barangay</th>
                        <th>Position</th>
                        <th>Last sign-in</th>
                        <th>Status</th>
                        <th class="actions-col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $a)
                        @php $seen = $lastSeen->get($a->id); @endphp
                        <tr>
                            <td><span class="cell-main">{{ $a->name }}</span><span class="cell-sub">{{ $a->email }}</span></td>
                            <td>{{ $a->barangay?->name ?? '—' }}</td>
                            <td>{{ $a->role_label }}</td>
                            <td style="white-space:nowrap;">
                                @if ($seen)
                                    {{ $seen->format('M d, Y') }}<span class="cell-sub">{{ $seen->diffForHumans() }}</span>
                                @else
                                    <span class="muted" style="margin:0;">Never</span>
                                @endif
                            </td>
                            <td>
                                @if (! $a->isApproved())
                                    <span class="badge badge-warn">Waiting for approval</span>
                                @elseif ($a->isActive())
                                    <span class="badge badge-ok">Active</span>
                                @else
                                    <span class="badge badge-off">Switched off</span>
                                @endif
                            </td>
                            <td class="actions-col"><a href="{{ route('admin.accounts.show', $a) }}" class="btn btn-small btn-secondary">Open</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="result-note">Showing {{ $accounts->firstItem() }}&ndash;{{ $accounts->lastItem() }} of {{ $accounts->total() }}</p>
        @if ($accounts->hasPages())<div class="card-pad">{{ $accounts->links('partials.pagination') }}</div>@endif
    @endif
</section>

@endsection
