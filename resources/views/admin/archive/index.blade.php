@extends('layouts.admin')

@section('title', 'Log archive')

@section('content')

@php $nothingSaved = $years->every(fn ($y) => $y['months'] === 0); @endphp

<div class="page-head">
    <div>
        <h2 class="section-title">Log archive</h2>
        <p class="muted">Every finished month of the activity log is saved here as a file, inside a folder for its year. Open a folder to view or download a month, or the whole year.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.activity') }}" class="btn btn-secondary">&lsaquo; This month's log</a>
    </div>
</div>

<div class="folder-grid">
    @foreach ($years as $y)
        <a href="{{ route('admin.archive.year', $y['year']) }}" class="folder-card">
            <svg class="folder-icon" viewBox="0 0 54 44" aria-hidden="true">
                <path d="M2 8a4 4 0 0 1 4-4h14l5 5h23a4 4 0 0 1 4 4v23a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z" fill="currentColor" opacity=".3"/>
                <path d="M2 16a4 4 0 0 1 4-4h42a4 4 0 0 1 4 4v20a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z" fill="currentColor"/>
            </svg>
            <span>
                <span class="folder-name">{{ $y['year'] }}</span>
                <span class="folder-meta">
                    @if ($y['months'] > 0)
                        {{ $y['months'] }} monthly {{ $y['months'] === 1 ? 'file' : 'files' }}{{ $y['whole'] ? ' + whole year' : '' }}
                        <br>{{ $y['from'] === $y['to'] ? $y['from'] : $y['from'] . ' to ' . $y['to'] }}
                    @else
                        Nothing saved yet
                    @endif
                </span>
            </span>
        </a>
    @endforeach
</div>

@if ($nothingSaved)
    <section class="card">
        <p class="muted" style="margin:0;">
            No month has ended yet since the log started, so there is no file yet. {{ $now->format('F Y') }} is saved here as soon as the month ends,
            and you can follow it live on the <a href="{{ route('admin.activity') }}">Activity log</a> page.
        </p>
    </section>
@endif

@endsection
