{{--
    SK Guide for the Admin: the same floating helper the Treasurers have, with a guide character the Admin
    can pick (boy or girl) and change at any time. The choice is saved on the Admin account (admins.guide_character).
    Like the Treasurer guide, it does not block the page, and stays open from page to page until X is pressed.
    Included once from layouts/admin.blade.php. Uses the guide styles of custom.css.
--}}
@php
    $guideWho = auth('admin')->user()?->guide_character;
    $guideWho = in_array($guideWho, ['boy', 'girl'], true) ? $guideWho : 'girl';

    $guideStart = match (true) {
        request()->routeIs('admin.requests.*')                 => 'requests',
        request()->routeIs('admin.accounts.*')                 => 'accounts',
        request()->routeIs('admin.barangays')                  => 'barangays',
        request()->routeIs('admin.activity', 'admin.archive.*') => 'activity',
        request()->routeIs('admin.profile')                    => 'tips',
        default                                                => 'start',
    };

    // tab key => [label, what the guide is "thinking" while that topic is open]
    $guideTabs = [
        'start'     => ['Start here',      'Hmm... what needs checking today?'],
        'requests'  => ['Access requests', 'Who wants to join?'],
        'accounts'  => ['Accounts',        'Is everything in order?'],
        'barangays' => ['Barangays',       'Which portals have a Treasurer?'],
        'activity'  => ['Activity & archive', 'Let me look at the records...'],
        'tips'      => ['Your account & tips', 'Oh, here is a handy trick!'],
    ];
@endphp

<button type="button" class="sk-guide-fab no-print" id="skGuideOpen" aria-controls="skGuide" aria-expanded="false" aria-label="Open the SK Guide">
    <img class="sk-mascot" src="{{ asset('images/mascot-' . $guideWho . '.png') }}" alt="" width="64" height="64">
    <span class="guide-badge" aria-hidden="true">?</span>
    <span class="guide-tip" aria-hidden="true">Need help? Ask SK Guide</span>
</button>

