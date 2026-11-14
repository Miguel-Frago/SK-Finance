@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')

@php
    // "3 days ago", "today": how long a request has been waiting, with a colour once it is getting old
    $ageOf = function ($when) {
        $days = (int) $when->diffInDays(now());
        $text = $days === 0 ? 'today' : ($days === 1 ? '1 day' : $days . ' days');
        $tone = $days >= 7 ? 'age-bad' : ($days >= 3 ? 'age-warn' : '');

        return [$text, $tone];
    };
    $coverage = $counts['barangays'] > 0 ? round($counts['portals_active'] / $counts['barangays'] * 100) : 0;
@endphp

@if ($mailNotSending)
    <div class="mail-banner" role="alert">
        <span class="mail-banner-icon" aria-hidden="true">!</span>
        <div>
            <strong>Emails are not being sent.</strong>
            <p>
                The mail setting is <code>{{ config('mail.default') }}</code>, so approvals, messages and secret codes are only written to the log file and nobody receives them.
                Set <code>MAIL_MAILER=smtp</code> (with the Gmail details) in the <code>.env</code> file, then use
                <a href="{{ route('admin.profile') }}#test-email">Send test email</a> to check it.
            </p>
        </div>
    </div>
@endif

<div class="metric-grid metric-grid-admin">
    <a class="metric-card metric-left metric-link" href="{{ route('admin.requests.index') }}">
        <span class="metric-label">Requests waiting</span>
        <span class="metric-value">{{ $counts['requests_pending'] }}</span>
        <span class="metric-hint">People asking to join</span>
    </a>
    <a class="metric-card metric-cash metric-link" href="{{ route('admin.requests.index', ['status' => 'approved']) }}">
        <span class="metric-label">Codes sent, unused</span>
        <span class="metric-value">{{ $counts['codes_out'] }}</span>
        <span class="metric-hint">Approved, no account made</span>
    </a>
    <a class="metric-card metric-pending metric-link" href="{{ route('admin.accounts.index') }}">
        <span class="metric-label">Accounts to approve</span>
        <span class="metric-value">{{ $counts['accounts_pending'] }}</span>
        <span class="metric-hint">Made with a code, not checked yet</span>
    </a>
    <a class="metric-card metric-ok metric-link" href="{{ route('admin.accounts.index', ['status' => 'active']) }}">
        <span class="metric-label">Active accounts</span>
        <span class="metric-value">{{ $counts['accounts_active'] }}</span>
        <span class="metric-hint">Can sign in now</span>
    </a>
    <a class="metric-card metric-off metric-link" href="{{ route('admin.accounts.index', ['status' => 'off']) }}">
        <span class="metric-label">Switched-off accounts</span>
        <span class="metric-value">{{ $counts['accounts_off'] }}</span>
        <span class="metric-hint">Ended terms and resignations</span>
    </a>
    <a class="metric-card metric-total metric-link" href="{{ route('admin.barangays') }}">
        <span class="metric-label">Portals with a Treasurer</span>
        <span class="metric-value">{{ $counts['portals_active'] }} <small>of {{ $counts['barangays'] }}</small></span>
        <span class="metric-hint">Barangay coverage</span>
    </a>
</div>

<section class="card">
    <div class="coverage">
        <div class="coverage-text"><strong>{{ $counts['portals_active'] }} of {{ $counts['barangays'] }}</strong> barangays have an active Treasurer</div>
        <div class="coverage-bar" role="img" aria-label="{{ $coverage }} percent of barangays covered"><span style="width: {{ $coverage }}%;"></span></div>
        <a href="{{ route('admin.barangays', ['show' => 'vacant']) }}" class="btn btn-small btn-secondary">See the vacant ones</a>
    </div>
</section>

{{-- Needs attention: the oldest waiting items, and codes that are about to stop working --}}
<div class="attention-grid">
    <section class="card">
        <div class="attn-head">
            <h3 class="card-title">Oldest requests waiting</h3>
            <a href="{{ route('admin.requests.index') }}" class="btn btn-small btn-ghost">All</a>
        </div>
        @if ($oldestRequests->isEmpty())
            <p class="attn-clear">No request is waiting.</p>
        @else
            <ul class="attn-list">
                @foreach ($oldestRequests as $r)
                    @php [$age, $tone] = $ageOf($r->created_at); @endphp
                    <li class="attn-item">
                        <div class="attn-who">
                            <strong>{{ $r->list_name }}</strong>
                            <span>{{ $r->role_label }}, {{ $r->barangay->name }} &middot; <span class="age-chip {{ $tone }}">{{ $age }}</span></span>
                        </div>
                        <a href="{{ route('admin.requests.show', $r) }}" class="btn btn-small btn-secondary">Review</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card">
        <div class="attn-head">
            <h3 class="card-title">Accounts waiting for approval</h3>
            <a href="{{ route('admin.accounts.index') }}" class="btn btn-small btn-ghost">All</a>
        </div>
        @if ($oldestAccounts->isEmpty())
            <p class="attn-clear">No account is waiting.</p>
        @else
            <ul class="attn-list">
                @foreach ($oldestAccounts as $a)
                    @php [$age, $tone] = $ageOf($a->created_at); @endphp
                    <li class="attn-item">
                        <div class="attn-who">
                            <strong>{{ $a->name }}</strong>
                            <span>{{ $a->role_label }}, {{ $a->barangay?->name ?? '—' }} &middot; <span class="age-chip {{ $tone }}">{{ $age }}</span></span>
                        </div>
                        <a href="{{ route('admin.accounts.show', $a) }}" class="btn btn-small btn-secondary">Review</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card">
        <div class="attn-head">
            <h3 class="card-title">Secret codes running out</h3>
            <a href="{{ route('admin.requests.index', ['status' => 'approved']) }}" class="btn btn-small btn-ghost">All</a>
        </div>
        @if ($expiringCodes->isEmpty())
            <p class="attn-clear">No code ends in the next 2 days.</p>
        @else
            <ul class="attn-list">
                @foreach ($expiringCodes as $r)
                    @php $expired = $r->code_expires_at->isPast(); @endphp
                    <li class="attn-item">
                        <div class="attn-who">
                            <strong>{{ $r->list_name }}</strong>
                            <span>
                                {{ $r->role_label }}, {{ $r->barangay->name }} &middot;
                                <span class="age-chip {{ $expired ? 'age-bad' : 'age-warn' }}">{{ $expired ? 'expired ' . $r->code_expires_at->format('M d') : 'ends ' . $r->code_expires_at->format('M d') }}</span>
                            </span>
                        </div>
                        <form method="POST" action="{{ route('admin.requests.approve', $r) }}">
                            @csrf
                            <button type="submit" class="btn btn-small btn-primary">Resend code</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

<section class="card">
    <div class="attn-head">
        <h3 class="card-title">Latest activity</h3>
        @if ($recent->isNotEmpty())<a href="{{ route('admin.activity') }}" class="btn btn-small btn-ghost">This month's log</a>@endif
    </div>
    @if ($recent->isEmpty())
        <p class="empty">Nothing has happened yet.</p>
    @else
        @include('admin._log-table', ['logs' => $recent])
    @endif
</section>

@endsection
