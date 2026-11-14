/* SK Finance - messages (toasts).
   Small pop-ups in the top-right corner that go away by themselves after a few seconds.
   Hover over one to keep it, press x to close it now. The time comes from data-timeout (milliseconds).
   Used by the Admin pages; it behaves like the messages on the Treasurer pages. */
(function () {
    'use strict';

    function setup(toast) {
        var total     = parseInt(toast.getAttribute('data-timeout'), 10) || 3000;
        var remaining = total;
        var started   = 0;
        var timer     = null;

        toast.style.setProperty('--toast-time', total + 'ms');

        function dismiss() {
            clearTimeout(timer);
            toast.classList.add('is-leaving');
            setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
        }

        function start() {
            started = Date.now();
            toast.classList.remove('is-paused');
            timer = setTimeout(dismiss, remaining);
        }

        function pause() {
            clearTimeout(timer);
            remaining = Math.max(remaining - (Date.now() - started), 800);
            toast.classList.add('is-paused');
        }

        toast.addEventListener('mouseenter', pause);
        toast.addEventListener('mouseleave', start);

        var close = toast.querySelector('.toast-close');
        if (close) { close.addEventListener('click', dismiss); }

        start();
    }

    /* Show a new message from script: skToast('success' | 'warning' | 'error', 'Text', optionalMilliseconds) */
    window.skToast = function (kind, text, ms) {
        var stack = document.getElementById('toastStack');
        if (!stack) { return; }

        var toast = document.createElement('div');
        toast.className = 'toast toast-' + kind;
        toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        toast.setAttribute('data-timeout', String(ms || (kind === 'error' ? 8000 : kind === 'warning' ? 6000 : 3000)));

        var icon = document.createElement('span');
        icon.className = 'toast-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = kind === 'success' ? '\u2713' : '!';

        var body = document.createElement('div');
        body.className = 'toast-text';
        body.textContent = text;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Dismiss message');
        close.innerHTML = '&times;';

        toast.appendChild(icon);
        toast.appendChild(body);
        toast.appendChild(close);
        stack.appendChild(toast);
        setup(toast);
    };

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.toast'), setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
