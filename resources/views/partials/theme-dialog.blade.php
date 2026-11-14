{{-- ============ Theme: recommended themes, colour pickers and hex codes ============ --}}
<dialog id="themeDialog" class="theme-dialog no-print" aria-labelledby="themeTitle">
    <form id="themeForm">
        <h3 class="theme-title" id="themeTitle">Choose a theme</h3>
        <p class="muted">Changes show right away. Press Save to keep them for your account.</p>

        <p class="theme-sub">Recommended</p>
        <div class="theme-presets" id="themePresets"></div>

        <p class="theme-sub">Custom colours</p>
        <div class="theme-row">
            <label for="th_sidebar_hex">Sidebar &amp; headings<small>Menu, table headers, titles</small></label>
            <input type="color" id="th_sidebar_pick" value="#16264a" aria-label="Pick the sidebar colour">
            <input type="text" id="th_sidebar_hex" value="#16264a" maxlength="7" spellcheck="false" autocomplete="off" placeholder="#16264a">
        </div>
        <div class="theme-row">
            <label for="th_accent_hex">Accent<small>Buttons, links, highlights</small></label>
            <input type="color" id="th_accent_pick" value="#3a68c8" aria-label="Pick the accent colour">
            <input type="text" id="th_accent_hex" value="#3a68c8" maxlength="7" spellcheck="false" autocomplete="off" placeholder="#3a68c8">
        </div>
        <p class="theme-note" id="themeNote" hidden>Very light colours are darkened a little so the text stays easy to read.</p>

        <div class="theme-actions">
            <button type="submit" class="btn btn-primary" id="themeSave">Save</button>
            <button type="button" class="btn btn-ghost" id="themeReset">Reset to default</button>
            <button type="button" class="btn btn-ghost" id="themeCancel">Cancel</button>
        </div>
    </form>
</dialog>