{{-- role="complementary", not "dialog": it does not take over the page --}}
<aside class="guide-float no-print" id="skGuide" data-start="{{ $guideStart }}" data-who="{{ $guideWho }}"
       data-save-url="{{ route('admin.guide.character') }}"
       data-img-boy="{{ asset('images/mascot-boy.png') }}" data-img-girl="{{ asset('images/mascot-girl.png') }}"
       role="complementary" aria-labelledby="skGuideTitle" hidden>
    <div class="guide-stage" aria-hidden="true">
        <img class="guide-char" id="skGuideChar" src="{{ asset('images/mascot-' . $guideWho . '.png') }}" alt="" width="120" height="118">
        <div class="guide-thought">
            <span class="dot dot-1"></span><span class="dot dot-2"></span>
            <p id="skGuideThought">Hmm... what needs checking today?</p>
        </div>
    </div>

    <div class="guide-panel">
        <header class="guide-head">
            <div>
                <h2 id="skGuideTitle">SK Guide</h2>
                <p>Pick a topic. You can keep working while I stay here.</p>
            </div>
            <button type="button" class="guide-close" data-guide-close aria-label="Close the guide">&times;</button>
        </header>

        {{-- Choose who the guide is. Changes right away and is remembered for this Admin account. --}}
        <div class="guide-who" role="radiogroup" aria-label="Choose your guide">
            <span class="guide-who-label">Your guide</span>
            <button type="button" class="guide-who-btn" role="radio" data-guide-who="boy" aria-checked="{{ $guideWho === 'boy' ? 'true' : 'false' }}">
                <img src="{{ asset('images/mascot-boy.png') }}" alt="" width="28" height="28"> Boy
            </button>
            <button type="button" class="guide-who-btn" role="radio" data-guide-who="girl" aria-checked="{{ $guideWho === 'girl' ? 'true' : 'false' }}">
                <img src="{{ asset('images/mascot-girl.png') }}" alt="" width="28" height="28"> Girl
            </button>
            <span class="guide-who-state" id="skGuideWhoState" role="status" aria-live="polite"></span>
        </div>

        <div class="guide-tabs" role="tablist" aria-label="Guide topics">
            @foreach ($guideTabs as $key => [$label, $thought])
                <button type="button" class="guide-tab" role="tab" id="guide-tab-{{ $key }}"
                        data-guide-tab="{{ $key }}" data-thought="{{ $thought }}"
                        aria-controls="guide-pane-{{ $key }}" aria-selected="false">{{ $label }}</button>
            @endforeach
        </div>

        <div class="guide-body">

            <section class="guide-pane" id="guide-pane-start" role="tabpanel" aria-labelledby="guide-tab-start" hidden>
                <h3>Welcome! This is the usual order of things</h3>
                <p>As the Admin you decide who gets into SK Finance. You do not see any barangay's money here, only people and activity.</p>
                <ol class="guide-steps">
                    <li><strong>Look at the Dashboard.</strong> The six boxes show what needs you. Click a box to open that list. The page updates by itself.</li>
                    <li><strong>Review the requests.</strong> In <em>Access requests</em>, open a person who asked to join and approve or deny them. Approving emails them a secret code.</li>
                    <li><strong>They make an account.</strong> The person uses the code on the Create account page. This is outside your screens.</li>
                    <li><strong>Check the new account.</strong> In <em>Accounts</em>, approve it so the person can sign in.</li>
                    <li><strong>Keep track.</strong> <em>Barangays</em> shows which portals have a Treasurer. <em>Activity log</em> shows who did what.</li>
                </ol>
                <div class="guide-note">You can change who I am, a boy or a girl, in the <strong>Your guide</strong> row above, any time you like.</div>
            </section>

            <section class="guide-pane" id="guide-pane-requests" role="tabpanel" aria-labelledby="guide-tab-requests" hidden>
                <h3>Access requests</h3>
                <p>The first step. A person asks to join as the SK Treasurer or SK Chairman of a barangay.</p>
                <ol class="guide-steps">
                    <li><strong>Open a request.</strong> Click it in the list to see the name, email, barangay and position. The coloured age shows how long it has been waiting.</li>
                    <li><strong>Approve.</strong> This emails a secret code that works once and expires after a few days. The status becomes <em>Code sent</em>.</li>
                    <li><strong>Something is wrong?</strong> Use <em>Message</em> to write to the person about the mistake. The request stays as it is.</li>
                    <li><strong>Deny.</strong> Use this when the request should not go ahead. You can give a reason.</li>
                    <li><strong>Code not used or lost?</strong> Open the request and approve again to send a fresh code.</li>
                </ol>
                <div class="guide-note">A barangay can only have one active Treasurer (and one Chairman). If the old one is still active, switch that account off first, then approve the new request.</div>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('admin.requests.index') }}">Open Access requests</a>
            </section>

            <section class="guide-pane" id="guide-pane-accounts" role="tabpanel" aria-labelledby="guide-tab-accounts" hidden>
                <h3>Accounts</h3>
                <p>The second step. After a person used their code, their account waits here for your check.</p>
                <ol class="guide-steps">
                    <li><strong>Find it.</strong> Use the status filter, search by name or email, or narrow to one barangay.</li>
                    <li><strong>Approve.</strong> Check the details, then approve. Only then can the person sign in.</li>
                    <li><strong>Message or deny.</strong> <em>Message</em> asks the person to fix a mistake and the account stays pending. <em>Deny</em> removes a pending account, and the person may ask again.</li>
                    <li><strong>End of term or resignation.</strong> Use <em>Switch off</em>. The person can no longer sign in, but everything they recorded stays.</li>
                    <li><strong>Back again.</strong> <em>Switch on</em> lets a switched-off account sign in again.</li>
                </ol>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('admin.accounts.index') }}">Open Accounts</a>
            </section>

            <section class="guide-pane" id="guide-pane-barangays" role="tabpanel" aria-labelledby="guide-tab-barangays" hidden>
                <h3>Barangays</h3>
                <p>The list of every barangay portal and who looks after it.</p>
                <ol class="guide-steps">
                    <li><strong>See the coverage.</strong> The Dashboard bar shows how many barangays have an active Treasurer.</li>
                    <li><strong>Show only some.</strong> Filter to the <em>vacant</em> ones to see which portals have nobody yet, or to the <em>active</em> ones.</li>
                    <li><strong>Fill a gap.</strong> For a vacant barangay, wait for a request from that barangay, then approve it in <em>Access requests</em>.</li>
                </ol>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('admin.barangays') }}">Open Barangays</a>
            </section>

            <section class="guide-pane" id="guide-pane-activity" role="tabpanel" aria-labelledby="guide-tab-activity" hidden>
                <h3>Activity log &amp; archive</h3>
                <p>A line for every important thing that happens: who did what, when, and in which barangay.</p>
                <ol class="guide-steps">
                    <li><strong>Activity log.</strong> Shows the current month. Use the filters to find a person, a barangay or an action.</li>
                    <li><strong>Log archive.</strong> Every finished month is saved as a file, with one folder for each year and a whole-year file too.</li>
                    <li><strong>Open or download.</strong> Open a month to read it on screen, or download it as a CSV file to keep.</li>
                </ol>
                <div class="guide-note">Nothing is ever deleted from the log. A new month starts at midnight Philippine time.</div>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('admin.activity') }}">Open Activity log</a>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('admin.archive.index') }}">Open Log archive</a>
            </section>

            <section class="guide-pane" id="guide-pane-tips" role="tabpanel" aria-labelledby="guide-tab-tips" hidden>
                <h3>Your account &amp; handy tips</h3>
                <p>Small things that make the system easier to use.</p>
                <ol class="guide-steps">
                    <li><strong>Your profile.</strong> Click your name at the bottom of the left menu to change your name, email or password.</li>
                    <li><strong>New president?</strong> On the profile page, <em>Handover to a new president</em> moves the account to them. Every request, account and log line stays.</li>
                    <li><strong>Emails not arriving?</strong> Use <em>Email check</em> on the profile page to send yourself a test message.</li>
                    <li><strong>Colours.</strong> The <em>Theme</em> button at the top right changes the colours of your pages.</li>
                    <li><strong>Messages.</strong> Confirmations appear in the top-right corner and go away by themselves. Hover over one to keep it.</li>
                    <li><strong>Your guide.</strong> Pick a boy or a girl in the <em>Your guide</em> row above. It is saved for your account.</li>
                </ol>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('admin.profile') }}">Open My profile</a>
            </section>

        </div>
    </div>
