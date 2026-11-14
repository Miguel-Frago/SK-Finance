@extends('layouts.app')

@section('title', $minute->title)

@section('content')

@php
    $text = fn (?string $value) => filled($value) ? nl2br(e($value)) : '<span class="muted">Nothing written.</span>';
@endphp

<div class="page-head">
    <div>
        <h2 class="section-title">{{ $minute->title }}</h2>
        <p class="muted">
            {{ $minute->type_label }} &middot; {{ $minute->meeting_date->format('F d, Y') }}
            @if ($minute->time_label) &middot; {{ $minute->time_label }} @endif
            @can('manage-minutes') &middot; <span class="badge {{ $minute->isFinal() ? 'badge-ok' : 'badge-warn' }}">{{ $minute->status_label }}</span> @endcan
        </p>
    </div>
    <div class="btn-row">
        <a href="{{ route('minutes.index') }}" class="btn btn-secondary">Back to the minutes</a>
        @can('print-minutes')
            <a href="{{ route('minutes.print', $minute) }}" target="_blank" rel="noopener" class="btn btn-secondary">Print</a>
        @endcan
        @can('manage-minutes')
            <a href="{{ route('minutes.edit', $minute) }}" class="btn btn-primary">Edit</a>
        @endcan
    </div>
</div>

@if ($errors->has('minutes'))
    <div class="alert alert-error">{{ $errors->first('minutes') }}</div>
@endif

<section class="card">
    <dl class="profile-facts">
        <div><dt>Venue</dt><dd>{{ $minute->venue ?: '—' }}</dd></div>
        <div><dt>Presiding officer</dt><dd>{{ $minute->presiding ?: '—' }}</dd></div>
        <div><dt>Next meeting</dt><dd>{{ $minute->next_meeting ?: '—' }}</dd></div>
    </dl>
</section>

<div class="admin-grid">
    <section class="card">
        <h3 class="card-title">Present</h3>
        <p>{!! $text($minute->present) !!}</p>
    </section>
    <section class="card">
        <h3 class="card-title">Absent</h3>
        <p>{!! $text($minute->absent) !!}</p>
    </section>
</div>

<section class="card">
    <h3 class="card-title">Agenda</h3>
    <p>{!! $text($minute->agenda) !!}</p>
</section>

<section class="card">
    <h3 class="card-title">Discussion and decisions</h3>
    <p>{!! $text($minute->discussion) !!}</p>
</section>

<section class="card">
    <h3 class="card-title">Resolutions and action points</h3>
    <p>{!! $text($minute->resolutions) !!}</p>
</section>

<section class="card">
    <dl class="profile-facts">
        <div><dt>Prepared by</dt><dd>{{ $minute->prepared_by }} (SK Secretary)</dd></div>
        <div><dt>Attested by</dt><dd>{{ $minute->attested_by ?: '—' }}@if ($minute->attested_by) (SK Chairperson)@endif</dd></div>
    </dl>
</section>

@can('manage-minutes')
    @unless ($minute->isFinal())
        <section class="card">
            <h3 class="card-title">Delete this draft</h3>
            <p class="muted">Only drafts can be deleted. Final minutes stay as a record.</p>
            <form method="POST" action="{{ route('minutes.destroy', $minute) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger-outline" onclick="return confirm('Delete this draft?');">Delete the draft</button>
            </form>
        </section>
    @endunless
@endcan

@endsection
