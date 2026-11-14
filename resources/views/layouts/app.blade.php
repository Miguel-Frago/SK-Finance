<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · SK Financial Tracking</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <link rel="stylesheet" href="{{ asset('css/print-report.css') }}">
    {{-- the signed-in person's colour theme: applied before the page is painted, so there is no flash --}}
    <script>window.SK_THEME = @json(auth()->check() ? auth()->user()->theme : null);</script>
    <script src="{{ asset('js/theme.js') }}"></script>
    @stack('head')
</head>
<body @cannot('print') class="no-printing" @endcannot @can('print') @hasSection('print') class="has-print-report" @endif @endcan>
@php
    $nav = [
        ['label' => 'Dashboard',          'route' => 'dashboard',            'match' => 'dashboard'],
        ['label' => 'Budget & Allocation', 'route' => 'budget.index',         'match' => 'budget.*'],
        ['label' => 'Transactions',       'route' => 'transactions.index',   'match' => 'transactions.*'],
        ['label' => 'Fund Movements',     'route' => 'fund-movements.index', 'match' => 'fund-movements.*'],
        ['label' => 'Journal',            'route' => 'journal.index',        'match' => 'journal.*'],
        ['label' => 'Ledger',             'route' => 'ledger.index',         'match' => 'ledger.*'],
        ['label' => 'Bank Reconciliation', 'route' => 'reconciliation.index', 'match' => 'reconciliation.*'],
        ['label' => 'Quarter Lock',       'route' => 'period-locks.index',   'match' => 'period-locks.*'],
        ['label' => 'Report Generation',  'route' => 'reports.index',        'match' => 'reports.*'],
        ['label' => 'Minutes of Meetings', 'route' => 'minutes.index',       'match' => 'minutes.*'],
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
                <small>{{ auth()->user()->portal()?->name ?? 'Barangay' }} Portal</small>
            </div>
        </div>

        <nav class="sidebar-nav">
            @foreach ($nav as $item)
                @if (Route::has($item['route']))
                    <a href="{{ route($item['route']) }}"
                       class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                        {{ $item['label'] }}
                    </a>
                @else
                    <span class="nav-link disabled">{{ $item['label'] }} <em>soon</em></span>
                @endif
            @endforeach

            {{-- QR codes of the Create account and Sign in pages, to show a room full of phones --}}
            @can('manage')
                <a href="{{ route('join.show') }}" class="nav-link {{ request()->routeIs('join.*') ? 'active' : '' }}">Share sign-up (QR)</a>
            @endcan
        </nav>

        <div class="sidebar-footer">
            <a href="{{ route('profile.edit') }}" class="sidebar-user {{ request()->routeIs('profile.*') ? 'active' : '' }}" title="My profile">
                <span class="user-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="user-name">{{ auth()->user()->name }}<small>{{ auth()->user()->role_label }} · {{ auth()->user()->portal()?->name }}</small></span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
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
                <strong>SK Finance</strong>
            </span>
        </div>

        {{-- Letterhead: only shows on paper. A page that draws its own heading (Report Generation) turns it off. --}}
        @sectionMissing('no_letterhead')
            <div class="print-letterhead">
                <img src="{{ asset('images/sk-logo.png') }}" alt="" width="70" height="70">
                <div class="pl-text">
                    <strong>Sangguniang Kabataan</strong>
                    <span>{{ auth()->user()->portal()?->name }}, Luna, La Union</span>
                    <span>SK Financial Tracking System</span>
                </div>
                <div class="pl-meta">
                    <strong>@yield('title', 'Dashboard')</strong>
                    @if ($currentBudget)<span>{{ $currentBudget->display_name }}</span>@endif
                    <span>Printed {{ now()->format('M d, Y h:i A') }} by {{ auth()->user()->name }}</span>
                </div>
            </div>
        @endif

        <header class="topbar">
            <h1 class="page-title">@yield('title', 'Dashboard')</h1>

            <div class="topbar-right">
                <div class="live-clock no-print" id="liveClock" aria-live="off" title="Current date and time">
                    <span class="clock-date" id="clockDate"></span>
                    <span class="clock-time" id="clockTime"></span>
                </div>

                <button type="button" class="btn btn-secondary btn-small theme-btn no-print" id="themeBtn"
                        data-url="{{ route('theme.update') }}" title="Choose a colour theme"><i aria-hidden="true"></i>Theme</button>

                @if ($budgets->isNotEmpty())
                    <form method="POST" action="{{ route('budget.switch') }}" class="budget-switcher">
                        @csrf
                        <label for="budget_id">Budget</label>
                        <select id="budget_id" name="budget_id" onchange="this.form.submit()">
                            @foreach ($budgets as $b)
                                <option value="{{ $b->id }}" @selected($currentBudget && $currentBudget->id === $b->id)>
                                    {{ $b->display_name }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                @endif

                @include('partials.messages-toggle')
            </div>
        </header>

        <main class="content screen-only">
            @yield('content')
        </main>

        {{-- Printed report: hidden on screen. Pages that define @section('print') print this instead of the screen. --}}
        @hasSection('print')
            @can('print')
                <section class="print-report" aria-hidden="true">
                    @yield('print')
                </section>
            @endcan
        @endif
    </div>

    @include('partials.messages-panel')
</div>

{{-- Messages: small pop-ups in the top-right corner that fade away by themselves --}}
<div class="toast-stack" id="toastStack" aria-live="polite">
    @if (session('success'))
        <div class="toast toast-success" role="status" data-timeout="3000">
            <span class="toast-icon" aria-hidden="true">&#10003;</span>
            <div class="toast-text">{{ session('success') }}</div>
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

@include('partials.sk-guide')

<script src="{{ asset('js/messages-panel.js') }}"></script>
<script src="{{ asset('js/messages-app.js') }}"></script>

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
// ?print=1: this page lists EVERY row. Opened by hand it prints by itself; when it is loaded inside the hidden
// frame made by skPrintAll() below, the parent page starts the printing instead.
if (new URLSearchParams(location.search).get('print') === '1' && window.parent === window) {
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
}

// "Print all rows": load the full list into a hidden frame in THIS tab and open the print dialog over the page
window.skPrintAll = function (button) {
    var url = button.getAttribute('data-print-all');
    var label = button.textContent;
    var old = document.getElementById('skPrintFrame');
    if (old) { old.remove(); }

    var frame = document.createElement('iframe');
    frame.id = 'skPrintFrame';
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    // kept "visible" but off-screen: some browsers print a blank page from a display:none frame
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1100px;height:800px;border:0;visibility:hidden;';

    function done() {
        button.disabled = false;
        button.textContent = label;
        setTimeout(function () { frame.remove(); }, 500);
    }

    button.disabled = true;
    button.textContent = 'Preparing…';

    frame.onload = function () {
        var win = frame.contentWindow;
        try {
            win.addEventListener('afterprint', done);
            setTimeout(function () { win.focus(); win.print(); }, 300);
            // browsers that do not fire afterprint for frames: do not leave the button stuck
            setTimeout(function () { if (button.disabled) { done(); } }, 60000);
        } catch (e) {
            done();
            window.location.href = url; // last resort: open the list in this tab
        }
    };

    frame.src = url;
    document.body.appendChild(frame);
};
</script>

<script>
(function () {
    /* ---- 1. Messages: dismiss after a few seconds (hover to keep one, x to close it now) ---- */
    var toasts = document.querySelectorAll('.toast');

    Array.prototype.forEach.call(toasts, function (toast) {
        var total     = parseInt(toast.getAttribute('data-timeout'), 10) || 3000;
        var remaining = total;
        var started   = 0;
        var timer     = null;

        toast.style.setProperty('--toast-time', total + 'ms');

        function dismiss() {
            clearTimeout(timer);
            toast.classList.add('is-leaving');
            setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
        }

        function start() {
            started = Date.now();
            toast.classList.remove('is-paused');
            timer = setTimeout(dismiss, remaining);
        }

        function pause() {
            clearTimeout(timer);
            remaining = Math.max(remaining - (Date.now() - started), 800);
            toast.classList.add('is-paused');
        }

        toast.addEventListener('mouseenter', pause);
        toast.addEventListener('mouseleave', start);
        toast.querySelector('.toast-close').addEventListener('click', dismiss);

        start();
    });

    /* ---- 2. Stay where you were: after a form is sent and the same page comes back, scroll back to the same spot ---- */
    var KEY = 'sk.scroll';

    try {
        var saved = sessionStorage.getItem(KEY);

        if (saved) {
            sessionStorage.removeItem(KEY);
            saved = JSON.parse(saved);

            if (saved.path === location.pathname && saved.y > 0 && Date.now() - saved.t < 15000) {
                var root = document.documentElement;
                var jump = function () {
                    root.style.scrollBehavior = 'auto'; // the page scrolls smoothly otherwise, we want an instant jump
                    window.scrollTo(0, saved.y);
                    root.style.scrollBehavior = '';
                };

                jump();
                window.addEventListener('load', jump);
            }
        }
    } catch (e) { /* storage blocked: the page simply opens at the top */ }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        // only forms that save something (POST), and only if nothing cancelled them (e.g. "Are you sure?")
        if (event.defaultPrevented || !form || String(form.method).toLowerCase() !== 'post') { return; }
        if (/\/(logout|budget\/switch)$/.test(form.getAttribute('action') || '')) { return; }

        try {
            sessionStorage.setItem(KEY, JSON.stringify({ path: location.pathname, y: window.scrollY, t: Date.now() }));
        } catch (e) { /* ignore */ }
    });
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