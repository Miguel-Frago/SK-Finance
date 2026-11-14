/*
 * SK Guide button: draggable.
 * - Drag it with the mouse or a finger; a plain click still opens the guide.
 * - It stays on screen, snaps to the left or right edge, and remembers its place on this device.
 * - It never sits under the open Messages panel.
 * - Arrow keys move it while it has focus. "Reset position" (in the guide header) puts it back.
 * Works on both layouts (barangay and Admin); it only needs the #skGuideOpen button.
 */
(function () {
    var fab = document.getElementById('skGuideOpen');
    if (!fab) { return; }

    var KEY = 'sk.guide.pos';
    var MARGIN = 12;       // gap kept from the screen edges (px)
    var THRESHOLD = 6;     // movement before a press counts as a drag (px)
    var drag = null;
    var justDragged = false;

    function panelWidth() {
        var panel = document.getElementById('msgPanel');
        if (!panel || !document.body.classList.contains('msg-open')) { return 0; }
        // on phones the panel covers the whole screen, so it does not push the button
        return window.innerWidth <= 900 ? 0 : panel.getBoundingClientRect().width;
    }

    function limits() {
        var w = fab.offsetWidth || 64, h = fab.offsetHeight || 64;
        return {
            minX: MARGIN,
            maxX: Math.max(MARGIN, window.innerWidth - panelWidth() - w - MARGIN),
            minY: MARGIN,
            maxY: Math.max(MARGIN, window.innerHeight - h - MARGIN)
        };
    }

    function clamp(v, lo, hi) { return Math.min(Math.max(v, lo), hi); }

    function isLeft(x) {
        return (x + (fab.offsetWidth || 64) / 2) < (window.innerWidth - panelWidth()) / 2;
    }

    function place(x, y) {
        var l = limits();
        x = clamp(x, l.minX, l.maxX);
        y = clamp(y, l.minY, l.maxY);
        fab.style.left = x + 'px';
        fab.style.top = y + 'px';
        fab.style.right = 'auto';
        fab.style.bottom = 'auto';
        // the guide window opens on the same side as the button
        document.body.classList.toggle('guide-left', isLeft(x));
        return { x: x, y: y };
    }

    function save(side, y) {
        try { localStorage.setItem(KEY, JSON.stringify({ side: side, y: y })); } catch (e) { /* storage blocked: not remembered */ }
    }

    // snap to the nearest side edge, keep the height
    function snap() {
        var l = limits();
        var x = parseFloat(fab.style.left);
        var y = parseFloat(fab.style.top);
        if (isNaN(x)) { x = l.maxX; }
        if (isNaN(y)) { y = l.maxY; }
        var side = isLeft(x) ? 'left' : 'right';
        var p = place(side === 'left' ? l.minX : l.maxX, y);
        save(side, p.y);
    }

    function restore() {
        var saved = null;
        try { saved = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { saved = null; }
        if (!saved || (saved.side !== 'left' && saved.side !== 'right') || typeof saved.y !== 'number') { return; }
        var l = limits();
        place(saved.side === 'left' ? l.minX : l.maxX, saved.y);
    }

    function reset() {
        try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ }
        fab.style.left = fab.style.top = fab.style.right = fab.style.bottom = '';
        document.body.classList.remove('guide-left');
    }

    // ---- mouse / touch / pen ----
    fab.addEventListener('pointerdown', function (e) {
        if (e.button !== undefined && e.button !== 0) { return; }
        var r = fab.getBoundingClientRect();
        drag = { id: e.pointerId, sx: e.clientX, sy: e.clientY, ox: r.left, oy: r.top, moved: false };
    });

    fab.addEventListener('pointermove', function (e) {
        if (!drag || e.pointerId !== drag.id) { return; }
        var dx = e.clientX - drag.sx, dy = e.clientY - drag.sy;
        if (!drag.moved && Math.abs(dx) < THRESHOLD && Math.abs(dy) < THRESHOLD) { return; }
        if (!drag.moved) {
            drag.moved = true;
            fab.classList.add('is-dragging');
            try { fab.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
        }
        place(drag.ox + dx, drag.oy + dy);
        e.preventDefault();
    });

    function end(e) {
        if (!drag || (e && e.pointerId !== drag.id)) { return; }
        var moved = drag.moved;
        drag = null;
        fab.classList.remove('is-dragging');
        if (moved) {
            justDragged = true;               // swallow the click that follows a drag
            setTimeout(function () { justDragged = false; }, 0);
            snap();
        }
    }
    fab.addEventListener('pointerup', end);
    fab.addEventListener('pointercancel', end);

    // capture phase: runs before the guide's own click handler, so a drag never opens the guide
    fab.addEventListener('click', function (e) {
        if (justDragged) { e.stopImmediatePropagation(); e.preventDefault(); }
    }, true);

    // ---- keyboard: arrows move it ----
    fab.addEventListener('keydown', function (e) {
        var step = e.shiftKey ? 60 : 20, dx = 0, dy = 0;
        if (e.key === 'ArrowLeft') { dx = -step; }
        else if (e.key === 'ArrowRight') { dx = step; }
        else if (e.key === 'ArrowUp') { dy = -step; }
        else if (e.key === 'ArrowDown') { dy = step; }
        else { return; }
        e.preventDefault();
        var r = fab.getBoundingClientRect();
        place(r.left + dx, r.top + dy);
        snap();
    });

    // ---- "Reset position" button inside the guide header ----
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-guide-reset]') : null;
        if (t) { reset(); }
    });

    // keep it on screen when the window changes size or the Messages panel opens or closes
    function refit() {
        if (fab.style.left) { snap(); }
    }
    window.addEventListener('resize', refit);
    document.addEventListener('sk:panel-change', refit);

    restore();
})();
