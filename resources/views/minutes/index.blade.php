@extends('layouts.app')

@section('title', 'Minutes of Meetings')

@section('content')

@php
    $isSecretary = auth()->user()->canManageMinutes();
    $hasFilters  = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

<div class="page-head">
    <div>
        <h2 class="section-title">Minutes of meetings</h2>
        <p class="muted">
            @if ($isSecretary)
                You write the minutes. Drafts are seen only by you; once you mark them <strong>Final</strong>, everyone in the barangay can read them.
            @else
                The final minutes of the meetings of the Sangguniang Kabataan.
            @endif
        </p>
    </div>
    <div class="btn-row">
        @can('manage-minutes')
            <a href="{{ route('minutes.create') }}" class="btn btn-primary">Write minutes</a>
        @endcan
    </div>
</div>

<section class="card">
    <form method="GET" action="{{ route('minutes.index') }}" class="form">
        <div class="form-fields">
            <div class="form-group">
                <label for="m_q">Search</label>
                <input id="m_q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Title, venue or what was discussed">
            </div>
            <div class="form-group">
                <label for="m_type">Kind of meeting</label>
                <select id="m_type" name="type">
                    <option value="">All</option>
                    @foreach (\App\Models\MeetingMinute::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="m_year">Year</label>
                <select id="m_year" name="year">
                    <option value="">All</option>
                    @foreach ($years as $year)
                        <option value="{{ $year }}" @selected(($filters['year'] ?? '') === (string) $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </div>
            @if ($isSecretary)
                <div class="form-group">
                    <label for="m_status">Status</label>
                    <select id="m_status" name="status">
                        <option value="">All</option>
                        @foreach (\App\Models\MeetingMinute::STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            @if ($hasFilters)<a href="{{ route('minutes.index') }}" class="btn btn-ghost">Clear</a>@endif
        </div>
    </form>
</section>

<section class="card">
    @if ($minutes->isEmpty())
        <p class="empty">
            @if ($hasFilters)
                No minutes match.
            @elseif ($isSecretary)
                No minutes yet. Press <strong>Write minutes</strong> after your next meeting.
            @else
                No minutes have been published yet.
            @endif
        </p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Meeting</th>
                        <th>Kind</th>
                        <th>Venue</th>
                        @if ($isSecretary)<th>Status</th>@endif
                        <th class="actions-col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($minutes as $m)
                        <tr>
                            <td style="white-space:nowrap;">{{ $m->meeting_date->format('M d, Y') }}</td>
                            <td>{{ $m->title }}</td>
                            <td>{{ $m->type_label }}</td>
                            <td>{{ $m->venue ?: '—' }}</td>
                            @if ($isSecretary)
                                <td><span class="badge {{ $m->isFinal() ? 'badge-ok' : 'badge-warn' }}">{{ $m->status_label }}</span></td>
                            @endif
                            <td class="actions-col"><a href="{{ route('minutes.show', $m) }}" class="btn btn-small btn-secondary">Open</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $minutes->links('partials.pagination') }}
    @endif
</section>

@endsection
