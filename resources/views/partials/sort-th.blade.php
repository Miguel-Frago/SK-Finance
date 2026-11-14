{{--
    A column header that sorts the list when clicked (click again to flip the direction).
    Expects: $label, $column, $sort (current column), $dir (current direction 'asc'|'desc')
    Optional: $class (e.g. 'num'), $first ('asc' or 'desc': direction of the first click, default 'asc')
--}}
@php
    $isSorted = ($sort ?? null) === $column;
    $nextDir  = $isSorted ? (($dir ?? 'asc') === 'asc' ? 'desc' : 'asc') : ($first ?? 'asc');
@endphp
<th class="sortable {{ $class ?? '' }} {{ $isSorted ? 'is-sorted' : '' }}"
    aria-sort="{{ $isSorted ? (($dir ?? 'asc') === 'asc' ? 'ascending' : 'descending') : 'none' }}">
    <a href="{{ request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDir, 'page' => null]) }}"
       title="Sort by {{ strtolower($label) }}">
        {{ $label }}<span class="sort-icon" aria-hidden="true">{{ $isSorted ? (($dir ?? 'asc') === 'asc' ? '▲' : '▼') : '↕' }}</span>
    </a>
</th>
