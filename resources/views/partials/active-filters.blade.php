{{--
    The filters that are currently applied, as chips with an x that removes just that one.
    Expects: $chips  [['text' => 'From: Jan 05, 2026', 'keys' => ['date_from']], ...]
--}}
@if (! empty($chips))
    <div class="chips" aria-label="Applied filters">
        @foreach ($chips as $chip)
            <span class="chip">
                {{ $chip['text'] }}
                <a href="{{ request()->fullUrlWithQuery(array_fill_keys($chip['keys'], null) + ['page' => null]) }}"
                   title="Remove this filter" aria-label="Remove filter {{ $chip['text'] }}">&times;</a>
            </span>
        @endforeach
    </div>
@endif
