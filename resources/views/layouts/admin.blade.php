<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') · SK Finance Admin</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    {{-- the Admin's own colour theme: applied before the page is painted, so there is no flash --}}
    <script>window.SK_THEME = @json(auth('admin')->check() ? auth('admin')->user()->theme : null);</script>
    <script src="{{ asset('js/theme.js') }}"></script>
    @stack('head')
</head>
<body data-live-url="{{ route('admin.live') }}" data-live-every="5000">
@php
    $adminNav = [
        ['label' => 'Dashboard',       'route' => 'admin.dashboard',      'match' => 'admin.dashboard',  'count' => 0],
        ['label' => 'Access requests', 'route' => 'admin.requests.index', 'match' => 'admin.requests.*', 'count' => $waiting['requests'] ?? 0],
        ['label' => 'Accounts',        'route' => 'admin.accounts.index', 'match' => 'admin.accounts.*', 'count' => $waiting['accounts'] ?? 0],
        ['label' => 'Barangays',       'route' => 'admin.barangays',      'match' => 'admin.barangays',  'count' => 0],
        ['label' => 'Activity log',    'route' => 'admin.activity',       'match' => 'admin.activity',   'count' => 0],
        ['label' => 'Log archive',     'route' => 'admin.archive.index',  'match' => 'admin.archive.*',  'count' => 0],
        ['label' => 'Unlock requests', 'route' => 'admin.unlocks',        'match' => 'admin.unlocks*',   'count' => $waiting['unlocks'] ?? 0],
        ['label' => 'System check',    'route' => 'admin.system',         'match' => 'admin.system',     'count' => 0],
    ];
@endphp

<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <button type="button" class="nav-close no-print" id="navClose" aria-label="Close menu">&times;</button>
        <div class="sidebar-brand sidebar-brand-admin">
            <span class="brand-logos">
                <img class="brand-logo brand-logo-sm" src="{{ asset('images/luna-seal.png') }}" alt="Municipality of Luna seal" width="66" height="66">
                <img class="brand-logo brand-logo-sm" src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo" width="66" height="66">
            </span>
            <div>
                <strong>SK Finance</strong>
                <small>Admin &middot; SK Federation of Luna</small>
            </div>
        </div>

        <nav class="sidebar-nav">
            @foreach ($adminNav as $item)
                <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                    {{ $item['label'] }}
                    @if ($item['count'] > 0)<span class="nav-count">{{ $item['count'] }}</span>@endif
                </a>
            @endforeach
        </nav>

        <div class="sidebar-footer">
            {{-- the name opens the Admin's profile (details, password, handover), like the other accounts --}}
            <a href="{{ route('admin.profile') }}" class="sidebar-user {{ request()->routeIs('admin.profile*') ? 'active' : '' }}" title="My profile">
                <span class="user-avatar">{{ strtoupper(substr(auth('admin')->user()->name, 0, 1)) }}</span>
                <span class="user-name">{{ auth('admin')->user()->name }}<small>Admin &middot; SK Federation</small></span>
            </a>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="btn-logout">Sign out</button>
            </form>
        </div>
    </aside>
    <div class="nav-backdrop no-print" id="navBackdrop"></div>

    <div class="main">
        {{-- Menu button: phones and tablets only --}}
        <div class="mobile-bar no-print">
            <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"><span></span><span></span><span></span></button>
            <span class="mobile-bar-brand">
                <img src="{{ asset('images/sk-logo.png') }}" alt="" width="30" height="30">
                <strong>SK Finance Admin</strong>
            </span>
        </div>

        <header class="topbar">
            <h1 class="page-title">@yield('title', 'Admin')</h1>

            <div class="topbar-right">
                <div class="live-clock no-print" id="liveClock" aria-live="off" title="Current date and time">
                    <span class="clock-date" id="clockDate"></span>
                    <span class="clock-time" id="clockTime"></span>
                </div>

                <button type="button" class="btn btn-secondary btn-small theme-btn no-print" id="themeBtn"
                        data-url="{{ route('admin.theme.update') }}" title="Choose a colour theme"><i aria-hidden="true"></i>Theme</button>

                @include('partials.messages-toggle')
            </div>
        </header>

        <main class="content">
            {{-- The secret code is shown only once, so this message stays until the Admin leaves the page --}}
            @if (session('code_fallback'))
                <div class="alert alert-warning" role="status">
                    Secret code (shown only now, give it to the person yourself):
                    <span class="code-box">{{ session('code_fallback') }}</span>
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    @include('partials.messages-panel')
</div>

{{-- Messages: small pop-ups in the top-right corner that go away by themselves (like on the Treasurer pages) --}}
<div class="toast-stack no-print" id="toastStack" aria-live="polite">
    @if (session('success'))
        <div class="toast toast-success" role="status" data-timeout="3000">
            <span class="toast-icon" aria-hidden="true">&#10003;</span>
            <div class="toast-text">{{ session('success') }}</div>
            <button type="button" class="toast-close" aria-label="Dismiss message">&times;</button>
        </div>
    @endif

    @if (session('warning'))
        <div class="toast toast-warning" role="status" data-timeout="6000">
            <span class="toast-icon" aria-hidden="true">!</span>
            <div class="toast-text">{{ session('warning') }}</div>
            <button type="button" class="toast-close" aria-label="Dismiss message">&times;</button>
        </div>
    @endif

    @if ($errors->any())
        <div class="toast toast-error" role="alert" data-timeout="8000">
            <span class="toast-icon" aria-hidden="true">!</span>
            <div class="toast-text">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            <button type="button" class="toast-close" aria-label="Dismiss message">&times;</button>
        </div>
    @endif
</div>

@include('partials.theme-dialog')

@include('partials.admin-guide')

<script src="{{ asset('js/messages-panel.js') }}"></script>
<script src="{{ asset('js/messages-app.js') }}"></script>

<script src="{{ asset('js/toasts.js') }}"></script>
<script src="{{ asset('js/live.js') }}"></script>

<script>
// Live date and time in the top bar (uses this computer's clock)
(function () {
    var d = document.getElementById('clockDate'), t = document.getElementById('clockTime');
    if (!d || !t) { return; }
    function tick() {
        var n = new Date();
        d.textContent = n.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        t.textContent = n.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<script>
// Menu drawer on phones and tablets: open with the menu button, close with x, the dark area, a link or Esc
(function () {
    var body = document.body, btn = document.getElementById('navToggle'), side = document.getElementById('sidebar');
    if (!btn || !side) { return; }

    function setOpen(open) {
        body.classList.toggle('nav-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    btn.addEventListener('click', function () { setOpen(!body.classList.contains('nav-open')); });
    document.getElementById('navClose').addEventListener('click', function () { setOpen(false); });
    document.getElementById('navBackdrop').addEventListener('click', function () { setOpen(false); });
    side.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('a')) { setOpen(false); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setOpen(false); } });
    window.addEventListener('resize', function () { if (window.innerWidth > 900) { setOpen(false); } });
})();
</script>
</body>
</html>