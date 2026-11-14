{{--
    SK Guide: the SK mascot (thinking pose) in the bottom-right corner. Clicking him opens a small
    floating guide that explains how to use the system. It is NOT a pop-up that blocks the page:
    there is no dark backdrop, the page behind stays clickable and scrollable, and the guide stays
    open (even when you move to another page) until you press the X button.
    It opens on the topic that matches the page you are on. Included once from layouts/app.blade.php.
--}}
@php
    $guideWho = auth()->user()?->guide_character;
    $guideWho = in_array($guideWho, ['boy', 'girl'], true) ? $guideWho : 'boy';

    $guideStart = match (true) {
        request()->routeIs('budget.*')                    => 'budget',
        request()->routeIs('transactions.*')              => 'expenses',
        request()->routeIs('fund-movements.*')            => 'funds',
        request()->routeIs('journal.*', 'ledger.*')       => 'books',
        request()->routeIs('reports.*')                   => 'reports',
        default                                           => 'start',
    };

    // tab key => [label, what the mascot is "thinking" while that topic is open]
    $guideTabs = [
        'start'    => ['Start here',       'Hmm... where should we begin?'],
        'budget'   => ['Budget',           'Let us plan the money first...'],
        'expenses' => ['Expenses',         'What did we spend on?'],
        'funds'    => ['Fund movements',   'Where did the money go?'],
        'books'    => ['Journal & Ledger', 'Let me check the records...'],
        'reports'  => ['Reports & Printing', 'Time to put it on paper!'],
        'tips'     => ['Tips',             'Oh, here is a handy trick!'],
    ];
@endphp

<button type="button" class="sk-guide-fab" id="skGuideOpen" aria-controls="skGuide" aria-expanded="false" aria-label="Open the SK Guide">
    <img class="sk-mascot" src="{{ asset('images/mascot-' . $guideWho . '.png') }}" alt="" width="64" height="64">
    <span class="guide-badge" aria-hidden="true">?</span>
    <span class="guide-tip" aria-hidden="true">Need help? Ask SK Guide</span>
</button>

