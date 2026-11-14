/* SK Finance - live Admin pages.
   Every few seconds the open page asks /admin/live "did anything change?". If yes, it fetches itself again and swaps
   in the new list, counts and menu badges, so a new request or account shows up without pressing refresh.
   It never swaps while you are typing or have changed a field (nothing you wrote is lost); it shows a
   "New updates" button instead. It rests while the tab is hidden and checks again the moment you come back. */
(function () {
    'use strict';

    var body = document.body;
    var url  = body.getAttribute('data-live-url');
    var gap  = parseInt(body.getAttribute('data-live-every'), 10) || 5000;
    if (!url || !window.fetch || !window.DOMParser) { return; }

    var stamp = null, requests = null, accounts = null;
    var busy = false, timer = null, notice = null;

    function say(kind, text) { if (window.skToast) { window.skToast(kind, text, 5000); } }

    // true while the person is typing, or a field differs from what the page loaded with
    function editing(root) {
        var a = document.activeElement;
        if (a && root.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && a.type !== 'button' && a.type !== 'submit') { return true; }

        var fields = root.querySelectorAll('input, textarea, select');
        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            if (f.type === 'hidden' || f.type === 'button' || f.type === 'submit') { continue; }
            if (f.tagName === 'SELECT') {
                for (var j = 0; j < f.options.length; j++) { if (f.options[j].selected !== f.options[j].defaultSelected) { return true; } }
            } else if (f.type === 'checkbox' || f.type === 'radio') {
                if (f.checked !== f.defaultChecked) { return true; }
            } else if (f.value !== f.defaultValue) { return true; }
        }
        return false;
    }

    function showNotice() {
        if (notice) { return; }
        notice = document.createElement('button');
        notice.type = 'button';
        notice.className = 'live-notice no-print';
        notice.textContent = 'New updates. Click to refresh';
        notice.addEventListener('click', function () { window.location.reload(); });
        document.body.appendChild(notice);
    }

    function swap() {
        return fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) {
                if (res.redirected && /\/admin\/login/.test(res.url)) { window.location.reload(); return null; }
                if (!res.ok) { return null; }
                return res.text();
            })
            .then(function (html) {
                if (!html) { return; }

                var doc     = new DOMParser().parseFromString(html, 'text/html');
                var newNav  = doc.querySelector('.sidebar-nav');
                var oldNav  = document.querySelector('.sidebar-nav');
                var newMain = doc.querySelector('main.content');
                var oldMain = document.querySelector('main.content');

                if (newNav && oldNav) { oldNav.innerHTML = newNav.innerHTML; }   // menu badges are always safe to update
                if (!newMain || !oldMain) { return; }

                if (editing(oldMain)) { showNotice(); return; }

                oldMain.innerHTML = newMain.innerHTML;
            })
            .catch(function () { /* offline for a moment: try again next time */ });
    }

    function check() {
        if (busy || document.hidden) { return Promise.resolve(); }
        busy = true;

        return fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) {
                if (res.redirected && /\/admin\/login/.test(res.url)) { window.location.reload(); return null; }
                if (!res.ok || !/json/.test(res.headers.get('content-type') || '')) { return null; }
                return res.json();
            })
            .then(function (d) {
                if (!d) { return; }

                if (stamp === null) { stamp = d.stamp; requests = d.requests; accounts = d.accounts; return; }
                if (d.stamp === stamp) { return; }

                if (d.requests > requests) { say('success', d.requests - requests === 1 ? 'A new access request came in.' : (d.requests - requests) + ' new access requests came in.'); }
                if (d.accounts > accounts) { say('success', d.accounts - accounts === 1 ? 'A new account is waiting for approval.' : (d.accounts - accounts) + ' new accounts are waiting for approval.'); }

                stamp = d.stamp; requests = d.requests; accounts = d.accounts;
                return swap();
            })
            .catch(function () { /* ignore: next check will try again */ })
            .then(function () { busy = false; });
    }

    function schedule() { clearInterval(timer); timer = setInterval(check, gap); }

    document.addEventListener('visibilitychange', function () { if (!document.hidden) { check(); } });

    check();
    schedule();
})();
