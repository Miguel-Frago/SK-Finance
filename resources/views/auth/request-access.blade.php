<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request access · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
</head>
<body class="ax-body">
@php $barangays = \App\Models\Barangay::orderBy('name')->get(['id', 'name']); @endphp

<main class="ax-card">
    <div class="ax-head">
        <div class="ax-pill ax-pill-sm">
                <span class="ax-pill-logo ax-pill-logo-bp"><img src="{{ asset('images/bagong-pilipinas.png') }}" alt="Bagong Pilipinas logo" width="60" height="57"></span>
                <span class="ax-pill-logo"><img src="{{ asset('images/luna-seal.png') }}" alt="Municipality of Luna seal" width="42" height="42"></span>
                <span class="ax-pill-logo"><img src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo" width="42" height="42"></span>
            </div>
        <h2>Request access</h2>
        <p>Send your details. The SK Federation checks them and emails you a secret code to make your account.</p>
    </div>

    <form method="POST" action="{{ route('request-access.store') }}" class="ax-form">
        @csrf

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
            <label for="ra_email">Email <small>(a working Gmail you can open)</small></label>
            <input id="ra_email" type="email" name="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email" placeholder="you@gmail.com">
        </div>

        <div class="ax-row">
            <div class="ax-field">
                <label for="ra_last">Last name</label>
                <input id="ra_last" type="text" name="last_name" value="{{ old('last_name') }}" maxlength="100" required autocomplete="family-name" placeholder="Dela Cruz">
            </div>
            <div class="ax-field">
                <label for="ra_first">First name</label>
                <input id="ra_first" type="text" name="first_name" value="{{ old('first_name') }}" maxlength="100" required autocomplete="given-name" placeholder="Juan">
            </div>
        </div>

        <div class="ax-field">
            <label for="ra_middle">Middle name <small>(optional)</small></label>
            <input id="ra_middle" type="text" name="middle_name" value="{{ old('middle_name') }}" maxlength="100" autocomplete="additional-name" placeholder="Santos">
        </div>

        <div class="ax-row">
            <div class="ax-field">
                <label for="ra_barangay">Barangay</label>
                <select id="ra_barangay" name="barangay_id" required>
                    <option value="">Choose your barangay</option>
                    @foreach ($barangays as $barangayOption)
                        <option value="{{ $barangayOption->id }}" @selected((int) old('barangay_id') === $barangayOption->id)>{{ $barangayOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ax-field">
                <label for="ra_role">Position</label>
                <select id="ra_role" name="role" required>
                    <option value="">Choose position</option>
                    @foreach (\App\Models\User::SIGNUP_ROLES as $roleKey => $roleName)
                        <option value="{{ $roleKey }}" @selected(old('role') === $roleKey)>{{ $roleName }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <button type="submit" class="ax-btn ax-btn-primary">Send request</button>
    </form>

    <p class="ax-links">
        Already have a secret code? <a href="{{ route('register') }}">Create account</a>
        &middot; <a href="{{ route('login') }}">Sign in</a>
    </p>
</main>
</body>
</html>
