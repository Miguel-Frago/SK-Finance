<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin sign in · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
</head>
<body class="ax-body">
<main class="ax-card ax-card-narrow ax-card-admin">
    <div class="ax-head">
        <div class="ax-pill">
            <span class="ax-pill-logo ax-pill-logo-bp"><img src="{{ asset('images/bagong-pilipinas.png') }}" alt="Bagong Pilipinas logo" width="60" height="57"></span>
            <span class="ax-pill-logo"><img src="{{ asset('images/luna-seal.png') }}" alt="Municipality of Luna seal" width="42" height="42"></span>
            <span class="ax-pill-logo"><img src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo" width="42" height="42"></span>
        </div>
        <h2>Admin sign in</h2>
        <p>SK Federation of Luna, La Union</p>
    </div>

    <form method="POST" action="{{ route('admin.login.attempt') }}" class="ax-form">
        @csrf

        @if (session('handover'))
            @php $handover = session('handover'); @endphp
            <div class="ax-alert ax-alert-warn" role="status">
                <div class="ax-alert-body">
                    <strong>Handover done.</strong>
                    The Admin account now belongs to <strong>{{ $handover['email'] }}</strong>.
                    @if ($handover['emailed'])
                        The temporary password was emailed to that address. It must be changed at the first sign-in.
                    @else
                        Give them this temporary password (shown only now): <code>{{ $handover['temp'] }}</code>. It must be changed at the first sign-in.
                    @endif
                </div>
            </div>
        @endif

        @if ($errors->any())
            <div class="ax-alert" role="alert">
                <div class="ax-alert-body">
                    @foreach ($errors->all() as $message)
                        <div>{{ $message }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="ax-field">
            <label for="ad_email">Email</label>
            <input id="ad_email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="you@example.com">
        </div>

        <div class="ax-field">
            <label for="ad_password">Password</label>
            <input id="ad_password" type="password" name="password" required autocomplete="current-password" placeholder="Your password">
        </div>

        <label class="ax-check">
            <input type="checkbox" name="remember" value="1"> Keep me signed in
        </label>

        <button type="submit" class="ax-btn ax-btn-primary">Sign in</button>
    </form>

    <p class="ax-links"><a href="{{ route('login') }}">Barangay sign in</a></p>
</main>
</body>
</html>
