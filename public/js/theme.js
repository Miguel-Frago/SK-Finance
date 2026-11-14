/* SK Finance - colour themes.
   A theme is just two colours: the SIDEBAR colour (sidebar, headings, table headers) and the ACCENT colour
   (buttons, links, highlights). Every other colour of the page is worked out from them, so any pair looks
   consistent. Loaded in <head> so the theme is in place before the page is painted (no flash). */
(function () {
    'use strict';

    var DEFAULT = { id: 'navy', name: 'Navy', sidebar: '#16264a', accent: '#3a68c8' };

    // Recommended themes
    var PRESETS = [
        DEFAULT,
        { id: 'forest',   name: 'Forest',   sidebar: '#14342b', accent: '#2f8f5b' },
        { id: 'ocean',    name: 'Ocean',    sidebar: '#0e3b45', accent: '#1e8a9e' },
        { id: 'royal',    name: 'Royal',    sidebar: '#2a1b4e', accent: '#7048c8' },
        { id: 'maroon',   name: 'Maroon',   sidebar: '#4a1620', accent: '#b3364a' },
        { id: 'sunset',   name: 'Sunset',   sidebar: '#3d2312', accent: '#cc651e' },
        { id: 'rose',     name: 'Rose',     sidebar: '#4a1f3a', accent: '#b83d76' },
        { id: 'graphite', name: 'Graphite', sidebar: '#1f2937', accent: '#4b6584' }
    ];

    var VARS = ['--navy-900', '--navy-800', '--navy-700', '--sidebar-from', '--sidebar-to',
        '--slate-50', '--slate-100', '--slate-200', '--slate-300', '--slate-400', '--slate-600', '--slate-700', '--slate-900',
        '--surface', '--field', '--page-from', '--page-to', '--topbar-bg', '--choice-on', '--blue-600', '--blue-700'];

    /* ---------- colour helpers ---------- */
    function normalize(hex) {
        hex = String(hex || '').trim().replace(/^#/, '');
        if (/^[0-9a-f]{3}$/i.test(hex)) { hex = hex.replace(/(.)/g, '$1$1'); }
        return /^[0-9a-f]{6}$/i.test(hex) ? '#' + hex.toLowerCase() : null;
    }

    function toHsl(hex) {
        var r = parseInt(hex.substr(1, 2), 16) / 255, g = parseInt(hex.substr(3, 2), 16) / 255, b = parseInt(hex.substr(5, 2), 16) / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, h = 0, s = 0, d = max - min;
        if (d) {
            s = d / (1 - Math.abs(2 * l - 1));
            if (max === r) { h = ((g - b) / d) % 6; } else if (max === g) { h = (b - r) / d + 2; } else { h = (r - g) / d + 4; }
            h *= 60; if (h < 0) { h += 360; }
        }
        return { h: h, s: s * 100, l: l * 100 };
    }

    function hsl(h, s, l, a) {
        s = Math.max(0, Math.min(100, s)); l = Math.max(0, Math.min(100, l));
        return (a === undefined ? 'hsl(' : 'hsla(') + Math.round(h) + ', ' + Math.round(s) + '%, ' + Math.round(l * 10) / 10 + '%' + (a === undefined ? ')' : ', ' + a + ')');
    }

    function toHex(c) {
        var s = c.s / 100, l = c.l / 100, k = function (n) { return (n + c.h / 30) % 12; };
        var a = s * Math.min(l, 1 - l);
        var f = function (n) { var v = l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1))); return ('0' + Math.round(v * 255).toString(16)).slice(-2); };
        return '#' + f(0) + f(8) + f(4);
    }

    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    // contrast ratio of a colour against white text
    function contrast(hex) {
        var c = [1, 3, 5].map(function (i) {
            var v = parseInt(hex.substr(i, 2), 16) / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 1.05 / (0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2] + 0.05);
    }

    // Very light colours would make the white text unreadable, so they are darkened a little.
    function safe(sidebar, accent) {
        var sb = toHsl(sidebar), ac = toHsl(accent);
        sb.l = clamp(sb.l, 8, 30);
        ac.l = clamp(ac.l, 28, 54);
        ac.s = Math.max(ac.s, 20);
        // bright hues (yellow, lime) are still too light for white button text: darken until it reads well
        while (ac.l > 20 && contrast(toHex(ac)) < 3.8) { ac.l -= 1; }
        return { sb: sb, ac: ac, sidebar: toHex(sb), accent: toHex(ac) };
    }

    /* ---------- apply / clear ---------- */
    function clear() {
        var st = document.documentElement.style;
        VARS.forEach(function (v) { st.removeProperty(v); });
    }

    function apply(theme) {
        var sidebar = theme && normalize(theme.sidebar), accent = theme && normalize(theme.accent);

        if (!sidebar || !accent || (sidebar === DEFAULT.sidebar && accent === DEFAULT.accent)) { clear(); return null; }

        var t = safe(sidebar, accent), sb = t.sb, ac = t.ac, st = document.documentElement.style;
        var h = sb.h, ts = clamp(sb.s * 0.55, 12, 42);   // tint of the page follows the sidebar colour

        var set = function (k, v) { st.setProperty(k, v); };

        set('--navy-800', hsl(h, sb.s, sb.l));
        set('--navy-900', hsl(h, sb.s, Math.max(3, sb.l - 5)));
        set('--navy-700', hsl(h, sb.s, sb.l + 8));
        set('--sidebar-from', hsl(h, sb.s, sb.l + 3));
        set('--sidebar-to', hsl(h, sb.s, Math.max(3, sb.l - 4)));

        set('--slate-50',  hsl(h, ts, 94));
        set('--slate-100', hsl(h, ts, 90));
        set('--slate-200', hsl(h, ts, 86));
        set('--slate-300', hsl(h, ts, 80));
        set('--slate-400', hsl(h, ts * 0.4, 60));
        set('--slate-600', hsl(h, ts * 0.55, 35));
        set('--slate-700', hsl(h, ts * 0.6, 26));
        set('--slate-900', hsl(h, ts * 0.7, 16));
        set('--surface',   hsl(h, ts, 96.5));
        set('--field',     hsl(h, ts, 93.5));
        set('--page-from', hsl(h, ts, 92.5));
        set('--page-to',   hsl(h, ts, 88.5));
        set('--topbar-bg', hsl(h, ts, 94.5, 0.94));
        set('--choice-on', hsl(ac.h, Math.min(ac.s, 60), 91));

        set('--blue-600', hsl(ac.h, ac.s, ac.l));
        set('--blue-700', hsl(ac.h, ac.s, ac.l - 7));

        return t;
    }

    window.SKTheme = { PRESETS: PRESETS, DEFAULT: DEFAULT, apply: apply, normalize: normalize, safe: safe };

    // The saved theme is applied right away (this file is loaded in <head>)
    apply(window.SK_THEME);

    /* ---------- the "Theme" dialog ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        var dlg = document.getElementById('themeDialog');
        var btn = document.getElementById('themeBtn');
        if (!dlg || !btn) { return; }

        var grid = document.getElementById('themePresets');
        var form = document.getElementById('themeForm');
        var pickSb = document.getElementById('th_sidebar_pick'), txtSb = document.getElementById('th_sidebar_hex');
        var pickAc = document.getElementById('th_accent_pick'), txtAc = document.getElementById('th_accent_hex');
        var note = document.getElementById('themeNote');
        var saveBtn = document.getElementById('themeSave');
        var current = { id: DEFAULT.id, sidebar: DEFAULT.sidebar, accent: DEFAULT.accent };   // what is being previewed
        var saved = null;                                                                     // what is stored

        function savedTheme() {
            var s = window.SK_THEME;
            return s && normalize(s.sidebar) && normalize(s.accent)
                ? { id: s.preset || 'custom', sidebar: normalize(s.sidebar), accent: normalize(s.accent) }
                : { id: DEFAULT.id, sidebar: DEFAULT.sidebar, accent: DEFAULT.accent };
        }

        function matchPreset(sb, ac) {
            for (var i = 0; i < PRESETS.length; i++) { if (PRESETS[i].sidebar === sb && PRESETS[i].accent === ac) { return PRESETS[i].id; } }
            return 'custom';
        }

        // build the preset cards once
        PRESETS.forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'theme-card'; b.dataset.id = p.id;
            b.innerHTML = '<span class="theme-swatch"><i style="background:' + p.sidebar + '"></i><i style="background:' + p.accent + '"></i></span>' +
                          '<span class="theme-name">' + p.name + (p.id === 'navy' ? ' <small>default</small>' : '') + '</span>';
            b.addEventListener('click', function () { setColors(p.sidebar, p.accent, true); });
            grid.appendChild(b);
        });

        function show() {
            var t = safe(current.sidebar, current.accent);
            var adjusted = t.sidebar !== current.sidebar || t.accent !== current.accent;
            note.hidden = !adjusted;
            apply(current);
            current.id = matchPreset(current.sidebar, current.accent);
            Array.prototype.forEach.call(grid.children, function (c) { c.classList.toggle('is-active', c.dataset.id === current.id); });
        }

        function setColors(sb, ac, syncFields) {
            current.sidebar = sb; current.accent = ac;
            if (syncFields) {
                pickSb.value = sb; txtSb.value = sb; pickAc.value = ac; txtAc.value = ac;
                txtSb.classList.remove('is-bad'); txtAc.classList.remove('is-bad');
            }
            show();
        }

        // colour picker <-> hex box, both directions
        function link(pick, txt, which) {
            pick.addEventListener('input', function () {
                txt.value = pick.value; txt.classList.remove('is-bad');
                setColors(which === 'sb' ? pick.value : current.sidebar, which === 'ac' ? pick.value : current.accent, false);
            });
            txt.addEventListener('input', function () {
                var hex = normalize(txt.value);
                txt.classList.toggle('is-bad', !hex);
                if (!hex) { return; }
                pick.value = hex;
                setColors(which === 'sb' ? hex : current.sidebar, which === 'ac' ? hex : current.accent, false);
            });
            txt.addEventListener('blur', function () { var hex = normalize(txt.value); if (hex) { txt.value = hex; txt.classList.remove('is-bad'); } });
        }
        link(pickSb, txtSb, 'sb');
        link(pickAc, txtAc, 'ac');

        function open() {
            saved = savedTheme();
            setColors(saved.sidebar, saved.accent, true);
            if (!dlg.open) { dlg.showModal(); }
        }

        function revert() { apply(saved && { sidebar: saved.sidebar, accent: saved.accent }); }

        btn.addEventListener('click', open);
        document.getElementById('themeCancel').addEventListener('click', function () { revert(); dlg.close(); });
        dlg.addEventListener('cancel', revert);                       // Esc key
        dlg.addEventListener('click', function (e) { if (e.target === dlg) { revert(); dlg.close(); } });
        document.getElementById('themeReset').addEventListener('click', function () { setColors(DEFAULT.sidebar, DEFAULT.accent, true); });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!normalize(txtSb.value) || !normalize(txtAc.value)) { alert('Please enter a valid hex code, for example #16264a.'); return; }

            var t = safe(current.sidebar, current.accent);   // save the colours as they are really shown
            var body = { sidebar: t.sidebar, accent: t.accent, preset: matchPreset(t.sidebar, t.accent) };
            var token = document.querySelector('meta[name="csrf-token"]');

            saveBtn.disabled = true;
            fetch(btn.dataset.url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
                body: JSON.stringify(body)
            }).then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                window.SK_THEME = body;
                dlg.close();
            }).catch(function () {
                alert('Could not save the theme. Please check your connection and try again.');
            }).then(function () { saveBtn.disabled = false; });
        });
    });
})();
