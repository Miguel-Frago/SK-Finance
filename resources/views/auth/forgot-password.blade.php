<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
</head>
<body class="ax-body">
<main class="ax-card">
    <div class="ax-head">
        @include('auth._logos')
        <h2>Forgot your password?</h2>
        <p>Type the email of your account. If it belongs to an active account, we send a link to choose a new password.</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="ax-form">
        @csrf
        <div class="ax-field">
            <label for="fp_email">Email</label>
            <input id="fp_email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="you@example.com">
            @error('email')<span class="ax-error">{{ $message }}</span>@enderror
        </div>
        <button type="submit" class="ax-btn ax-btn-primary">Send the link</button>
    </form>

    <p class="ax-links">
        No email access? Ask the SK Federation (the Admin) for a temporary password.
        &middot; <a href="{{ route('login') }}">Back to sign in</a>
    </p>
</main>
</body>
</html>
