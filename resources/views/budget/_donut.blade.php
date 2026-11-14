{{--
    Donut chart (pure SVG, no JavaScript).
    Expects: $segments  [['label' =>, 'value' =>, 'color' =>], ...]
             $centerTop / $centerBottom  text in the middle of the ring
             $chartLabel                 accessible description
             $chartSize                  'md' or 'sm'
             $centerLevel (optional)     'low' | 'medium' | 'high': colours the percentage in the middle
--}}
@php
    $chart = \App\Support\Donut::arcs($segments);
@endphp
<div class="donut-block donut-{{ $chartSize }}">
    <svg class="donut-svg" viewBox="0 0 42 42" role="img" aria-label="{{ $chartLabel }}">
        <circle class="donut-track" cx="21" cy="21" r="15.9155" fill="none" stroke-width="5"></circle>
        @foreach ($chart['arcs'] as $arc)
            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $arc['color'] }}" stroke-width="5"
                    stroke-dasharray="{{ $arc['dash'] }} {{ $arc['rest'] }}" stroke-dashoffset="{{ $arc['offset'] }}">
                <title>{{ $arc['label'] }}: @peso($arc['value']) ({{ $arc['share'] }}%)</title>
            </circle>
        @endforeach
        <text class="donut-center-top {{ ! empty($centerLevel) ? 'pct-' . $centerLevel : '' }}" x="21" y="21.4" text-anchor="middle">{{ $centerTop }}</text>
        <text class="donut-center-bottom" x="21" y="26.6" text-anchor="middle">{{ $centerBottom }}</text>
    </svg>

    <ul class="donut-legend">
        @forelse ($chart['arcs'] as $arc)
            <li>
                <i class="dot" style="background: {{ $arc['color'] }}"></i>
                <span class="legend-name">{{ $arc['label'] }}</span>
                <span class="legend-value">@peso($arc['value'])</span>
                <span class="legend-share">{{ $arc['share'] }}%</span>
            </li>
        @empty
            <li class="legend-empty">Nothing allocated yet.</li>
        @endforelse
    </ul>
</div>
