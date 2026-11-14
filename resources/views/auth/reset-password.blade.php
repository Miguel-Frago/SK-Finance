<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a new password · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
</head>
<body class="ax-body">
<main class="ax-card">
    <div class="ax-head">
        @include('auth._logos')
        <h2>Choose a new password</h2>
        <p>At least 8 characters, with letters and numbers.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="ax-form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="ax-field">
            <label for="rp_email">Email</label>
            <input id="rp_email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username">
            @error('email')<span class="ax-error">{{ $message }}</span>@enderror
        </div>
        <div class="ax-field">
            <label for="rp_password">New password</label>
            <input id="rp_password" type="password" name="password" required autocomplete="new-password">
            @error('password')<span class="ax-error">{{ $message }}</span>@enderror
        </div>
        <div class="ax-field">
            <label for="rp_confirm">Type it again</label>
            <input id="rp_confirm" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button type="submit" class="ax-btn ax-btn-primary">Save the new password</button>
    </form>

    <p class="ax-links"><a href="{{ route('password.request') }}">Ask for a new link</a> &middot; <a href="{{ route('login') }}">Back to sign in</a></p>
</main>
</body>
</html>
