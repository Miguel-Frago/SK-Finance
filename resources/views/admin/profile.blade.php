@extends('layouts.admin')

@section('title', 'My profile')

@push('head')
    <script src="{{ asset('js/password-toggle.js') }}" defer></script>
@endpush

@section('content')

@if ($admin->must_change_password)
    <div class="alert alert-warning" role="status">
        This account has a temporary password. Choose your own password below to continue.
    </div>
@endif

<div class="admin-grid admin-grid-even">
    <div>
        <section class="card">
            <h3 class="card-title">Change password</h3>
            <p class="muted">Use at least 8 characters, with letters and numbers. Every other device you are signed in on is signed out.</p>
            <form method="POST" action="{{ route('admin.profile.password') }}" class="form">
                @csrf
                @method('PUT')
                <div class="form-group">
                    <label for="pw_current">{{ $admin->must_change_password ? 'Temporary password' : 'Current password' }}</label>
                    <input id="pw_current" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="pw_new">New password</label>
                        <input id="pw_new" type="password" name="password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="pw_confirm">Repeat the new password</label>
                        <input id="pw_confirm" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save password</button>
            </form>
        </section>

        @unless ($admin->must_change_password)
            <section class="card">
                <h3 class="card-title">Your details</h3>
                <p class="muted">The name and email of the Admin. Type your password to confirm a change.</p>
                <form method="POST" action="{{ route('admin.profile.details') }}" class="form">
                    @csrf
                    @method('PUT')
                    <div class="form-group">
                        <label for="d_name">Name</label>
                        <input id="d_name" type="text" name="name" value="{{ old('name', $admin->name) }}" maxlength="255" required>
                    </div>
                    <div class="form-group">
                        <label for="d_email">Email</label>
                        <input id="d_email" type="email" name="email" value="{{ old('email', $admin->email) }}" maxlength="255" required>
                    </div>
                    <div class="form-group">
                        <label for="d_current">Current password</label>
                        <input id="d_current" type="password" name="current_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-secondary">Save details</button>
                </form>
            </section>
        @endunless
    </div>

    @unless ($admin->must_change_password)
        <div>
            <section class="card">
                <h3 class="card-title">Handover to a new president</h3>
                <p class="muted">
                    Use this when you are resigning or being replaced. The account stays, so every request, approval, account and
                    activity log line stays too. It is reset to the new president's name and email with a random temporary password
                    that they must replace at their first sign-in. You are signed out right away, on every device.
                </p>
                <form method="POST" action="{{ route('admin.profile.handover') }}" class="form">
                    @csrf
                    <div class="form-group">
                        <label for="h_name">New president's name <small class="hint">(optional)</small></label>
                        <input id="h_name" type="text" name="new_name" value="{{ old('new_name') }}" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label for="h_email">New president's email</label>
                        <input id="h_email" type="email" name="new_email" value="{{ old('new_email') }}" maxlength="255" required>
                    </div>
                    <label class="ax-check" style="margin-bottom:.9rem;">
                        <input type="checkbox" name="email_it" value="1" checked> Email the temporary password to the new president
                    </label>
                    <div class="form-group">
                        <label for="h_current">Your current password</label>
                        <input id="h_current" type="password" name="current_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-danger-outline" onclick="return confirm('Hand the Admin account over? You will be signed out.');">Hand over the account</button>
                </form>
            </section>

            @php
                $mailer   = (string) config('mail.default');
                $mailOff  = in_array($mailer, ['log', 'array'], true);
                $mailHost = config('mail.mailers.' . $mailer . '.host');
            @endphp
            <section class="card" id="test-email">
                <h3 class="card-title">Email check</h3>
                <p class="muted">Sends a short message to your own address ({{ $admin->email }}), so you can check the Gmail setup without approving a real request.</p>
                <div class="mail-status">
                    <span class="mail-dot {{ $mailOff ? 'is-off' : '' }}" aria-hidden="true"></span>
                    @if ($mailOff)
                        <span>Mail is set to <code>{{ $mailer }}</code>: emails are only written to the log file and nobody receives them.</span>
                    @else
                        <span>Mail is set to <code>{{ $mailer }}</code>@if ($mailHost) through <code>{{ $mailHost }}</code>@endif.</span>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.profile.test-email') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Send test email</button>
                </form>
            </section>
        </div>
    @endunless
</div>

@endsection