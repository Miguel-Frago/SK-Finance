{{-- A block of blank item rows. Rows left empty are ignored by the controller. Expects $itemRows (how many). --}}
<div class="item-rows">
    <div class="item-row item-row-head">
        <span>Ref. code</span>
        <span>Item name</span>
        <span>Amount (₱)</span>
    </div>
    @for ($i = 0; $i < $itemRows; $i++)
        <div class="item-row">
            <input type="text" name="items[{{ $i }}][code]" maxlength="50" placeholder="optional" aria-label="Reference code">
            <input type="text" name="items[{{ $i }}][name]" maxlength="255" placeholder="e.g. Supplies and Materials" aria-label="Item name">
            <input type="number" name="items[{{ $i }}][allocated_amount]" step="0.01" min="0" placeholder="0.00" aria-label="Amount">
        </div>
    @endfor
</div>
