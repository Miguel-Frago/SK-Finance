@php
    // badge colour: green = added, blue = changed, red = deleted, purple = anything else (sign-ins, approvals ...)
    $badgeFor = fn (string $action) => match ($action) {
        'created' => 'badge-ok',
        'updated' => 'badge-info',
        'deleted' => 'badge-bad',
        default   => 'badge-purple',
    };
@endphp
<div class="table-wrap">
    <table class="table table-admin table-log">
        <thead>
            <tr>
                <th>When</th>
                <th>Who</th>
                <th>Barangay</th>
                <th>What happened</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($logs as $log)
                <tr>
                    <td class="log-when">{{ $log->created_at?->format('M d, Y') }}<small>{{ $log->created_at?->format('g:i A') }}</small></td>
                    <td><span class="cell-main">{{ $log->user_name ?? '—' }}</span>@if ($log->user_role)<span class="cell-sub">{{ $log->user_role }}</span>@endif</td>
                    <td>{{ $log->barangay?->name ?? '—' }}</td>
                    <td>
                        <div class="log-what">
                            <span class="badge {{ $badgeFor($log->action) }}">{{ $log->action }}</span>
                            <div class="log-text">
                                {{ $log->description }}
                                @if (is_array($log->details) && $log->details)
                                    <small class="log-details">
                                        @foreach ($log->details as $field => $pair)
                                            {{ $field }}: {{ is_array($pair) ? ($pair[0] ?? '—') . ' → ' . ($pair[1] ?? '—') : $pair }}@if (! $loop->last); @endif
                                        @endforeach
                                    </small>
                                @endif
                            </div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
