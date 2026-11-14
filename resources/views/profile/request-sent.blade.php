<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request sent · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
</head>
<body class="ax-body">
<main class="ax-card">
    <div class="ax-head">
        <img class="ax-logo" src="{{ asset('images/sk-logo.png') }}" alt="SK logo" width="56" height="56">
        <h2>Request sent</h2>
        <p>The SK Federation will check your details and email you. If everything is correct you will get a secret code to make your account. If something needs fixing, they will write to you at the same email.</p>
    </div>

    <p class="ax-links">
        Got your secret code? <a href="{{ route('register') }}">Create account</a>
        &middot; <a href="{{ route('login') }}">Back to sign in</a>
    </p>
</main>
</body>
</html>
