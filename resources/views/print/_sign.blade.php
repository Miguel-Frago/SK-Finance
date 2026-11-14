{{-- Signature lines at the end of a printed report. Names and signatures are the ones saved on the Report Generation page. --}}
@php
    $saved = \App\Models\ReportSetting::read('signatories');
    $me    = auth()->user();
    $slots = [
        'prepared' => ['Prepared by', $me->isTreasurer() ? $me->name : '', $me->isTreasurer() ? 'SK Treasurer' : ''],
        'noted'    => ['Noted by', '', ''],
        'approved' => ['Approved by', '', ''],
    ];
@endphp
<div class="pr-sign">
    @foreach ($slots as $key => [$label, $defName, $defPos])
        @php
            $entry = $saved[$key] ?? [];
            $name  = array_key_exists('name', $entry) ? $entry['name'] : $defName;
            $pos   = array_key_exists('pos', $entry) ? $entry['pos'] : $defPos;
        @endphp
        <div>
            <p class="pr-sign-role">{{ $label }}:</p>
            <div class="pr-sign-line">{{ $name ?: '________________________' }}</div>
            <div class="pr-sign-title">{{ $pos ?: ' ' }}</div>
        </div>
    @endforeach
</div>
