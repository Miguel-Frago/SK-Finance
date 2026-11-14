{{--
    Expense form shared by create and edit.
    Expects: $action, $submitLabel, $transaction (null when creating), $budget, $catalog,
             $accounts (bank / cash balances), $origin (what the edited transaction already uses), $defaultDate
    Layout: the form card (3 numbered sections) on the left, a side panel with the balances and a
    live summary on the right. The category -> item dropdowns, the remaining balance, the live
    summary and the "bank only" check number field are driven by the small script at the bottom
    (no libraries).
--}}
@php
    $selectedCategory = old('category_id', $transaction?->category_id);
    $selectedItem     = old('subcategory_id', $transaction?->subcategory_id);
    $currentMethod    = old('payment_method', $transaction?->payment_method ?? 'bank');
    $methodNotes      = ['bank' => 'Paid by check or transfer', 'cash_on_hand' => 'Paid in cash'];
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="form form-layout">
    @csrf
    @if ($transaction)
        @method('PUT')
    @endif

    <div class="card form-main">

        {{-- 1 · What was it for? --}}
        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">1</span>
                <div>
                    <h3>What was it for?</h3>
                    <p>When it happened and what the money was spent on.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group">
                    <label for="date">Date</label>
                    <input id="date" type="date" name="date" required
                           min="{{ $budget->year }}-01-01" max="{{ $budget->year }}-12-31"
                           value="{{ old('date', $transaction?->date?->format('Y-m-d') ?? $defaultDate) }}">
                    @error('date')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="voucher_number">Voucher number</label>
                    <input id="voucher_number" type="text" name="voucher_number" maxlength="100"
                           value="{{ old('voucher_number', $transaction?->voucher_number) }}">
                    @error('voucher_number')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide">
                    <label for="description">Description</label>
                    <input id="description" type="text" name="description" maxlength="255" required
                           placeholder="e.g. Bond paper and ink for the office"
                           value="{{ old('description', $transaction?->description) }}">
                    @error('description')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide">
                    <label for="payee">Payee</label>
                    <input id="payee" type="text" name="payee" maxlength="255"
                           placeholder="Who was paid"
                           value="{{ old('payee', $transaction?->payee) }}">
                    @error('payee')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>

        {{-- 2 · Which budget item pays for it? --}}
        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">2</span>
                <div>
                    <h3>Charge it to a budget item</h3>
                    <p>Every expense is taken from the balance of one item.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group form-wide">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" required>
                        <option value="">&mdash; Select a category &mdash;</option>
                        @foreach ($catalog as $categoryId => $group)
                            <option value="{{ $categoryId }}" @selected((string) $selectedCategory === (string) $categoryId)>{{ $group['path'] }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide">
                    <label for="subcategory_id">Item</label>
                    <select id="subcategory_id" name="subcategory_id" required>
                        <option value="">&mdash; Select an item &mdash;</option>
                    </select>
                    <div id="item-hint" class="balance-hint">Choose a category, then an item.</div>
                    @error('subcategory_id')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>

        {{-- 3 · Amount and payment --}}
        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">3</span>
                <div>
                    <h3>Amount and payment</h3>
                    <p>How much was paid, from which account, and the proof.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group form-wide">
                    <label for="amount">Amount (&#8369;)</label>
                    <input id="amount" type="number" name="amount" step="0.01" min="0.01" required
                           class="input-amount" placeholder="0.00"
                           value="{{ old('amount', $transaction?->amount) }}">
                    @error('amount')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide">
                    <span class="field-label">Paid from</span>
                    <div class="choice-group" role="radiogroup" aria-label="Paid from">
                        @foreach (\App\Models\Transaction::PAYMENT_METHODS as $value => $label)
                            <label class="choice">
                                <input type="radio" name="payment_method" value="{{ $value }}" @checked($currentMethod === $value)>
                                <span class="choice-body">
                                    <strong>{{ $label }}</strong>
                                    <small>{{ $methodNotes[$value] ?? '' }}</small>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <div id="method-hint" class="balance-hint"></div>
                    @error('payment_method')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group" id="check-group" @if ($currentMethod !== 'bank') hidden @endif>
                    <label for="check_number">Check number</label>
                    <input id="check_number" type="text" name="check_number" maxlength="100"
                           value="{{ old('check_number', $transaction?->check_number) }}">
                    @error('check_number')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                @php
                    $limits   = \App\Models\TransactionAttachment::limits();
                    $existing = $transaction?->attachments ?? collect();
                    $removing = collect(old('remove_attachments', []))->map(fn ($id) => (int) $id);
                @endphp
                <div class="form-group form-wide">
                    <label for="receipts">Receipts (optional)</label>
                    <input id="receipts" type="file" name="receipts[]" multiple accept=".jpg,.jpeg,.png,.pdf"
                           data-max-files="{{ $limits['files'] }}"
                           data-max-file-bytes="{{ $limits['file_bytes'] }}"
                           data-max-total-bytes="{{ $limits['total_bytes'] }}">
                    <small class="hint">
                        JPG, PNG or PDF. Up to {{ $limits['files'] }} files for this expense, {{ $limits['file_label'] }} MB each
                        and {{ $limits['total_label'] }} MB in all. Hold Ctrl (or Shift) to pick several at once.
                        Each receipt prints on its own page in the report.
                    </small>
                    <span class="form-error" id="receipts-js-error" hidden></span>
                    @error('receipts')<span class="form-error">{{ $message }}</span>@enderror
                    @foreach (collect($errors->get('receipts.*'))->flatten()->unique() as $message)
                        <span class="form-error">{{ $message }}</span>
                    @endforeach

                    @if ($existing->isNotEmpty())
                        <ul class="receipt-list">
                            @foreach ($existing as $file)
                                <li data-size="{{ (int) $file->size }}">
                                    <a href="{{ $file->url }}" target="_blank" rel="noopener">{{ $file->display_name }}</a>
                                    @if ($file->size_label)<small class="hint">{{ $file->size_label }}</small>@endif
                                    <label class="checkbox"><input type="checkbox" name="remove_attachments[]" value="{{ $file->id }}" @checked($removing->contains($file->id))> Remove</label>
                                </li>
                            @endforeach
                        </ul>
                        <small class="hint">Files you choose above are added to these. Tick Remove to delete one when you save.</small>
                    @endif
                </div>
            </div>
        </section>

        <div class="form-bar">
            <a href="{{ route('transactions.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
        </div>
    </div>

    {{-- Side panel: balances now, live summary, tips --}}
    <aside class="form-side">
        <div class="metric-card metric-bank">
            <span class="metric-label">Bank account</span>
            <span class="metric-value">@peso($accounts['bank'])</span>
        </div>

        <div class="metric-card metric-cash">
            <span class="metric-label">Cash on hand</span>
            <span class="metric-value">@peso($accounts['cash_on_hand'])</span>
        </div>

        <div class="side-card">
            <h4>This expense</h4>
            <div class="side-list">
                <div class="side-row"><span>Item</span><strong id="side-item">&mdash;</strong></div>
                <div class="side-row"><span>Allocated</span><strong id="side-allocated">&mdash;</strong></div>
                <div class="side-row"><span>Item balance now</span><strong id="side-left">&mdash;</strong></div>
                <div class="side-row"><span>Item balance after</span><strong id="side-after">&mdash;</strong></div>
                <div class="side-row"><span id="side-account-label">Bank after payment</span><strong id="side-account-after">&mdash;</strong></div>
            </div>
        </div>

        <div class="side-card">
            <h4>Good to know</h4>
            <ul class="side-tips">
                <li>An expense can't be more than the balance of its item.</li>
                <li>It also can't be more than what is in the account it is paid from.</li>
                <li>Paying from the bank needs a check number.</li>
                <li>Keep the receipt or voucher: attach photos or PDFs (more than one is fine).</li>
            </ul>
        </div>
    </aside>
</form>

<script>
// Receipts: check the number and size of the chosen files before sending, so a too-big choice is caught at once.
// (The server checks the same limits again.)
(function () {
    var input = document.getElementById('receipts');
    if (!input) { return; }

    var box = document.getElementById('receipts-js-error');
    var maxFiles = Number(input.dataset.maxFiles);
    var maxFileBytes = Number(input.dataset.maxFileBytes);
    var maxTotalBytes = Number(input.dataset.maxTotalBytes);
    var kept = Array.prototype.slice.call(document.querySelectorAll('.receipt-list li'));

    function mb(bytes) { return (bytes / 1048576).toFixed(1).replace(/\.0$/, ''); }

    function problem() {
        var count = 0, bytes = 0;

        kept.forEach(function (li) {
            if (!li.querySelector('input[type="checkbox"]').checked) { count++; bytes += Number(li.dataset.size) || 0; }
        });

        var files = input.files ? Array.prototype.slice.call(input.files) : [];

        for (var i = 0; i < files.length; i++) {
            if (files[i].size > maxFileBytes) {
                return '"' + files[i].name + '" is ' + mb(files[i].size) + ' MB. Each receipt can be at most ' + mb(maxFileBytes) + ' MB.';
            }
            count++; bytes += files[i].size;
        }

        if (count > maxFiles) { return 'An expense can have at most ' + maxFiles + ' receipts; this one would have ' + count + '.'; }
        if (bytes > maxTotalBytes) { return 'The receipts of one expense can add up to at most ' + mb(maxTotalBytes) + ' MB; these add up to ' + mb(bytes) + ' MB.'; }

        return '';
    }

    function show(message) { box.textContent = message; box.hidden = !message; }

    input.addEventListener('change', function () {
        var message = problem();
        show(message);
        if (message) { input.value = ''; }   // forget the choice so it cannot be sent by accident
    });

    kept.forEach(function (li) {
        li.querySelector('input[type="checkbox"]').addEventListener('change', function () { show(problem()); });
    });

    input.form.addEventListener('submit', function (event) {
        var message = problem();
        if (message) { event.preventDefault(); show(message); input.scrollIntoView({ block: 'center' }); }
    });
})();
</script>

<script>
(function () {
    var catalog  = {{ \Illuminate\Support\Js::from($catalog) }};   // categoryId -> { path, items: [{ id, label, allocated, balance }] }
    var accounts = {{ \Illuminate\Support\Js::from($accounts) }};  // { bank, cash_on_hand } balances right now
    var origin   = {{ \Illuminate\Support\Js::from($origin) }};    // null, or what the edited transaction already uses
    var chosenItem = {{ \Illuminate\Support\Js::from((string) $selectedItem) }};

    var category  = document.getElementById('category_id');
    var item      = document.getElementById('subcategory_id');
    var amount    = document.getElementById('amount');
    var methods   = document.querySelectorAll('input[name="payment_method"]');
    var itemHint  = document.getElementById('item-hint');
    var methodHint = document.getElementById('method-hint');
    var checkGroup = document.getElementById('check-group');

    // live summary in the side panel
    var sideItem      = document.getElementById('side-item');
    var sideAllocated = document.getElementById('side-allocated');
    var sideLeft      = document.getElementById('side-left');
    var sideAfter     = document.getElementById('side-after');
    var sideAccLabel  = document.getElementById('side-account-label');
    var sideAccAfter  = document.getElementById('side-account-after');

    function peso(value) {
        var number = Number(value);

        return (number < 0 ? '-' : '') + '\u20B1' + Math.abs(number).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function cents(value) {
        return Math.round(value * 100);
    }

    function methodValue() {
        for (var i = 0; i < methods.length; i++) {
            if (methods[i].checked) { return methods[i].value; }
        }

        return 'bank';
    }

    // Fill the item dropdown with the items of the chosen category
    function fillItems() {
        var group = catalog[category.value];

        item.length = 1; // keep only the placeholder option

        if (!group) { return; }

        group.items.forEach(function (entry) {
            var option = document.createElement('option');
            option.value = entry.id;
            option.textContent = entry.label;
            option.selected = String(entry.id) === chosenItem;
            item.appendChild(option);
        });
    }

    function currentItem() {
        var group = catalog[category.value];

        if (!group) { return null; }

        for (var i = 0; i < group.items.length; i++) {
            if (String(group.items[i].id) === item.value) { return group.items[i]; }
        }

        return null;
    }

    // Remaining balance of the chosen item (the edited transaction does not count against itself)
    function itemLeft(selected) {
        var back = origin && String(origin.item) === String(selected.id) ? origin.amount : 0;

        return selected.balance + back;
    }

    function accountLeft() {
        var back = origin && origin.method === methodValue() ? origin.amount : 0;

        return accounts[methodValue()] + back;
    }

    function show(element, text, isOver) {
        element.textContent = text;
        element.classList.toggle('is-over', isOver);
    }

    function refresh() {
        var value    = parseFloat(amount.value) || 0;
        var selected = currentItem();
        var current  = methodValue();
        var account  = current === 'bank' ? 'bank account' : 'cash on hand';

        if (!selected) {
            show(itemHint, 'Choose a category, then an item.', false);

            sideItem.textContent = sideAllocated.textContent = sideLeft.textContent = sideAfter.textContent = '\u2014';
            sideAfter.classList.remove('is-over');
        } else {
            var left = itemLeft(selected);
            var text = 'Remaining balance: ' + peso(left) + ' of ' + peso(selected.allocated) + ' allocated.';
            var over = cents(value) > cents(left);

            show(itemHint, over ? text + ' This amount is more than the balance and will be rejected.' : text, over);

            sideItem.textContent      = selected.label;
            sideAllocated.textContent = peso(selected.allocated);
            sideLeft.textContent      = peso(left);
            show(sideAfter, peso(left - value), over);
        }

        var funds = accountLeft();
        var short = cents(value) > cents(funds);

        show(methodHint, 'Available in ' + account + ': ' + peso(funds) + (short ? '. Not enough funds, this payment will be rejected.' : ''), short);

        sideAccLabel.textContent = (current === 'bank' ? 'Bank' : 'Cash on hand') + ' after payment';
        show(sideAccAfter, peso(funds - value), short);

        checkGroup.hidden = current !== 'bank';
    }

    category.addEventListener('change', function () { chosenItem = ''; fillItems(); refresh(); });
    item.addEventListener('change', function () { chosenItem = item.value; refresh(); });
    amount.addEventListener('input', refresh);

    for (var i = 0; i < methods.length; i++) {
        methods[i].addEventListener('change', refresh);
    }

    fillItems();
    refresh();
})();
</script>
