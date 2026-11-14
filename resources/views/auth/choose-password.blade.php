<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose your password · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
</head>
<body class="ax-body">
<main class="ax-card">
    <div class="ax-head">
        @include('auth._logos')
        <h2>Choose your own password</h2>
        <p>You signed in with a temporary password from the SK Federation. Choose a password only you know. Until then the rest of the system stays closed.</p>
    </div>

    @if (session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif

    <form method="POST" action="{{ route('password.choose.update') }}" class="ax-form">
        @csrf
        @method('PUT')
        <div class="ax-field">
            <label for="cp_password">New password</label>
            <input id="cp_password" type="password" name="password" required autocomplete="new-password" autofocus>
            <small class="muted">At least 8 characters, with letters and numbers.</small>
            @error('password')<span class="ax-error">{{ $message }}</span>@enderror
        </div>
        <div class="ax-field">
            <label for="cp_confirm">Type it again</label>
            <input id="cp_confirm" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button type="submit" class="ax-btn ax-btn-primary">Save my password</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="ax-links">
        @csrf
        <button type="submit" class="link-action">Sign out</button>
    </form>
</main>
</body>
</html>
