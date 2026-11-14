@extends('layouts.admin')

@section('title', 'Log archive')

@section('content')

@php
    $size = fn (int $bytes) => $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';

    // the rows of the folder: the whole-year file first, then January to December, then the month still going on
    $items = [];

    if ($folder['year'] || $folder['months'] || $year === $now->year) {
        $items[] = ['period' => 'year', 'whole' => true, 'pending' => false, 'label' => 'Whole year ' . $year, 'file' => $folder['year']['file'] ?? sprintf('%04d-whole-year.csv', $year), 'info' => $folder['year'] ?? null];
    }

    foreach ($folder['months'] as $month => $info) {
        $items[] = ['period' => sprintf('%02d', $month), 'whole' => false, 'pending' => false, 'label' => $info['label'], 'file' => $info['file'], 'info' => $info];
    }
@endphp

<nav class="crumbs no-print" aria-label="Where you are">
    <a href="{{ route('admin.archive.index') }}">Log archive</a>
    <span aria-hidden="true">&rsaquo;</span>
    <strong>{{ $year }}</strong>
</nav>

<div class="page-head">
    <div>
        <h2 class="section-title">{{ $year }} activity logs</h2>
        <p class="muted">One file for each finished month, and one file with the whole year. Open a file to read it here, or download it as CSV (it opens in Excel).</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.archive.index') }}" class="btn btn-secondary">&lsaquo; All years</a>
    </div>
</div>

<section class="card card-flush">
    <ul class="file-list">
        @foreach ($items as $item)
            @php $info = $item['info']; @endphp
            <li class="file-row {{ $item['whole'] ? 'is-year' : '' }}">
                <svg class="arch-icon" viewBox="0 0 36 44" aria-hidden="true">
                    <path d="M4 0h20l12 12v28a4 4 0 0 1-4 4H4a4 4 0 0 1-4-4V4a4 4 0 0 1 4-4z" fill="currentColor" opacity=".25"/>
                    <path d="M24 0l12 12H28a4 4 0 0 1-4-4z" fill="currentColor"/>
                    <path d="M8 22h20v2.5H8zm0 6h20v2.5H8zm0 6h13v2.5H8z" fill="currentColor"/>
                </svg>
                <div class="file-main">
                    <span class="file-title">{{ $item['label'] }}@if ($item['whole'] && $year === $now->year) <span class="badge badge-info">year so far</span>@endif</span>
                    <span class="file-meta">
                        <code>{{ $item['file'] }}</code>
                        @if ($info)
                            &middot; {{ number_format($info['entries']) }} {{ $info['entries'] === 1 ? 'entry' : 'entries' }}
                            &middot; {{ $size($info['size']) }}
                            &middot; saved {{ $info['saved_at']->format('M d, Y g:i A') }}
                        @else
                            &middot; made when you open it
                        @endif
                    </span>
                </div>
                <div class="file-actions">
                    <a href="{{ route('admin.archive.show', ['year' => $year, 'period' => $item['period']]) }}" class="btn btn-secondary btn-small">View</a>
                    <a href="{{ route('admin.archive.download', ['year' => $year, 'period' => $item['period']]) }}" class="btn btn-primary btn-small">Download</a>
                </div>
            </li>
        @endforeach

        @if ($year === $now->year)
            <li class="file-row is-pending">
                <svg class="arch-icon" viewBox="0 0 36 44" aria-hidden="true">
                    <path d="M4 0h20l12 12v28a4 4 0 0 1-4 4H4a4 4 0 0 1-4-4V4a4 4 0 0 1 4-4z" fill="currentColor" opacity=".18"/>
                    <path d="M8 22h20v2.5H8zm0 6h20v2.5H8zm0 6h13v2.5H8z" fill="currentColor" opacity=".5"/>
                </svg>
                <div class="file-main">
                    <span class="file-title">{{ $now->format('F Y') }} <span class="badge badge-warn">in progress</span></span>
                    <span class="file-meta">Saved here as a file when the month ends, on {{ $now->startOfMonth()->addMonth()->format('F j') }}.</span>
                </div>
                <div class="file-actions">
                    <a href="{{ route('admin.activity') }}" class="btn btn-secondary btn-small">See it live</a>
                </div>
            </li>
        @endif
    </ul>
</section>

@endsection
