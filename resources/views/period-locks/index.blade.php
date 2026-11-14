@extends('layouts.app')

@section('title', 'Quarter lock')

@section('content')
<div class="page-head">
    <div>
        <h2 class="section-title">Quarter lock &middot; {{ $year }}</h2>
        <p class="muted">When a quarter is finished and its report is signed, it is <strong>locked</strong>: nothing dated in it can be added, changed or voided. The Treasurer asks, the Chairman approves. To open it again the Treasurer or the Chairman asks, and the <strong>Admin</strong> decides.</p>
    </div>
</div>

@if ($errors->has('lock'))
    <div class="alert alert-error">{{ $errors->first('lock') }}</div>
@endif

<div class="table-wrap">
    <table class="table">
        <thead><tr><th>Quarter</th><th>Status</th><th>Details</th><th class="actions-col">Action</th></tr></thead>
        <tbody>
        @foreach ($quarters as $row)
            @php
                $lock = $row['lock'];
                $status = $lock->status;
            @endphp
            <tr>
                <td>
                    <strong>Q{{ $row['q'] }}</strong>
                    <small class="hint block">{{ $row['start']->format('M d') }} &ndash; {{ $row['end']->format('M d, Y') }}</small>
                </td>
                <td>
                    @if ($status === 'locked')
                        <span class="badge badge-void">Locked</span>
                    @elseif ($status === 'unlock_requested')
                        <span class="badge badge-cash">Locked &middot; unlock asked</span>
                    @elseif ($status === 'lock_requested')
                        <span class="badge badge-bank">Waiting for the Chairman</span>
                    @else
                        <span class="badge">Open</span>
                    @endif
                </td>
                <td>
                    @if ($status === 'locked' && $lock->approved_at)
                        <span class="hint">Locked {{ $lock->approved_at->format('M d, Y') }}</span>
                    @elseif ($status === 'lock_requested')
                        <span class="hint">Asked {{ $lock->requested_at?->format('M d, Y') }}</span>
                    @elseif ($status === 'unlock_requested')
                        <span class="hint">Reason given: {{ $lock->unlock_reason }}</span>
                    @elseif (! $row['ended'])
                        <span class="hint">This quarter has not ended yet.</span>
                    @endif
                    @if ($lock->note)
                        <small class="void-note block">Last answer: {{ $lock->note }}</small>
                    @endif
                </td>
                <td class="actions-col">
                    @if ($status === 'open' && $row['ended'] && $role === 'treasurer')
                        <form method="POST" action="{{ route('period-locks.request', [$year, $row['q']]) }}" class="inline-form" onsubmit="return confirm('Ask the Chairman to lock Q{{ $row['q'] }} {{ $year }}? Nothing dated in it can be changed once it is locked.')">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-small">Ask to lock</button>
                        </form>
                    @elseif ($status === 'lock_requested' && $role === 'treasurer')
                        <form method="POST" action="{{ route('period-locks.withdraw', [$year, $row['q']]) }}" class="inline-form">
                            @csrf
                            <button type="submit" class="link-action link-danger">Take back</button>
                        </form>
                    @elseif ($status === 'lock_requested' && $role === 'chairman')
                        <form method="POST" action="{{ route('period-locks.approve', [$year, $row['q']]) }}" class="inline-form" onsubmit="return confirm('Approve the lock of Q{{ $row['q'] }} {{ $year }}?')">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-small">Approve lock</button>
                        </form>
                        <details class="void-box">
                            <summary class="link-action link-danger">Turn down</summary>
                            <form method="POST" action="{{ route('period-locks.reject', [$year, $row['q']]) }}" class="void-form">
                                @csrf
                                <label for="rej-{{ $row['q'] }}">Why?</label>
                                <textarea id="rej-{{ $row['q'] }}" name="note" required minlength="5" maxlength="255" rows="2"></textarea>
                                <button type="submit" class="btn btn-danger-outline btn-small">Turn down</button>
                            </form>
                        </details>
                    @elseif ($status === 'locked' && in_array($role, ['treasurer', 'chairman'], true))
                        <details class="void-box">
                            <summary class="link-action">Ask to unlock</summary>
                            <form method="POST" action="{{ route('period-locks.unlock', [$year, $row['q']]) }}" class="void-form">
                                @csrf
                                <label for="un-{{ $row['q'] }}">Why must Q{{ $row['q'] }} be opened again?</label>
                                <textarea id="un-{{ $row['q'] }}" name="reason" required minlength="10" maxlength="500" rows="3"></textarea>
                                <p class="hint">The Admin will read this and decide. The quarter stays locked until then.</p>
                                <button type="submit" class="btn btn-primary btn-small">Send to the Admin</button>
                            </form>
                        </details>
                    @else
                        <span class="hint">&mdash;</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
