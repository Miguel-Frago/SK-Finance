{{--
    "Void" instead of "Delete": the record stays in the books, marked as voided with a reason, and no longer counts in any total.
    Needs: $action (the void address), $what ("expense" or "movement")
--}}
<details class="void-box">
    <summary class="link-action link-danger">Void</summary>
    <form method="POST" action="{{ $action }}" class="void-form">
        @csrf
        <label for="void-{{ md5($action) }}">Why is this {{ $what }} voided?</label>
        <textarea id="void-{{ md5($action) }}" name="void_reason" required minlength="10" maxlength="255" rows="2" placeholder="For example: entered twice by mistake"></textarea>
        <p class="hint">It stays in the books, marked as voided, and no longer counts in any total.</p>
        <button type="submit" class="btn btn-danger-outline btn-small">Void this {{ $what }}</button>
    </form>
</details>
