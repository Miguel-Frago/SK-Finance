@extends('layouts.admin')

@section('title', 'Unlock requests')

@section('content')
<div class="page-head">
    <div>
        <h2 class="section-title">Unlock requests</h2>
        <p class="muted">A barangay locked a finished quarter and now asks to open it again. You see the barangay, the quarter and the reason, <strong>never any money</strong>. Allow it only when the reason is good: while a quarter is open, its records can change.</p>
    </div>
</div>

@if ($errors->has('lock'))
    <div class="alert alert-error">{{ $errors->first('lock') }}</div>
@endif

@if ($requests->isEmpty())
    <section class="card card-narrow"><p class="muted">No unlock request is waiting.</p></section>
@else
<div class="table-wrap">
    <table class="table table-admin">
        <thead><tr><th>Barangay</th><th>Quarter</th><th>Asked by</th><th>Reason</th><th class="actions-col">Decision</th></tr></thead>
        <tbody>
        @foreach ($requests as $r)
            @php $who = $askers->get($r->unlock_requested_by); @endphp
            <tr>
                <td><span class="cell-main">{{ $r->barangay?->name }}</span></td>
                <td>{{ $r->label() }}<small class="hint block">locked {{ $r->approved_at?->format('M d, Y') }}</small></td>
                <td>{{ $who?->name ?? 'Former account' }}<small class="hint block">{{ $who?->role_label }} &middot; {{ $r->unlock_requested_at?->format('M d, Y g:i A') }}</small></td>
                <td>{{ $r->unlock_reason }}</td>
                <td class="actions-col">
                    <form method="POST" action="{{ route('admin.unlocks.approve', $r->id) }}" class="inline-form" onsubmit="return confirm('Unlock {{ $r->label() }} of {{ $r->barangay?->name }}?')">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-small">Allow</button>
                    </form>
                    <details class="void-box">
                        <summary class="link-action link-danger">Turn down</summary>
                        <form method="POST" action="{{ route('admin.unlocks.deny', $r->id) }}" class="void-form">
                            @csrf
                            <label for="deny-{{ $r->id }}">Why?</label>
                            <textarea id="deny-{{ $r->id }}" name="note" required minlength="5" maxlength="255" rows="2"></textarea>
                            <button type="submit" class="btn btn-danger-outline btn-small">Turn down</button>
                        </form>
                    </details>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
