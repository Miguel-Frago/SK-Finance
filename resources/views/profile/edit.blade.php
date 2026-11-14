@extends('layouts.app')

@section('title', 'My Profile')

@section('content')

<div class="page-head">
    <div>
        <h2 class="section-title">My profile</h2>
        <p class="muted">Your account details and password. Only you can see and change these.</p>
    </div>
</div>

<div class="profile-grid">
    {{-- ============ Account summary ============ --}}
    <section class="card profile-summary">
        @if ($user->photo_url)
            <img class="profile-avatar profile-avatar-img" src="{{ $user->photo_url }}" alt="Your photo">
        @else
            <span class="profile-avatar">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
        @endif
        <h3 class="card-title">{{ $user->name }}</h3>
        <p class="muted">{{ $user->email }}</p>
        <dl class="profile-facts">
            <div><dt>Role</dt><dd>{{ $user->role_label }}</dd></div>
            <div><dt>Barangay</dt><dd>{{ $user->portal()?->name ?? '—' }}</dd></div>
            <div><dt>Account created</dt><dd>{{ $user->created_at?->format('M d, Y') ?? '—' }}</dd></div>
            <div><dt>Last updated</dt><dd>{{ $user->updated_at?->format('M d, Y g:i A') ?? '—' }}</dd></div>
        </dl>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-secondary btn-block">Sign out</button>
        </form>
    </section>

    <div>
        {{-- ============ Account details ============ --}}
        <section class="card">
            <h3 class="card-title">Account details</h3>
            <form method="POST" action="{{ route('profile.update') }}" class="form form-grid">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="name">Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" maxlength="255" required autocomplete="name">
                    @error('name', 'profile')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="email">Email (used to sign in)</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="255" required autocomplete="username">
                    @error('email', 'profile')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide">
                    <label for="profile_current_password">Current password <small class="hint">(only needed when you change your email)</small></label>
                    <input id="profile_current_password" type="password" name="current_password" autocomplete="current-password">
                    @error('current_password', 'profile')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </section>

        {{-- ============ Barangay portal settings (treasurer's switch) ============ --}}
        @include('profile._barangay')

        {{-- ============ Photo and ID details ============ --}}
        @include('profile._details')

        {{-- ============ QR Access ID ============ --}}
        @include('profile._access-id')

        {{-- ============ Change password ============ --}}
        <section class="card">
            <h3 class="card-title">Change password</h3>
            <p class="muted">Use at least 8 characters, with letters and numbers.</p>
            <form method="POST" action="{{ route('profile.password') }}" class="form form-grid">
                @csrf
                @method('PUT')

                <div class="form-group form-wide">
                    <label for="current_password">Current password</label>
                    <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
                    @error('current_password', 'password')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="password">New password</label>
                    <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
                    @error('password', 'password')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm new password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Change password</button>
                </div>
            </form>
        </section>
    </div>
</div>

@endsection
