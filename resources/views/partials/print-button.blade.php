{{--
    Print button for a page header. Every page prints its own report (the "print" section of the page),
    which already lists ALL rows of the current filters, so one plain window.print() is all that is needed.
    (The "all" option is kept so older calls still work; it no longer changes anything.)
--}}
@can('print')
    <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print report</button>
@endcan
