<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Access ID · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
</head>
<body class="auth-body">
<main class="auth-card">
    <div class="auth-brand">
        <img src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo" width="64" height="64">
        <h1>SK Finance</h1>
        <p>Treasurer Portal Access ID</p>
    </div>

    <form method="POST" action="{{ route('qr.login', $token) }}" class="form" autocomplete="off">
        @csrf

        <div class="form-group">
            <label for="pin">Enter your 6-digit PIN</label>
            <input id="pin" class="pin-input" type="password" name="pin" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" required autofocus autocomplete="off" placeholder="••••••">
            @error('pin')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    <p class="muted" style="text-align:center; margin: 1rem 0 0; font-size:.85rem;">
        <a href="{{ route('login') }}">Use email and password instead</a>
    </p>
</main>
</body>
</html>
