@extends('layouts.admin')

@section('title', 'Account')

@section('content')

@php
    $req       = $a->accessRequest;
    $isPending = ! $a->isApproved();
    $nameInReq = $req?->full_name;
    // the name typed on the account vs the name in the approved request (spaces and capitals ignored)
    $norm      = fn (?string $v) => strtolower(preg_replace('/\s+/', ' ', trim((string) $v)));
    $nameDiffers = $req && $norm($a->name) !== $norm($nameInReq);
@endphp

<div class="page-head">
    <div class="detail-hero">
        <span class="detail-avatar" aria-hidden="true">{{ strtoupper(mb_substr($a->name, 0, 1)) }}</span>
        <div>
            <h2 class="section-title">{{ $a->name }}</h2>
            <p class="muted">
                {{ $a->role_label }} of {{ $a->barangay?->name ?? '—' }} &middot;
                @if ($isPending)<span class="badge badge-warn">Waiting for approval</span>
                @elseif ($a->isActive())<span class="badge badge-ok">Active</span>
                @else<span class="badge badge-off">Switched off</span>@endif
            </p>
        </div>
    </div>
    @if (session('temp_password'))
        <section class="card action-card">
            <h3 class="card-title">Temporary password for {{ $a->name }}</h3>
            <p><span class="code-box">{{ session('temp_password') }}</span></p>
            <p class="muted">
                Give it to the person yourself (in person or by phone). <strong>It is shown only now.</strong>
                They must choose their own password the next time they sign in, and they were signed out everywhere.
                @if (session('temp_emailed') === 'yes') It was also emailed to {{ $a->email }}.
                @elseif (session('temp_emailed') === 'failed') The email could not be sent: tell them the password another way.
                @endif
            </p>
        </section>
    @endif

    <div class="page-actions"><a href="{{ route('admin.accounts.index') }}" class="btn btn-secondary">&lsaquo; Back to the list</a></div>
</div>

<div class="admin-grid">
    <section class="card facts-card">
        <h3 class="card-title">Details to check</h3>
        <dl class="profile-facts">
            <div><dt>Name on the account</dt><dd class="{{ $nameDiffers ? 'mismatch' : '' }}">{{ $a->name }}</dd></div>
            @if ($req)
                <div><dt>Name in the approved request</dt><dd>{{ $nameInReq }}</dd></div>
            @endif
            <div><dt>Email</dt><dd>{{ $a->email }}</dd></div>
            <div><dt>Barangay</dt><dd>{{ $a->barangay?->name ?? '—' }}</dd></div>
            <div><dt>Position</dt><dd>{{ $a->role_label }}</dd></div>
            <div><dt>Account made</dt><dd>{{ $a->created_at?->format('M d, Y g:i A') }}</dd></div>
            @if ($a->approved_at && ! $isPending)
                <div><dt>Approved</dt><dd>{{ $a->approved_at->format('M d, Y') }}</dd></div>
            @endif
            @if ($a->deactivated_at && ! $a->isActive())
                <div><dt>Switched off</dt><dd>{{ $a->deactivated_at->format('M d, Y') }}</dd></div>
            @endif
            @unless ($isPending)
                <div><dt>Last sign-in</dt><dd>{{ $lastSeen ? $lastSeen->format('M d, Y g:i A') . ' (' . $lastSeen->diffForHumans() . ')' : 'Never' }}</dd></div>
            @endunless
        </dl>

        @if ($nameDiffers)
            <div class="alert alert-warning alert-inline">The name typed on the account is different from the name in the request. Check it, and message the person if it is a mistake.</div>
        @endif

        @if ($seatBlock && ($isPending || ! $a->isActive()))
            <div class="alert alert-warning alert-inline">{{ $seatBlock }}</div>
        @endif

        @if ($req?->last_message)
            <div class="note-block">
                <h4>Last note sent</h4>
                <p>{{ $req->last_message }}</p>
            </div>
        @endif
    </section>

    <div>
        @if ($isPending)
            <section class="card action-card">
                <h3 class="card-title">Approve</h3>
                <p class="muted">After approval the person can sign in, and can make their QR Access ID.</p>
                <form method="POST" action="{{ route('admin.accounts.approve', $a) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary" @disabled($seatBlock)>Approve the account</button>
                </form>
            </section>

            <section class="card action-card">
                <h3 class="card-title">Something looks wrong?</h3>
                <p class="muted">Write to them at {{ $a->email }}. The account stays pending.</p>
                <form method="POST" action="{{ route('admin.accounts.message', $a) }}" class="form">
                    @csrf
                    <div class="form-group">
                        <label for="msg">Your message</label>
                        <textarea id="msg" name="message" rows="4" maxlength="2000" required>{{ old('message') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-secondary">Email the message</button>
                </form>
            </section>

            <section class="card action-card">
                <h3 class="card-title">Deny</h3>
                <p class="muted">The pending account is removed and the person may send a new request.</p>
                <form method="POST" action="{{ route('admin.accounts.deny', $a) }}" class="form">
                    @csrf
                    <div class="form-group">
                        <label for="reason">Reason <small class="hint">(optional, included in the email)</small></label>
                        <input id="reason" type="text" name="reason" maxlength="500" value="{{ old('reason') }}">
                    </div>
                    <button type="submit" class="btn btn-danger-outline" onclick="return confirm('Deny and remove this pending account?');">Deny the account</button>
                </form>
            </section>
        @elseif ($a->isActive())
            <section class="card action-card">
                <h3 class="card-title">Lost password</h3>
                <p class="muted">If this person cannot sign in, give a temporary password. It is shown once, and they must choose their own at the next sign-in.</p>
                <form method="POST" action="{{ route('admin.accounts.password', $a) }}">
                    @csrf
                    <label class="ax-check"><input type="checkbox" name="email" value="1"> Also email it to {{ $a->email }}</label>
                    <button type="submit" class="btn btn-secondary" onclick="return confirm('Give {{ $a->name }} a temporary password? Their old password stops working now.');">Give a temporary password</button>
                </form>
            </section>
            <section class="card action-card">
                <h3 class="card-title">End of term or resignation</h3>
                <p class="muted">Switching the account off stops it from signing in. Everything it recorded stays in the barangay, so the new appointee can take over the portal.</p>
                <form method="POST" action="{{ route('admin.accounts.disable', $a) }}">
                    @csrf
                    <button type="submit" class="btn btn-danger-outline" onclick="return confirm('Switch this account off?');">Switch the account off</button>
                </form>
            </section>
        @else
            <section class="card action-card">
                <h3 class="card-title">Switched off</h3>
                <p class="muted">This account cannot sign in. Switch it on again only if it was switched off by mistake.</p>
                <form method="POST" action="{{ route('admin.accounts.enable', $a) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary" @disabled($seatBlock)>Switch the account on</button>
                </form>
            </section>
        @endif
    </div>
</div>

@endsection
