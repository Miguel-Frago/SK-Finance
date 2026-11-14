@extends('layouts.admin')

@section('title', 'Access request')

@section('content')

@php
    $badge   = ['pending' => 'badge-warn', 'approved' => 'badge-ok', 'registered' => 'badge-ok', 'denied' => 'badge-bad'];
    $isOpen  = in_array($r->status, ['pending', 'approved'], true);
@endphp

<div class="page-head">
    <div class="detail-hero">
        <span class="detail-avatar" aria-hidden="true">{{ strtoupper(mb_substr($r->first_name, 0, 1)) }}</span>
        <div>
            <h2 class="section-title">{{ $r->full_name }}</h2>
            <p class="muted">{{ $r->role_label }} of {{ $r->barangay->name }} &middot; <span class="badge {{ $badge[$r->status] ?? '' }}">{{ $r->status_label }}</span></p>
        </div>
    </div>
    <div class="page-actions"><a href="{{ route('admin.requests.index') }}" class="btn btn-secondary">&lsaquo; Back to the list</a></div>
</div>

<div class="admin-grid">
    <section class="card facts-card">
        <h3 class="card-title">Details to check</h3>
        <dl class="profile-facts">
            <div><dt>Last name</dt><dd>{{ $r->last_name }}</dd></div>
            <div><dt>First name</dt><dd>{{ $r->first_name }}</dd></div>
            <div><dt>Middle name</dt><dd>{{ $r->middle_name ?: '—' }}</dd></div>
            <div><dt>Email</dt><dd>{{ $r->email }}</dd></div>
            <div><dt>Barangay</dt><dd>{{ $r->barangay->name }}</dd></div>
            <div><dt>Position</dt><dd>{{ $r->role_label }}</dd></div>
            <div><dt>Request sent</dt><dd>{{ $r->created_at->format('M d, Y g:i A') }}</dd></div>
            @if ($r->status === 'approved')
                <div><dt>Secret code</dt><dd>sent, expires {{ $r->code_expires_at?->format('M d, Y') }}</dd></div>
            @endif
            @if ($r->status === 'registered')
                <div><dt>Account</dt><dd>@if ($r->user)<a href="{{ route('admin.accounts.show', $r->user) }}">open the account</a>@else — @endif</dd></div>
            @endif
        </dl>

        @if ($seatBlock && $isOpen)
            <div class="alert alert-warning alert-inline">{{ $seatBlock }}</div>
        @endif

        @if ($r->last_message)
            <div class="note-block">
                <h4>Last note sent{{ $r->last_message_at ? ' (' . $r->last_message_at->format('M d, Y') . ')' : '' }}</h4>
                <p>{{ $r->last_message }}</p>
            </div>
        @endif
    </section>

    <div>
        @if ($isOpen)
            <section class="card action-card">
                <h3 class="card-title">{{ $r->status === 'approved' ? 'Send a new secret code' : 'Approve' }}</h3>
                <p class="muted">The person receives an email with a secret code. It works once and expires in {{ config('sk.code_days') }} days.</p>
                <form method="POST" action="{{ route('admin.requests.approve', $r) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary" @disabled($seatBlock)>{{ $r->status === 'approved' ? 'Email a new code' : 'Approve and email the code' }}</button>
                </form>
            </section>

            <section class="card action-card">
                <h3 class="card-title">Something looks wrong?</h3>
                <p class="muted">Write to them at {{ $r->email }}. The request stays as it is; nothing is approved or rejected.</p>
                <form method="POST" action="{{ route('admin.requests.message', $r) }}" class="form">
                    @csrf
                    <div class="form-group">
                        <label for="msg">Your message</label>
                        <textarea id="msg" name="message" rows="4" maxlength="2000" required placeholder="e.g. Your last name is spelled differently on the SK roster. Please reply with the correct spelling.">{{ old('message') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-secondary">Email the message</button>
                </form>
            </section>

            <section class="card action-card">
                <h3 class="card-title">Deny</h3>
                <p class="muted">The person is told, and may send a new request.</p>
                <form method="POST" action="{{ route('admin.requests.deny', $r) }}" class="form">
                    @csrf
                    <div class="form-group">
                        <label for="reason">Reason <small class="hint">(optional, included in the email)</small></label>
                        <input id="reason" type="text" name="reason" maxlength="500" value="{{ old('reason') }}">
                    </div>
                    <button type="submit" class="btn btn-danger-outline" onclick="return confirm('Deny this request?');">Deny the request</button>
                </form>
            </section>
        @else
            <section class="card">
                <p class="muted" style="margin:0;">This request is finished ({{ strtolower($r->status_label) }}).</p>
            </section>
        @endif
    </div>
</div>

@endsection