{{-- role="complementary", not "dialog": it does not take over the page --}}
<aside class="guide-float" id="skGuide" data-start="{{ $guideStart }}" data-who="{{ $guideWho }}"
       data-save-url="{{ route('guide.character') }}"
       data-img-boy="{{ asset('images/mascot-boy.png') }}" data-img-girl="{{ asset('images/mascot-girl.png') }}"
       role="complementary" aria-labelledby="skGuideTitle" hidden>
    <div class="guide-stage" aria-hidden="true">
        <img class="guide-char" id="skGuideChar" src="{{ asset('images/mascot-' . $guideWho . '.png') }}" alt="" width="120" height="118">
        <div class="guide-thought">
            <span class="dot dot-1"></span><span class="dot dot-2"></span>
            <p id="skGuideThought">Hmm... where should we begin?</p>
        </div>
    </div>

    <div class="guide-panel">
        <header class="guide-head">
            <div>
                <h2 id="skGuideTitle">SK Guide</h2>
                <p>Pick a topic. You can keep using the system while I stay here.</p>
            </div>
            <button type="button" class="guide-close" data-guide-close aria-label="Close the guide">&times;</button>
        </header>

        {{-- Choose who the guide is. Changes right away and is remembered for this account. --}}
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
                <p>SK Finance keeps your SK budget, your spending and your funds in one place.</p>
                <ol class="guide-steps">
                    <li><strong>Set up the budget.</strong> Open <em>Budget &amp; Allocation</em> and create the annual budget with its total amount.</li>
                    <li><strong>Split it up.</strong> Add categories, then items with an allocated amount. Money you have not given to an item stays unallocated.</li>
                    <li><strong>Record the funds you receive.</strong> In <em>Fund Movements</em>, record <em>Funds Received</em> into the bank or cash on hand.</li>
                    <li><strong>Record every expense.</strong> In <em>Transactions</em>, press <em>Record expense</em> and charge it to a budget item.</li>
                    <li><strong>Check how you are doing.</strong> The <em>Dashboard</em> shows the budget used and the items nearly used up. The <em>Journal</em> and <em>Ledger</em> fill in by themselves.</li>
                </ol>
                <div class="guide-note">The <strong>Budget</strong> box at the top right switches between your regular budget and any supplemental budget.</div>
            </section>

            <section class="guide-pane" id="guide-pane-budget" role="tabpanel" aria-labelledby="guide-tab-budget" hidden>
                <h3>Budget &amp; Allocation</h3>
                <p>This is where the money is planned, before anything is spent.</p>
                <ol class="guide-steps">
                    <li><strong>Create the budget.</strong> The first time, enter the year and the total amount, then press <em>Create budget</em>.</li>
                    <li><strong>Add a category.</strong> Press <em>Add category</em> and give it a name.</li>
                    <li><strong>Add items.</strong> Inside a category press <em>Add items</em>. Each item has a name and an allocated amount. Expenses are always charged to an item.</li>
                    <li><strong>Group things.</strong> <em>Add sub-category</em> puts a smaller category inside a bigger one.</li>
                    <li><strong>Change or remove.</strong> Use <em>Edit category</em>, or edit and delete an item. Anything that already has expenses cannot be deleted.</li>
                    <li><strong>Carry over leftovers.</strong> <em>New supplemental</em> creates a separate budget from unspent funds. It is tracked on its own.</li>
                </ol>
                <div class="guide-note">The bars and donut charts on this page show what is allocated, what is still free, and how much has been spent.</div>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('budget.index') }}">Open Budget &amp; Allocation</a>
            </section>

            <section class="guide-pane" id="guide-pane-expenses" role="tabpanel" aria-labelledby="guide-tab-expenses" hidden>
                <h3>Recording an expense</h3>
                <p>Every peso spent is recorded here, against one budget item.</p>
                <ol class="guide-steps">
                    <li>Press <strong>Record expense</strong> on the Transactions page or the Dashboard.</li>
                    <li><strong>What was it for?</strong> Fill in the date, voucher number, description and payee.</li>
                    <li><strong>Charge it.</strong> Pick the category, then the item. The remaining balance of the item shows right under it.</li>
                    <li><strong>Amount and payment.</strong> Type the amount and choose <em>Bank</em> (this also needs a check number) or <em>Cash on Hand</em>.</li>
                    <li><strong>Keep the proof.</strong> Attach receipts as JPG, PNG or PDF files: up to {{ \App\Models\TransactionAttachment::limits()['files'] }} per expense, {{ \App\Models\TransactionAttachment::limits()['file_label'] }} MB each and {{ \App\Models\TransactionAttachment::limits()['total_label'] }} MB in all. It is optional.</li>
                    <li>Press <strong>Record expense</strong> to save.</li>
                </ol>
                <div class="guide-note">An expense is rejected if it is more than the item's balance, or more than what is in the bank or cash account it is paid from. To fix a mistake, use the edit button in the Transactions list.</div>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('transactions.index') }}">Open Transactions</a>
            </section>

            <section class="guide-pane" id="guide-pane-funds" role="tabpanel" aria-labelledby="guide-tab-funds" hidden>
                <h3>Fund movements</h3>
                <p>Use these to record money coming in and money moving between the bank and your cash.</p>
                <ol class="guide-steps">
                    <li>Press <strong>Record movement</strong>.</li>
                    <li><strong>Pick what happened.</strong> <em>Funds Received</em> is money coming in, and you choose whether it went to the bank or cash on hand. <em>Bank Withdrawal</em> moves money from the bank to cash. <em>Cash Deposit</em> moves cash into the bank.</li>
                    <li><strong>Fill in the details.</strong> Add the amount and date. A reference such as a check or deposit slip number helps you find it later.</li>
                    <li><strong>Read the hint.</strong> Under the amount, the form tells you how the bank and cash balances will change.</li>
                </ol>
                <div class="guide-note">A movement that would take the bank or cash balance below zero is rejected.</div>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('fund-movements.index') }}">Open Fund Movements</a>
            </section>

            <section class="guide-pane" id="guide-pane-books" role="tabpanel" aria-labelledby="guide-tab-books" hidden>
                <h3>Journal &amp; Ledger</h3>
                <p>These are your records. You cannot type into them, they are built from your expenses and fund movements.</p>
                <ol class="guide-steps">
                    <li><strong>Journal.</strong> All your entries in one list. Use the filters and press <em>Apply</em>, or <em>Reset</em> to clear them.</li>
                    <li><strong>Ledger.</strong> Look at the entries of one budget item or category and see how much of its allocation has been used. <em>View in Transactions</em> jumps to the matching expenses.</li>
                    <li><strong>Found a mistake?</strong> Fix it where it was recorded, in Transactions or Fund Movements. The Journal and Ledger update on their own.</li>
                </ol>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('journal.index') }}">Open Journal</a>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('ledger.index') }}">Open Ledger</a>
            </section>

            <section class="guide-pane" id="guide-pane-reports" role="tabpanel" aria-labelledby="guide-tab-reports" hidden>
                <h3>Reports &amp; Printing</h3>
                <p>Turn your records into paper, with the receipts attached.</p>
                <ol class="guide-steps">
                    <li><strong>Open Report Generation.</strong> It lists the expenses of the current budget. The receipts are not inside the report itself: they are attached on the last pages.</li>
                    <li><strong>Choose what to include.</strong> Pick a date range, category, item or payment method. You can also show only the expenses that are <em>missing a receipt</em>.</li>
                    <li><strong>Check the preview.</strong> The white sheet is exactly what will be printed. The receipts come last, in date order, one receipt per page, each labeled with its transaction number and details. Expenses without a receipt are left out of the attachments.</li>
                    <li><strong>Print or save.</strong> Press <em>Print / Save as PDF</em>, then choose a printer or <em>Save as PDF</em> in the print window.</li>
                    <li><strong>Other pages.</strong> Dashboard, Budget, Transactions, Fund Movements, Journal and Ledger each have a <em>Print</em> button. On the long lists, <em>Print all rows</em> prints every row, not just the page you are on.</li>
                </ol>
                <div class="guide-note">The report layout is a draft. Tell the developer how you want the final report to look and it can be changed.</div>
                <a class="btn btn-secondary btn-small guide-go" href="{{ route('reports.index') }}">Open Report Generation</a>
            </section>

            <section class="guide-pane" id="guide-pane-tips" role="tabpanel" aria-labelledby="guide-tab-tips" hidden>
                <h3>Handy tips</h3>
                <p>Small things that make the system easier to use.</p>
                <ol class="guide-steps">
                    <li><strong>Messages.</strong> Confirmations appear in the top-right corner and go away by themselves after a few seconds. Hover over one to keep it.</li>
                    <li><strong>Your page stays put.</strong> After you add, edit or delete something, you stay where you were instead of jumping back to the top.</li>
                    <li><strong>Your profile.</strong> Click your name at the bottom of the left menu to change your name, email or password.</li>
                    <li><strong>Sorting and filters.</strong> Click a column heading to sort. Use <em>Apply</em> and <em>Reset</em> for filters.</li>
                    <li><strong>Your guide.</strong> Pick a boy or a girl in the <em>Your guide</em> row above. It is saved for your account, and you can change it any time.</li>
                    <li><strong>New treasurer?</strong> Creating an account needs an access code. Ask the person who set up the system.</li>
                </ol>
            </section>

        </div>
    </div>
