{{--
    Fund movement form shared by create and edit.
    Expects: $action, $submitLabel, $movement (null when creating), $budget,
             $balances (bank / cash / unspent right now), $origin (what the edited movement already did), $defaultDate
    Layout: the form card (2 numbered sections) on the left, a side panel with the balances on the right.
    The account field (receipts only) and the "will go from X to Y" hints are driven by the
    small script at the bottom (no libraries).
--}}
@php
    $currentType    = old('type', $movement?->type ?? 'receipt');
    $currentAccount = old('account', $movement?->account ?? 'bank');
    $typeNotes      = [
        'receipt'    => 'Money coming in to the SK fund',
        'withdrawal' => 'Take cash out of the bank',
        'deposit'    => 'Put cash on hand into the bank',
    ];
    $accountNotes   = ['bank' => 'Goes into the bank account', 'cash_on_hand' => 'Kept as cash'];
@endphp

<form method="POST" action="{{ $action }}" class="form form-layout">
    @csrf
    @if ($movement)
        @method('PUT')
    @endif

    <div class="card form-main">

        {{-- 1 · What kind of movement? --}}
        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">1</span>
                <div>
                    <h3>What kind of movement?</h3>
                    <p>Pick what happened to the money.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group form-wide">
                    <div class="choice-group choice-group-3" role="radiogroup" aria-label="Type">
                        @foreach (\App\Models\FundMovement::TYPES as $value => $label)
                            <label class="choice">
                                <input type="radio" name="type" value="{{ $value }}" required @checked($currentType === $value)>
                                <span class="choice-body">
                                    <strong>{{ $label }}</strong>
                                    <small>{{ $typeNotes[$value] ?? '' }}</small>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('type')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide" id="account-group" @if ($currentType !== 'receipt') hidden @endif>
                    <span class="field-label">Received into</span>
                    <div class="choice-group" role="radiogroup" aria-label="Received into">
                        @foreach (\App\Models\FundMovement::ACCOUNTS as $value => $label)
                            <label class="choice">
                                <input type="radio" name="account" value="{{ $value }}"
                                       @checked($currentAccount === $value) @if ($currentType !== 'receipt') disabled @endif>
                                <span class="choice-body">
                                    <strong>{{ $label }}</strong>
                                    <small>{{ $accountNotes[$value] ?? '' }}</small>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('account')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>

        {{-- 2 · Details --}}
        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">2</span>
                <div>
                    <h3>Amount and details</h3>
                    <p>How much, when, and how to find it again later.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group">
                    <label for="amount">Amount (&#8369;)</label>
                    <input id="amount" type="number" name="amount" step="0.01" min="0.01" required
                           class="input-amount" placeholder="0.00"
                           value="{{ old('amount', $movement?->amount) }}">
                    @error('amount')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="date">Date</label>
                    <input id="date" type="date" name="date" required
                           min="{{ $budget->year }}-01-01" max="{{ $budget->year }}-12-31"
                           value="{{ old('date', $movement?->date?->format('Y-m-d') ?? $defaultDate) }}">
                    @error('date')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide">
                    <div id="bank-hint" class="balance-hint"></div>
                    <div id="cash-hint" class="balance-hint"></div>
                </div>

                <div class="form-group form-wide">
                    <label for="reference">Reference</label>
                    <input id="reference" type="text" name="reference" maxlength="100"
                           placeholder="e.g. check no. or deposit slip no."
                           value="{{ old('reference', $movement?->reference) }}">
                    @error('reference')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" rows="3" maxlength="1000"
                              placeholder="e.g. First release of the SK fund from the barangay">{{ old('remarks', $movement?->remarks) }}</textarea>
                    @error('remarks')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>

        <div class="form-bar">
            <a href="{{ route('fund-movements.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
        </div>
    </div>

    {{-- Side panel: balances now and what each type does --}}
    <aside class="form-side">
        <div class="metric-card metric-bank">
            <span class="metric-label">Bank account</span>
            <span class="metric-value">@peso($balances['bank'])</span>
        </div>

        <div class="metric-card metric-cash">
            <span class="metric-label">Cash on hand</span>
            <span class="metric-value">@peso($balances['cash'])</span>
        </div>

        <div class="metric-card metric-left">
            <span class="metric-label">Budget not yet spent</span>
            <span class="metric-value">@peso($balances['unspent'])</span>
        </div>

        <div class="side-card">
            <h4>What each type does</h4>
            <ul class="side-tips">
                <li><strong>Funds received</strong> adds money to the bank or to cash on hand.</li>
                <li><strong>Bank withdrawal</strong> moves money from the bank to cash on hand.</li>
                <li><strong>Cash deposit</strong> moves cash on hand into the bank.</li>
                <li>An account can't go below zero, so the movement is rejected if it would.</li>
            </ul>
        </div>
    </aside>
</form>

<script>
(function () {
    var balances = {{ \Illuminate\Support\Js::from(['bank' => $balances['bank'], 'cash' => $balances['cash']]) }}; // right now
    var origin   = {{ \Illuminate\Support\Js::from($origin) }};   // effect of the movement being edited (zeros when creating)

    var types        = document.querySelectorAll('input[name="type"]');
    var accounts     = document.querySelectorAll('input[name="account"]');
    var accountGroup = document.getElementById('account-group');
    var amount       = document.getElementById('amount');
    var bankHint     = document.getElementById('bank-hint');
    var cashHint     = document.getElementById('cash-hint');

    function peso(value) {
        var number = Number(value);

        return (number < 0 ? '-' : '') + '\u20B1' + Math.abs(number).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function cents(value) {
        return Math.round(value * 100);
    }

    // Value of the chosen radio button in a group
    function chosen(list, fallback) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].checked) { return list[i].value; }
        }

        return fallback;
    }

    // What the form as filled in would do to each account
    function effects(value) {
        var type = chosen(types, 'receipt');

        if (type === 'withdrawal') { return { bank: -value, cash: value }; }
        if (type === 'deposit')    { return { bank: value, cash: -value }; }

        var account = chosen(accounts, 'bank');

        return {
            bank: account === 'bank' ? value : 0,
            cash: account === 'cash_on_hand' ? value : 0
        };
    }

    // "Bank will go from X to Y." (the movement being edited is taken out of X first)
    function describe(element, label, key, change, touched) {
        element.hidden = !touched;

        if (!touched) { return; }

        var from = balances[key];
        var to   = from - origin[key] + change;
        var low  = cents(to) < 0;
        var text = cents(to) === cents(from)
            ? label + ' stays at ' + peso(from) + '.'
            : label + ' will go from ' + peso(from) + ' to ' + peso(to) + '.';

        if (low) { text += ' That would leave it below zero, so this will be rejected.'; }

        element.textContent = text;
        element.classList.toggle('is-over', low);
    }

    function refresh() {
        var isReceipt = chosen(types, 'receipt') === 'receipt';
        var account   = chosen(accounts, 'bank');
        var change    = effects(parseFloat(amount.value) || 0);

        // The account only applies to receipts; a disabled field is not submitted
        accountGroup.hidden = !isReceipt;

        for (var i = 0; i < accounts.length; i++) {
            accounts[i].disabled = !isReceipt;
        }

        describe(bankHint, 'Bank', 'bank', change.bank, !isReceipt || account === 'bank' || origin.bank !== 0);
        describe(cashHint, 'Cash on hand', 'cash', change.cash, !isReceipt || account === 'cash_on_hand' || origin.cash !== 0);
    }

    for (var t = 0; t < types.length; t++)    { types[t].addEventListener('change', refresh); }
    for (var a = 0; a < accounts.length; a++) { accounts[a].addEventListener('change', refresh); }

    amount.addEventListener('input', refresh);

    refresh();
})();
</script>
