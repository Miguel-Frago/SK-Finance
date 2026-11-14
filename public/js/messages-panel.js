/*
 * Messages box. Phase 4A: the shell only.
 *  - the message icon in the top bar opens and hides it; it opens in front of the page and moves nothing
 *  - closes with the x button, the icon again, or the Escape key; it always starts closed
 *  - window.skMessages.setUnread(n) updates the count on the icon and the tab title (used by the messaging step)
 */
(function () {
    var panel = document.getElementById('msgPanel');
    if (!panel) { return; }

    var toggles = document.querySelectorAll('[data-msg-toggle]');
    var closers = document.querySelectorAll('[data-msg-close]');
    var badges = document.querySelectorAll('[data-msg-badge]');
    var live = document.getElementById('msgLive');
    var baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
    var unread = 0;

    function isOpen() { return document.body.classList.contains('msg-open'); }

    function setOpen(open, returnFocus) {
        document.body.classList.toggle('msg-open', open);
        panel.inert = !open;                       // a hidden box must not take keyboard focus
        if (open) { panel.removeAttribute('aria-hidden'); } else { panel.setAttribute('aria-hidden', 'true'); }
        Array.prototype.forEach.call(toggles, function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });

        if (open) {
            var first = panel.querySelector('[data-msg-close]');
            if (first) { setTimeout(function () { first.focus(); }, 60); }
        } else if (returnFocus && toggles[0]) {
            toggles[0].focus();
        }
        document.dispatchEvent(new CustomEvent('sk:messages-toggle', { detail: { open: open } }));
    }

    Array.prototype.forEach.call(toggles, function (t) {
        t.addEventListener('click', function () { var was = isOpen(); setOpen(!was, was); });
    });
    Array.prototype.forEach.call(closers, function (c) {
        c.addEventListener('click', function () { setOpen(false, true); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) { setOpen(false, true); }
    });

    function setUnread(n) {
        n = Math.max(0, parseInt(n, 10) || 0);
        var label = n > 99 ? '99+' : String(n);
        Array.prototype.forEach.call(badges, function (b) { b.textContent = label; b.hidden = n === 0; });
        document.title = n > 0 ? '(' + label + ') ' + baseTitle : baseTitle;
        if (live && n !== unread) {
            live.textContent = n === 0 ? 'No unread messages.' : (n === 1 ? '1 unread message.' : n + ' unread messages.');
        }
        unread = n;
    }
    window.skMessages = { setUnread: setUnread, isOpen: isOpen, open: function () { setOpen(true); }, close: function () { setOpen(false); } };

    setOpen(false, false);
})();
