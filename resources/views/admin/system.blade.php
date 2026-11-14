@extends('layouts.admin')

@section('title', 'System check')

@section('content')

<div class="page-head">
    <div>
        <h2 class="section-title">System check</h2>
        <p class="muted">Settings that decide whether uploads, backups and emails work. This page is read by the web server itself, so the PHP limits below are the ones that really apply to people using the system. It shows settings only, never accounts, money or messages.</p>
    </div>
</div>

<div class="summary-pills">
    <div class="summary-pill {{ $failures ? '' : 'pill-ok' }}" @if ($failures) style="border-left-color:#dc2626" @endif>
        <strong>{{ $failures }}</strong> must be fixed
    </div>
    <div class="summary-pill {{ $warnings ? 'pill-warn' : 'pill-ok' }}">
        <strong>{{ $warnings }}</strong> {{ $warnings === 1 ? 'reminder' : 'reminders' }}
    </div>
</div>

<div class="table-wrap">
    <table class="table table-admin">
        <thead>
            <tr><th>Check</th><th>Result</th><th>What it means</th></tr>
        </thead>
        <tbody>
            @foreach ($results as $r)
                <tr>
                    <td><span class="cell-main">{{ $r['name'] }}</span></td>
                    <td>
                        <span class="badge {{ $r['status'] === 'OK' ? 'badge-ok' : ($r['status'] === 'WARN' ? 'badge-warn' : 'badge-bad') }}">{{ $r['status'] }}</span>
                    </td>
                    <td>{{ $r['detail'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<h3 class="section-title" style="margin-top:1.5rem">This server</h3>
<div class="table-wrap">
    <table class="table table-admin">
        <tbody>
            @foreach ($server as $label => $value)
                <tr><td><span class="cell-main">{{ $label }}</span></td><td>{{ $value }}</td></tr>
            @endforeach
        </tbody>
    </table>
</div>

<p class="muted" style="margin-top:1rem">The same checks run in the terminal with <code>php artisan sk:check</code>. Backups are made with <code>php artisan sk:backup</code> and stay on the server: they contain every chat, so they are not offered for download here.</p>

@endsection
