<?php

/*
 | The message field: one line fits, three lines at most, then it scrolls inside itself without a visible scroll bar.
 | (The browser does the measuring; these checks make sure the rules that produce that behaviour stay in the files.)
 */

it('sizes the message field from one line up to three lines', function () {
    $js = file_get_contents(public_path('js/messages-app.js'));

    expect($js)->toContain('function autosize()')
        ->and($js)->toContain('line * 3')                    // the three-line limit
        ->and($js)->toContain('borderTopWidth')              // the border is counted, so one line is not clipped
        ->and($js)->toContain("ta.style.overflowY = need > max ? 'auto' : 'hidden'");
});

it('gives the message field a one-line minimum, a three-line maximum and no visible scroll bar', function () {
    $css = file_get_contents(public_path('css/custom.css'));

    expect($css)->toContain('min-height: calc(1.4em + 1.2rem + 3px)')
        ->and($css)->toContain('max-height: calc(4.2em + 1.2rem + 3px)')
        ->and($css)->toContain('scrollbar-width: none')
        ->and($css)->toContain('.msg-compose textarea::-webkit-scrollbar { display: none');
});
