<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ($mode ?? 'login') === 'register' ? 'Create account' : 'Sign in' }} · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
</head>
<body class="ax-body">
@php
    // Starts on the "Create account" side when asked for, or when creating an account failed
    $mode             = $mode ?? ($errors->hasBag('register') ? 'register' : 'login');
    $isRegister       = $mode === 'register';
    $registrationOpen = true;   // anyone can try; the secret code from the Admin decides
    $regErrors        = $errors->getBag('register');
    $barangays        = \App\Models\Barangay::orderBy('name')->get(['id', 'name']);
@endphp

<main class="ax-shell {{ $isRegister ? 'is-register' : '' }}" id="axShell"
      data-login-url="{{ route('login') }}" data-register-url="{{ route('register') }}">

    {{-- ============ Sign in (right side) ============ --}}
    <section class="ax-pane ax-pane-login" aria-labelledby="axLoginTitle" @if ($isRegister) inert @endif>
        <div class="ax-head">
            <div class="ax-pill ax-pill-sm">
                <span class="ax-pill-logo ax-pill-logo-bp"><img src="{{ asset('images/bagong-pilipinas.png') }}" alt="Bagong Pilipinas logo" width="60" height="57"></span>
                <span class="ax-pill-logo"><img src="{{ asset('images/luna-seal.png') }}" alt="Municipality of Luna seal" width="42" height="42"></span>
                <span class="ax-pill-logo"><img src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo" width="42" height="42"></span>
            </div>
            <h2 id="axLoginTitle">Sign in</h2>
            <p>Use your SK Treasurer or SK Chairman account to continue.</p>
        </div>

        <form method="POST" action="{{ route('login.attempt') }}" class="ax-form">
            @csrf

            <div class="ax-field">
                <label for="login_email">Email</label>
                <input id="login_email" type="email" name="email" value="{{ $isRegister ? '' : old('email') }}"
                       required autocomplete="username" placeholder="you@example.com">
                @if (! $isRegister)
                    @error('email')<span class="ax-error">{{ $message }}</span>@enderror
                @endif
            </div>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <div class="ax-field">
                <label for="login_password">Password</label>
                <div class="ax-input-wrap">
                    <input id="login_password" type="password" name="password" required autocomplete="current-password" placeholder="Your password">
                    <button type="button" class="ax-toggle" data-toggle="login_password" aria-pressed="false">Show</button>
                </div>
            </div>

            <label class="ax-check">
                <input type="checkbox" name="remember" value="1"> Keep me signed in
            </label>
            <p class="ax-links"><a href="{{ route('password.request') }}">Forgot your password?</a></p>

            <button type="submit" class="ax-btn ax-btn-primary">Sign in</button>
        </form>

        <div class="ax-switch">
            <span>Have a secret code?</span>
            <a href="{{ route('register') }}" class="ax-btn ax-btn-outline" data-switch="register">Create account</a>
        </div>
        <p class="ax-links">New here? <a href="{{ route('request-access') }}">Request access</a> from the Admin first.</p>
    </section>

    {{-- ============ Create account (left side) ============ --}}
    <section class="ax-pane ax-pane-register" aria-labelledby="axRegTitle" @if (! $isRegister) inert @endif>
        <div class="ax-head">
            <div class="ax-pill ax-pill-sm">
                <span class="ax-pill-logo ax-pill-logo-bp"><img src="{{ asset('images/bagong-pilipinas.png') }}" alt="Bagong Pilipinas logo" width="60" height="57"></span>
                <span class="ax-pill-logo"><img src="{{ asset('images/luna-seal.png') }}" alt="Municipality of Luna seal" width="42" height="42"></span>
                <span class="ax-pill-logo"><img src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo" width="42" height="42"></span>
            </div>
            <h2 id="axRegTitle">Create account</h2>
            <p>Use the secret code the Admin emailed you.</p>
        </div>

        <form method="POST" action="{{ route('register.attempt') }}" class="ax-form">
            @csrf

            @if ($regErrors->any())
                <div class="ax-alert" role="alert">
                    <div class="ax-alert-body">
                        @foreach ($regErrors->all() as $message)
                            <div>{{ $message }}</div>
                        @endforeach
                    </div>
                </div>
            @endif


            <fieldset class="ax-fieldset">

            <p class="ax-hint" style="margin:0 0 .75rem;">Your name is taken from your approved request, so you do not type it here.</p>

            <div class="ax-field">
                <label for="reg_email">Email</label>
                <input id="reg_email" type="email" name="email" value="{{ $isRegister ? old('email') : '' }}" maxlength="255" required autocomplete="email" placeholder="you@example.com">
            </div>

            <div class="ax-field">
                <label for="reg_barangay">Barangay</label>
                <select id="reg_barangay" name="barangay_id" required>
                    <option value="">Choose your barangay</option>
                    @foreach ($barangays as $barangayOption)
                        <option value="{{ $barangayOption->id }}" @selected($isRegister && (int) old('barangay_id') === $barangayOption->id)>{{ $barangayOption->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ax-row">
                <div class="ax-field">
                    <label for="reg_role">Register as</label>
                    <select id="reg_role" name="role" required>
                        <option value="">Choose position</option>
                        @foreach (\App\Models\User::SIGNUP_ROLES as $roleKey => $roleName)
                            <option value="{{ $roleKey }}" @selected($isRegister && old('role') === $roleKey)>{{ $roleName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ax-field">
                    <label for="reg_code">Secret code</label>
                    <input id="reg_code" type="text" name="access_code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="e.g. K7M2P-9QX4R">
                </div>
            </div>

            <div class="ax-row">
                <div class="ax-field">
                    <label for="reg_password">Password</label>
                    <div class="ax-input-wrap">
                        <input id="reg_password" type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="8+ characters">
                        <button type="button" class="ax-toggle" data-toggle="reg_password" aria-pressed="false">Show</button>
                    </div>
                </div>
                <div class="ax-field">
                    <label for="reg_password_confirmation">Confirm</label>
                    <input id="reg_password_confirmation" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" placeholder="Repeat it">
                </div>
            </div>
            <p class="ax-hint">Use letters and numbers.</p>

            <button type="submit" class="ax-btn ax-btn-primary">Create account</button>
            </fieldset>
        </form>

        <p class="ax-links">No secret code yet? <a href="{{ route('request-access') }}">Request access</a></p>

        <div class="ax-switch ax-switch-mobile">
            <span>Already have an account?</span>
            <a href="{{ route('login') }}" class="ax-btn ax-btn-outline" data-switch="login">Sign in</a>
        </div>
    </section>

    {{-- ============ The sliding panel ============ --}}
    <aside class="ax-overlay" aria-live="polite">
        <div class="ax-ov ax-ov-login">
            <img class="ax-ov-logo" src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo" width="96" height="96">
            <h2>Welcome to<br>SK Finance</h2>
            <p>Keep the Sangguniang Kabataan budget clear, honest and easy to report on, all in one place.</p>
            <ul class="ax-points">
                <li>Budget and allocation by category</li>
                <li>Expenses with receipts and vouchers</li>
                <li>Journal and ledger with running balances</li>
            </ul>
        </div>

        <div class="ax-ov ax-ov-register">
            <img class="ax-ov-logo" src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo" width="96" height="96">
            <h2>Create your<br>account</h2>
            <p>Create your account to set up your dashboard and start tracking your SK budget, spending and funds.</p>
            <a href="{{ route('login') }}" class="ax-btn ax-btn-light" data-switch="login">I already have an account</a>
        </div>
    </aside>
</main>

<script>
    (function () {
        var shell = document.getElementById('axShell');
        var login = shell.querySelector('.ax-pane-login');
        var register = shell.querySelector('.ax-pane-register');

        // Slide between the two sides
        function setMode(mode, fromClick) {
            var isRegister = mode === 'register';
            shell.classList.toggle('is-register', isRegister);
            login.inert = isRegister;
            register.inert = !isRegister;
            document.title = (isRegister ? 'Create account' : 'Sign in') + ' \u00b7 SK Financial Tracking';

            if (fromClick) {
                history.replaceState(null, '', isRegister ? shell.dataset.registerUrl : shell.dataset.loginUrl);
            }

            // Focus the first field once the slide is done (and on first load)
            setTimeout(function () {
                var first = (isRegister ? register : login).querySelector('input');
                if (first) { first.focus({ preventScroll: true }); }
            }, fromClick ? 650 : 50);
        }

        shell.querySelectorAll('[data-switch]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                setMode(link.dataset.switch, true);
            });
        });

        // Show / hide password
        document.querySelectorAll('[data-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.dataset.toggle);
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'Hide' : 'Show';
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
        });

        setMode(shell.classList.contains('is-register') ? 'register' : 'login', false);
    })();
</script>
</body>
</html>
