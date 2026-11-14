@extends('layouts.app')

@section('title', 'Report Generation')
{{-- the report sheet below has its own heading, so the plain page letterhead is not printed --}}
@section('no_letterhead', '1')

@section('content')

@if (! $budget)
    <section class="card card-narrow">
        <h2 class="card-title">No budget yet</h2>
        <p class="muted">Create a budget first, then you can generate a report of its expenses.</p>
        <a href="{{ route('budget.index') }}" class="btn btn-primary">Go to Budget &amp; Allocation</a>
    </section>
@else
    @php
        $hasFilters = collect($filters)->except(['show'])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $period = (! empty($filters['date_from']) ? \Carbon\Carbon::parse($filters['date_from'])->format('M d, Y') : 'Start')
                    . ' – '
                    . (! empty($filters['date_to']) ? \Carbon\Carbon::parse($filters['date_to'])->format('M d, Y') : 'Today');
        } elseif ($summary['first_date']) {
            $period = $summary['first_date']->format('M d, Y') . ' – ' . $summary['last_date']->format('M d, Y');
        } else {
            $period = 'No expenses in range';
        }

        $evidenceRows = $transactions->filter(fn ($t) => $t->attachments->isNotEmpty());
        $receiptTotal = $evidenceRows->sum(fn ($t) => $t->attachments->count());   // one printed page per receipt file
    @endphp

    {{-- ============ Header (screen only) ============ --}}
    <div class="page-head no-print report-tools">
        <div>
            <h2 class="section-title">Report Generation <span class="badge badge-purple">Draft layout</span></h2>
            <p class="muted">
                Pick what to include, check the preview below, then print it or save it as a PDF from the print window.
                The final report format is still to be decided, so the sheet below is a draft.
            </p>
        </div>
        <div class="btn-row">
            @can('edit-report')
                <button type="button" class="btn btn-secondary" id="editSignBtn">Edit names &amp; signatures</button>
            @endcan
            @can('print')
                <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
            @endcan
        </div>
    </div>

    {{-- ============ What to include (screen only) ============ --}}
    <section class="card no-print report-tools">
        <form method="GET" action="{{ route('reports.index') }}" class="form" id="reportFilters">
            <div class="filter-grid">
                <div class="form-group">
                    <label for="r_from">Date from</label>
                    <input id="r_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="form-group">
                    <label for="r_to">Date to</label>
                    <input id="r_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div class="form-group">
                    <label for="r_category">Category (includes nested)</label>
                    <select id="r_category" name="category_id">
                        <option value="">All categories</option>
                        @foreach ($paths as $categoryId => $path)
                            <option value="{{ $categoryId }}" @selected((string) ($filters['category_id'] ?? '') === (string) $categoryId)>{{ $path }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="r_item">Item</label>
                    <select id="r_item" name="subcategory_id">
                        <option value="">All items</option>
                        @foreach ($items as $categoryId => $group)
                            <optgroup label="{{ $paths[$categoryId] ?? '' }}">
                                @foreach ($group as $item)
                                    <option value="{{ $item->id }}" @selected((string) ($filters['subcategory_id'] ?? '') === (string) $item->id)>{{ $item->display_name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="r_method">Payment method</label>
                    <select id="r_method" name="payment_method">
                        <option value="">Bank and cash</option>
                        @foreach (\App\Models\Transaction::PAYMENT_METHODS as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['payment_method'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="r_evidence">Receipts</label>
                    <select id="r_evidence" name="evidence">
                        <option value="">All expenses</option>
                        <option value="with" @selected(($filters['evidence'] ?? '') === 'with')>Only with a receipt</option>
                        <option value="without" @selected(($filters['evidence'] ?? '') === 'without')>Only missing a receipt</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="r_show">Attachments (receipts, last pages)</label>
                    <select id="r_show" name="show">
                        <option value="one" @selected($show === 'one')>Attach, one receipt per page</option>
                        <option value="none" @selected($show === 'none')>Do not attach</option>
                    </select>
                </div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary btn-small" id="applyFilters">Update preview</button>
                @if ($hasFilters || $show !== 'one')
                    <a href="{{ route('reports.index') }}" class="btn btn-ghost btn-small">Reset</a>
                @endif
            </div>
        </form>
    </section>

    {{-- ============ The report sheet (this is what gets printed) ============ --}}
    <article class="report-sheet" aria-label="Report preview">

        <header class="report-head">
            <img src="{{ asset('images/sk-logo.png') }}" alt="" width="84" height="84">
            <div class="report-head-text">
                <small>Sangguniang Kabataan &middot; {{ auth()->user()->portal()?->name }}, Luna, La Union</small>
                <h1>Statement of Expenses <span class="draft-tag">DRAFT</span></h1>
                <p>{{ $budget->display_name }}</p>
            </div>
            <dl class="report-head-meta">
                <div><dt>Period</dt><dd>{{ $period }}</dd></div>
                <div><dt>Generated</dt><dd>{{ now()->format('M d, Y') }}</dd></div>
                <div><dt>Report no.</dt><dd class="placeholder" id="repNo">to be decided</dd></div>
            </dl>
        </header>

        @if ($hasFilters)
            <p class="report-filters">
                <strong>Included:</strong>
                @if (! empty($filters['category_id'])) {{ $paths[$filters['category_id']] ?? 'Category' }}; @endif
                @if (! empty($filters['subcategory_id'])) {{ $items->flatten(1)->firstWhere('id', (int) $filters['subcategory_id'])?->display_name ?? 'Item' }}; @endif
                @if (! empty($filters['payment_method'])) Paid from {{ \App\Models\Transaction::PAYMENT_METHODS[$filters['payment_method']] ?? '' }}; @endif
                @if (($filters['evidence'] ?? '') === 'with') Only expenses with a receipt; @endif
                @if (($filters['evidence'] ?? '') === 'without') Only expenses missing a receipt; @endif
                @if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {{ $period }} @endif
            </p>
        @endif

        {{-- Summary --}}
        <div class="report-summary">
            <div><span>Expenses</span><strong>{{ number_format($summary['count']) }}</strong></div>
            <div><span>Total spent</span><strong>@peso($summary['total'])</strong></div>
            <div><span>Paid from bank</span><strong>@peso($summary['bank'])</strong></div>
            <div><span>Paid from cash</span><strong>@peso($summary['cash'])</strong></div>
            <div><span>With receipt</span><strong>{{ $summary['with_evidence'] }} of {{ $summary['count'] }}</strong></div>
            <div><span>Receipt pages</span><strong>{{ $receiptTotal }}</strong></div>
        </div>

        {{-- Part 1: the transactions --}}
        <h2 class="report-section">I. Transactions</h2>

        @if ($transactions->isEmpty())
            <p class="empty">No expenses match these choices.</p>
        @else
            <div class="table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th class="col-no">No.</th>
                        <th>Date</th>
                        <th>Voucher</th>
                        <th>Description / Payee</th>
                        <th>Budget item</th>
                        <th>Paid from</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $transaction)
                        <tr>
                            <td class="col-no">{{ $loop->iteration }}</td>
                            <td class="nowrap">{{ $transaction->date->format('M d, Y') }}</td>
                            <td>{{ $transaction->voucher_number ?: '—' }}</td>
                            <td>
                                <strong>{{ $transaction->description }}</strong>
                                <small class="block">{{ $transaction->payee ?: 'No payee' }}@if ($transaction->check_number) &middot; Check {{ $transaction->check_number }}@endif</small>
                            </td>
                            <td>
                                {{ $transaction->subcategory->display_name }}
                                <small class="block">{{ $paths[$transaction->category_id] ?? $transaction->category->name }}</small>
                            </td>
                            <td>{{ $transaction->payment_method_label }}</td>
                            <td class="num">@peso($transaction->amount)</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="num">Total</td>
                        <td class="num">@peso($summary['total'])</td>
                    </tr>
                </tfoot>
            </table>
            </div>
        @endif

        {{-- Signatures: names, positions and signature images are editable (Edit names & signatures); kept in this browser --}}
        <footer class="report-sign" id="reportSign">
            @php
                $signDefaults = [
                    // Only the treasurer is pre-filled as "Prepared by"; a viewer or chairman never appears there by default
                    'prepared' => ['Prepared by', auth()->user()->isTreasurer() ? auth()->user()->name : '', auth()->user()->isTreasurer() ? 'SK Treasurer' : ''],
                    'noted'    => ['Noted by', '', ''],
                    'approved' => ['Approved by', '', ''],
                ];
            @endphp
            @foreach ($signDefaults as $key => $def)
                @php [$label, $defName, $defPos] = $def; @endphp
                <div data-sign="{{ $key }}" data-label="{{ $label }}" data-def-name="{{ $defName }}" data-def-pos="{{ $defPos }}">
                    <span class="sign-line"><img class="sign-img" alt="" hidden></span>
                    <strong class="sign-name {{ $defName === '' ? 'placeholder' : '' }}">{{ $defName !== '' ? $defName : 'Name to be decided' }}</strong>
                    <small class="sign-role">{{ $label }}{{ $defPos !== '' ? ' · ' . $defPos : '' }}</small>
                </div>
            @endforeach
        </footer>

        {{-- Annex: the receipts / proof. They are NOT part of the report body: they go on the last pages,
             ONE RECEIPT FILE PER PAGE (one bond paper each; an expense with 3 receipts gets 3 pages), in date order. Every page carries its own label:
             receipt n of N, the transaction number it proves (same No. as the table above) and the expense details. --}}
        @if ($show !== 'none' && $transactions->isNotEmpty())
            <div class="annex-break no-print">Each receipt prints on its own page, after the report</div>

            <section class="report-evidence">
                <h2 class="report-section no-print">Annex A &mdash; Supporting documents</h2>
                <p class="annex-intro no-print">Receipts and proof of the transactions above, in date order. Each one prints on its own page with a label showing its transaction number and details.</p>

                @if ($evidenceRows->isEmpty())
                    <p class="empty">None of these expenses has a receipt attached yet.</p>
                @else
                    @php $receiptNo = 0; @endphp

                    @foreach ($transactions as $transaction)
                        @continue($transaction->attachments->isEmpty())
                        @php $txnNo = $loop->iteration; $fileTotal = $transaction->attachments->count(); @endphp

                        @foreach ($transaction->attachments as $attachment)
                            @php $receiptNo++; @endphp

                            <figure class="evidence-page" id="exh-{{ $txnNo }}-{{ $loop->iteration }}">
                                <figcaption class="evidence-label">
                                    <div class="ev-top">
                                        <span class="ev-no-tag">Receipt {{ $receiptNo }} of {{ $receiptTotal }}</span>
                                        <span class="ev-txn">Transaction No. {{ $txnNo }}@if ($fileTotal > 1) &middot; Attachment {{ $loop->iteration }} of {{ $fileTotal }}@endif</span>
                                        <span class="ev-ref">{{ $budget->display_name }} &middot; Annex A</span>
                                    </div>
                                    <dl class="ev-details">
                                        <div><dt>Date</dt><dd>{{ $transaction->date->format('M d, Y') }}</dd></div>
                                        <div><dt>Voucher</dt><dd>{{ $transaction->voucher_number ?: '—' }}</dd></div>
                                        <div><dt>Amount</dt><dd>@peso($transaction->amount)</dd></div>
                                        <div class="wide"><dt>Description</dt><dd>{{ $transaction->description }}</dd></div>
                                        <div><dt>Payee</dt><dd>{{ $transaction->payee ?: 'No payee' }}</dd></div>
                                        <div><dt>Budget item</dt><dd>{{ $transaction->subcategory->display_name }}</dd></div>
                                        <div><dt>Paid from</dt><dd>{{ $transaction->payment_method_label }}@if ($transaction->check_number) &middot; Check {{ $transaction->check_number }}@endif</dd></div>
                                    </dl>
                                </figcaption>

                                @if ($attachment->is_image)
                                    {{-- no lazy loading: images further down must be loaded when the print dialog opens --}}
                                    <a href="{{ $attachment->url }}" target="_blank" rel="noopener" class="evidence-frame">
                                        <img src="{{ $attachment->url }}" alt="Receipt for {{ $transaction->description }}">
                                    </a>
                                @else
                                    {{-- a PDF cannot be drawn inside a printed page, so it gets a tile that links to the file --}}
                                    <a href="{{ $attachment->url }}" target="_blank" rel="noopener" class="evidence-frame evidence-file">
                                        <span class="file-icon" aria-hidden="true">PDF</span>
                                        <span>{{ $attachment->display_name }}</span>
                                        <small>PDF file &mdash; it cannot be drawn on this page, so open and print it separately</small>
                                    </a>
                                @endif
                            </figure>
                        @endforeach
                    @endforeach
                @endif

                @if ($summary['missing'] > 0)
                    <p class="report-missing no-print">
                        {{ $summary['missing'] }} {{ \Illuminate\Support\Str::plural('expense', $summary['missing']) }} in this report
                        {{ $summary['missing'] === 1 ? 'has' : 'have' }} no receipt attached, so
                        {{ $summary['missing'] === 1 ? 'it is' : 'they are' }} not in the annex.
                    </p>
                @endif
            </section>
        @endif
    </article>

    {{-- ============ Edit names & signatures (screen only) ============ --}}
    <dialog id="signDialog" class="sign-dialog no-print">
        <form method="dialog" id="signForm" class="form">
            <h3 class="sign-dialog-title">Edit names &amp; signatures</h3>
            <p class="muted">Shown at the bottom of the report. Saved for everyone: the SK treasurer and the SK chairman see the same names and signatures.</p>

            <div class="form-group">
                <label for="sf_repno">Report no.</label>
                <input id="sf_repno" type="text" maxlength="40" placeholder="e.g. 2026-001">
            </div>

            @foreach (['prepared' => 'Prepared by', 'noted' => 'Noted by', 'approved' => 'Approved by'] as $key => $label)
                <fieldset class="sign-fieldset" data-edit="{{ $key }}">
                    <legend>{{ $label }}</legend>
                    <div class="sign-fields">
                        <div class="form-group">
                            <label for="sf_{{ $key }}_name">Name</label>
                            <input id="sf_{{ $key }}_name" type="text" maxlength="80" data-f="name">
                        </div>
                        <div class="form-group">
                            <label for="sf_{{ $key }}_pos">Position</label>
                            <input id="sf_{{ $key }}_pos" type="text" maxlength="80" data-f="pos" placeholder="e.g. SK Chairperson">
                        </div>
                    </div>
                    <div class="sign-upload">
                        <img class="sign-preview" alt="" hidden>
                        <label class="btn btn-secondary btn-small">Upload signature
                            <input type="file" accept="image/png,image/jpeg,image/webp" data-f="file" hidden>
                        </label>
                        <button type="button" class="btn btn-ghost btn-small" data-f="clear" hidden>Remove signature</button>
                    </div>
                </fieldset>
            @endforeach

            <div class="btn-row">
                <button type="submit" class="btn btn-primary" value="save">Save</button>
                <button type="button" class="btn btn-ghost" id="signReset">Reset to default</button>
                <button type="button" class="btn btn-ghost" id="signCancel">Cancel</button>
            </div>
        </form>
    </dialog>

    <script>
    // Report filters: apply by themselves (no "Update preview" click), keep the scroll spot, and narrow Item by Category
    (function () {
        var form = document.getElementById('reportFilters');
        if (!form) { return; }

        var from = form.elements.date_from, to = form.elements.date_to;
        var cat = form.elements.category_id, item = form.elements.subcategory_id;
        var apply = document.getElementById('applyFilters');
        var timer = null;

        if (apply) { apply.hidden = true; }   // not needed when this script runs

        function narrowItems() {
            var label = cat.options[cat.selectedIndex].text.trim();
            var all = cat.value === '';
            Array.prototype.forEach.call(item.querySelectorAll('optgroup'), function (g) {
                var l = g.label.trim();
                var ok = all || l === label || (l.indexOf(label) === 0 && !/[A-Za-z0-9]/.test(l.charAt(label.length)));
                g.hidden = g.disabled = !ok;
            });
            var cur = item.options[item.selectedIndex];
            if (cur && cur.value !== '' && cur.parentNode.disabled) { item.value = ''; }
        }

        function send() {
            clearTimeout(timer);
            try { sessionStorage.setItem('sk.scroll', JSON.stringify({ path: location.pathname, y: window.scrollY, t: Date.now() })); } catch (e) {}
            form.submit();
        }

        narrowItems();

        form.addEventListener('change', function (e) {
            var t = e.target;
            if (t === cat) { narrowItems(); }

            if (t === from || t === to) {
                // an end date before the start date would be refused: move the other date along instead
                if (from.value && to.value && to.value < from.value) {
                    if (t === from) { to.value = from.value; } else { from.value = to.value; }
                }
                clearTimeout(timer);
                timer = setTimeout(send, 500);   // short wait so typing a date does not reload at every digit
                return;
            }
            send();
        });
    })();
    </script>

    <script>
    (function () {
        var SAVE_URL = @json(route('reports.signatories'));
        var TOKEN = @json(csrf_token());
        var state = @json((object) $signatories);   // what is saved on the server
        var sheet = document.getElementById('reportSign');
        var dlg = document.getElementById('signDialog');
        var form = document.getElementById('signForm');
        var repNo = document.getElementById('repNo');
        var repNoInput = document.getElementById('sf_repno');
        var keys = ['prepared', 'noted', 'approved'];
        var pending = {};   // signature images chosen in the dialog but not saved yet

        function load() { return JSON.parse(JSON.stringify(state)); }

        // saves to the server; resolves when saved, rejects when it was not
        function save(data) {
            return fetch(SAVE_URL, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': TOKEN },
                body: JSON.stringify(data)
            }).then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                state = data;
            });
        }

        function render() {
            var data = load();

            keys.forEach(function (k) {
                var box = sheet.querySelector('[data-sign="' + k + '"]');
                var d = data[k] || {};
                var name = d.name !== undefined ? d.name : box.dataset.defName;
                var pos  = d.pos  !== undefined ? d.pos  : box.dataset.defPos;
                var nameEl = box.querySelector('.sign-name');
                var img = box.querySelector('.sign-img');

                nameEl.textContent = name || 'Name to be decided';
                nameEl.classList.toggle('placeholder', !name);
                box.querySelector('.sign-role').textContent = box.dataset.label + (pos ? ' · ' + pos : '');

                if (d.sig) { img.src = d.sig; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
            });

            if (data.reportNo) { repNo.textContent = data.reportNo; repNo.classList.remove('placeholder'); }
            else { repNo.textContent = 'to be decided'; repNo.classList.add('placeholder'); }
        }

        function fillForm() {
            var data = load();
            pending = {};
            repNoInput.value = data.reportNo || '';

            keys.forEach(function (k) {
                var box = sheet.querySelector('[data-sign="' + k + '"]');
                var fs = form.querySelector('[data-edit="' + k + '"]');
                var d = data[k] || {};
                fs.querySelector('[data-f="name"]').value = d.name !== undefined ? d.name : box.dataset.defName;
                fs.querySelector('[data-f="pos"]').value  = d.pos  !== undefined ? d.pos  : box.dataset.defPos;
                fs.querySelector('[data-f="file"]').value = '';
                showPreview(fs, d.sig || '');
            });
        }

        function showPreview(fs, src) {
            var img = fs.querySelector('.sign-preview');
            if (src) { img.src = src; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
            fs.querySelector('[data-f="clear"]').hidden = !src;
        }

        // shrink the picture so it stays small in storage; white background becomes transparent-friendly PNG
        function shrink(file, done) {
            var reader = new FileReader();
            reader.onload = function () {
                var im = new Image();
                im.onload = function () {
                    var max = 500, r = Math.min(1, max / Math.max(im.width, im.height));
                    var c = document.createElement('canvas');
                    c.width = Math.round(im.width * r); c.height = Math.round(im.height * r);
                    c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
                    done(c.toDataURL('image/png'));
                };
                im.onerror = function () { alert('That file could not be read as an image.'); };
                im.src = reader.result;
            };
            reader.readAsDataURL(file);
        }

        keys.forEach(function (k) {
            var fs = form.querySelector('[data-edit="' + k + '"]');

            fs.querySelector('[data-f="file"]').addEventListener('change', function (e) {
                var file = e.target.files[0];
                if (!file) { return; }
                shrink(file, function (url) { pending[k] = url; showPreview(fs, url); });
            });
            fs.querySelector('[data-f="clear"]').addEventListener('click', function () {
                pending[k] = ''; fs.querySelector('[data-f="file"]').value = ''; showPreview(fs, '');
            });
        });

        var editBtn = document.getElementById('editSignBtn');   // not on the page for a viewer
        if (editBtn) { editBtn.addEventListener('click', function () { fillForm(); dlg.showModal(); }); }
        document.getElementById('signCancel').addEventListener('click', function () { dlg.close(); });

        document.getElementById('signReset').addEventListener('click', function () {
            if (!confirm('Reset all names, positions, signatures and the report no. to the defaults?')) { return; }
            save({}).then(function () { render(); dlg.close(); })
                    .catch(function () { alert('Could not reset. Please check your connection and try again.'); });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = load();
            data.reportNo = repNoInput.value.trim();

            keys.forEach(function (k) {
                var fs = form.querySelector('[data-edit="' + k + '"]');
                var d = data[k] || {};
                d.name = fs.querySelector('[data-f="name"]').value.trim();
                d.pos  = fs.querySelector('[data-f="pos"]').value.trim();
                if (pending[k] !== undefined) { d.sig = pending[k]; }
                data[k] = d;
            });

            var btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;

            save(data).then(function () { render(); dlg.close(); })
                      .catch(function () { alert('Could not save. Please check your connection (or sign in again) and try again.'); })
                      .then(function () { btn.disabled = false; });
        });

        // click on the dark backdrop closes the dialog
        dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });

        render();
    })();
    </script>
@endif

@endsection