</aside>

<script>
(function () {
    var guide   = document.getElementById('skGuide');
    var openBtn = document.getElementById('skGuideOpen');

    if (!guide || !openBtn) { return; }

    var tabs    = guide.querySelectorAll('[data-guide-tab]');
    var panes   = guide.querySelectorAll('.guide-pane');
    var body    = guide.querySelector('.guide-body');
    var thought = document.getElementById('skGuideThought');
    var charImg  = document.getElementById('skGuideChar');
    var fabImg   = openBtn.querySelector('.sk-mascot');
    var whoBtns  = guide.querySelectorAll('[data-guide-who]');
    var whoState = document.getElementById('skGuideWhoState');
    var saveTimer;
    var KEY     = 'sk.guide.closed';   // remembered in this browser: the guide stays closed once X is pressed

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

    function setWho(who) {
        var src = guide.getAttribute('data-img-' + who);
        if (!src) { return; }

        charImg.src = src;
        fabImg.src  = src;
        guide.setAttribute('data-who', who);

        for (var i = 0; i < whoBtns.length; i++) {
            whoBtns[i].setAttribute('aria-checked', whoBtns[i].getAttribute('data-guide-who') === who ? 'true' : 'false');
        }

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
            if (who !== guide.getAttribute('data-who')) { setWho(who); }
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