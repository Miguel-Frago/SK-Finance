<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $minute->title }} · Minutes</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <style>
        @page { margin: 18mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Times New Roman", Georgia, serif; font-size: 12pt; line-height: 1.5; color: #000; background: #fff; }
        .sheet { max-width: 190mm; margin: 0 auto; padding: 1rem; }
        .toolbar { max-width: 190mm; margin: 0 auto; padding: .75rem 1rem 0; display: flex; gap: .5rem; font-family: Arial, sans-serif; }
        .toolbar button, .toolbar a { padding: .5rem 1rem; border: 1px solid #1d4ed8; border-radius: 8px; background: #1d4ed8; color: #fff; font-size: .9rem; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1d4ed8; }
        .letterhead { display: flex; align-items: center; justify-content: space-between; gap: 1rem; text-align: center; border-bottom: 2px solid #000; padding-bottom: .6rem; margin-bottom: 1rem; }
        .letterhead img { width: 70px; height: 70px; object-fit: contain; flex: none; }
        .letterhead p { margin: 0; font-size: 11pt; line-height: 1.35; }
        .letterhead strong { display: block; font-size: 13pt; letter-spacing: .02em; }
        h1 { margin: .4rem 0 .2rem; text-align: center; font-size: 15pt; text-transform: uppercase; letter-spacing: .04em; }
        .sub { text-align: center; margin: 0 0 1rem; font-size: 11pt; }
        .draft { text-align: center; color: #b91c1c; font-weight: bold; letter-spacing: .2em; margin-bottom: .5rem; }
        table.facts { width: 100%; border-collapse: collapse; margin-bottom: .8rem; }
        table.facts td { padding: .15rem .4rem .15rem 0; vertical-align: top; font-size: 11.5pt; }
        table.facts td:first-child { width: 34mm; font-weight: bold; }
        h2 { margin: 1rem 0 .25rem; font-size: 12pt; text-transform: uppercase; letter-spacing: .03em; border-bottom: 1px solid #000; padding-bottom: .1rem; }
        .block { margin: 0; text-align: justify; }
        .two { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2.2rem; page-break-inside: avoid; }
        .sign div { text-align: center; }
        .sign .line { border-top: 1px solid #000; margin-top: 2.2rem; padding-top: .15rem; font-weight: bold; text-transform: uppercase; }
        .sign small { display: block; font-size: 10.5pt; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } }
    </style>
</head>
<body>
@php
    $text = fn (?string $value) => filled($value) ? nl2br(e($value)) : '—';
@endphp

<div class="toolbar">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <a href="{{ route('minutes.show', $minute) }}">Back</a>
</div>

<div class="sheet">
    <div class="letterhead">
        <img src="{{ asset('images/luna-seal.png') }}" alt="Municipality of Luna seal">
        <div>
            <p>Republic of the Philippines</p>
            <p>Province of La Union &middot; Municipality of Luna</p>
            <strong>Barangay {{ $minute->barangay->name }}</strong>
            <p>Sangguniang Kabataan</p>
        </div>
        <img src="{{ asset('images/sk-logo.png') }}" alt="Sangguniang Kabataan logo">
    </div>

    @unless ($minute->isFinal())
        <div class="draft">DRAFT</div>
    @endunless

    <h1>Minutes of the {{ strtolower($minute->type_label) }}</h1>
    <p class="sub">{{ $minute->title }}</p>

    <table class="facts">
        <tr><td>Date</td><td>{{ $minute->meeting_date->format('F d, Y') }}</td></tr>
        @if ($minute->time_label)<tr><td>Time</td><td>{{ $minute->time_label }}</td></tr>@endif
        @if ($minute->venue)<tr><td>Venue</td><td>{{ $minute->venue }}</td></tr>@endif
        @if ($minute->presiding)<tr><td>Presiding officer</td><td>{{ $minute->presiding }}</td></tr>@endif
    </table>

    <div class="two">
        <div>
            <h2>Present</h2>
            <p class="block">{!! $text($minute->present) !!}</p>
        </div>
        <div>
            <h2>Absent</h2>
            <p class="block">{!! $text($minute->absent) !!}</p>
        </div>
    </div>

    @if ($minute->agenda)
        <h2>Agenda</h2>
        <p class="block">{!! $text($minute->agenda) !!}</p>
    @endif

    <h2>Discussion and decisions</h2>
    <p class="block">{!! $text($minute->discussion) !!}</p>

    @if ($minute->resolutions)
        <h2>Resolutions and action points</h2>
        <p class="block">{!! $text($minute->resolutions) !!}</p>
    @endif

    @if ($minute->next_meeting)
        <h2>Next meeting</h2>
        <p class="block">{{ $minute->next_meeting }}</p>
    @endif

    <div class="sign">
        <div>
            <div class="line">{{ $minute->prepared_by }}</div>
            <small>SK Secretary &middot; Prepared by</small>
        </div>
        <div>
            <div class="line">{{ $minute->attested_by ?: '' }}</div>
            <small>SK Chairperson &middot; Attested by</small>
        </div>
    </div>
</div>
</body>
</html>
