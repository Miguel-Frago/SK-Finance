@extends('layouts.app')

@section('title', 'Share sign-up')

@section('content')

<div class="page-head">
    <div>
        <h2 class="section-title">Share sign-up</h2>
        <p class="muted">Show this page on a screen. Phones on the same wifi scan a code to open the page.</p>
    </div>
</div>

@if ($pcOnly)
    <div class="alert alert-error" role="alert">
        You opened this page with <strong>{{ request()->getHost() }}</strong>, which only works on this computer. Phones cannot use it.
        Open this page again with this computer's wifi address instead, for example <code>http://192.168.1.10:8000/join</code>
        (run <code>ipconfig</code> to find it), then the codes below will work on phones.
    </div>
@endif

<div class="join-grid">
    <section class="card">
        <h3 class="card-title">Request access</h3>
        <p class="muted">For new people. They send their details; the Admin checks them and emails a secret code to make an account.</p>
        <div class="join-qr">{!! $registerSvg !!}</div>
        <p class="join-url">{{ $registerUrl }}</p>
    </section>

    <section class="card">
        <h3 class="card-title">Sign in</h3>
        <p class="muted">For people who already made their account.</p>
        <div class="join-qr">{!! $loginSvg !!}</div>
        <p class="join-url">{{ $loginUrl }}</p>
    </section>
</div>

@endsection
