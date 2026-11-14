{{--
    Title strip of a printed report.
    Expects: $title, $subtitle (optional), $filters (optional) = [['label' => 'Paid from', 'value' => 'Bank'], ...] or [['text' => 'Paid from: Bank'], ...]
--}}
<div class="pr-title">
    <div>
        <h1>{{ $title }}</h1>
        @if (! empty($subtitle))<p>{{ $subtitle }}</p>@endif
    </div>
    <div class="pr-asof">As of {{ now()->format('F d, Y') }}</div>
</div>

@if (! empty($filters))
    <div class="pr-filters"><b>Filters applied:</b>
        @foreach ($filters as $filter)
            @if (isset($filter['text']))<span>{{ $filter['text'] }}</span>@else<span>{{ $filter['label'] }}: <b>{{ $filter['value'] }}</b></span>@endif
        @endforeach
    </div>
@endif