</aside>

<script>
(function () {
    var guide   = document.getElementById('skGuide');
    var openBtn = document.getElementById('skGuideOpen');

    if (!guide || !openBtn) { return; }

    var tabs     = guide.querySelectorAll('[data-guide-tab]');
    var panes    = guide.querySelectorAll('.guide-pane');
    var body     = guide.querySelector('.guide-body');
    var thought  = document.getElementById('skGuideThought');
    var charImg  = document.getElementById('skGuideChar');
    var fabImg   = openBtn.querySelector('.sk-mascot');
    var whoBtns  = guide.querySelectorAll('[data-guide-who]');
    var whoState = document.getElementById('skGuideWhoState');
    var KEY      = 'sk.admin.guide.closed';   // remembered in this browser: stays closed once X is pressed (separate from the Treasurer guide)
    var saveTimer;

    function select(key) {
        for (var i = 0; i < tabs.length; i++) {
            var on = tabs[i].getAttribute('data-guide-tab') === key;
            tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
            tabs[i].tabIndex = on ? 0 : -1;
            if (on) { thought.textContent = tabs[i].getAttribute('data-thought'); }
        }

        for (var j = 0; j < panes.length; j++) {
            panes[j].hidden = panes[j].id !== 'guide-pane-' + key;
        }

        body.scrollTop = 0;
    }

    function setOpen(isOpen) {
        guide.hidden = !isOpen;
        document.body.classList.toggle('guide-open', isOpen);
        openBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    function open() {
        setOpen(true);
        select(guide.getAttribute('data-start') || 'start');
        try { localStorage.removeItem(KEY); } catch (e) { /* storage blocked: it just will not be remembered */ }
    }

    function close() {
        setOpen(false);
        try { localStorage.setItem(KEY, '1'); } catch (e) { /* ignore */ }
        openBtn.focus({ preventScroll: true });
    }

    /* ---------- choosing the character ---------- */
    function say(text) {
        whoState.textContent = text;
        clearTimeout(saveTimer);
        if (text) { saveTimer = setTimeout(function () { whoState.textContent = ''; }, 6000); }
    }

    function setWho(who, save) {
        var src = guide.getAttribute('data-img-' + who);
        if (!src) { return; }

        charImg.src = src;
        fabImg.src  = src;
        guide.setAttribute('data-who', who);

        for (var i = 0; i < whoBtns.length; i++) {
            whoBtns[i].setAttribute('aria-checked', whoBtns[i].getAttribute('data-guide-who') === who ? 'true' : 'false');
        }

        if (!save) { return; }

        var tokenTag = document.querySelector('meta[name="csrf-token"]');

        fetch(guide.getAttribute('data-save-url'), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenTag ? tokenTag.getAttribute('content') : '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ character: who })
        }).then(function (res) {
            if (res.ok) { say('Saved'); return; }

            return res.json().catch(function () { return {}; }).then(function (data) {
                say(data.message || ('Could not save (error ' + res.status + ')'));
            });
        }).catch(function () {
            say('Could not save, try again');
        });
    }

    for (var w = 0; w < whoBtns.length; w++) {
        whoBtns[w].addEventListener('click', function () {
            var who = this.getAttribute('data-guide-who');
            if (who !== guide.getAttribute('data-who')) { setWho(who, true); }
        });
    }

    openBtn.addEventListener('click', open);

    // ONLY the X button closes it: no click-outside, no Escape key
    guide.addEventListener('click', function (event) {
        if (event.target.closest('[data-guide-close]')) { close(); }
    });

    for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function () { select(this.getAttribute('data-guide-tab')); });
    }

    // Open by default, and stay open from page to page until the X is pressed
    var closedBefore = false;
    try { closedBefore = localStorage.getItem(KEY) === '1'; } catch (e) { /* ignore */ }

    select(guide.getAttribute('data-start') || 'start');
    setOpen(! closedBefore);
})();
</script>