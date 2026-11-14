@php
    $hasCard  = $user->hasAccessId();
    $pcOnly   = \App\Support\AccessQr::baseUrlIsPcOnly();
    $validTo  = old('valid_until', now()->addYear()->format('Y-m-d'));
@endphp

<section class="card" id="access-id">
    <h3 class="card-title">
        QR Access ID
        <span class="access-status {{ $hasCard ? 'is-on' : '' }}">{{ $hasCard ? 'Active' : 'Not set up' }}</span>
    </h3>
    <p class="muted">
        Make your printable SK Access ID. Scan its QR code with your phone camera, then type your 6-digit PIN to sign in.
        @if ($hasCard && $user->qr_issued_at)
            Current card issued {{ $user->qr_issued_at->format('M d, Y') }}.
        @endif
    </p>

    @if (session('status_access_id'))
        <div class="alert alert-success">{{ session('status_access_id') }}</div>
    @endif

    @if ($pcOnly)
        <div class="access-warn">
            <strong>Your phone can't open this address yet.</strong>
            Set <code>SK_QR_BASE_URL</code> in <code>.env</code> to this PC's Wi-Fi address
            (for example <code>http://192.168.1.10:8000</code>) before you make the card.
        </div>
    @endif

    @if (session('access_id_card'))
        <div class="access-warn">
            Your new card is ready. <a href="{{ route('access-id.card') }}"><strong>Open the card to print it</strong></a>
            (the QR code is only shown until you press Done).
        </div>
    @endif

    <p class="muted" style="font-size:.88rem;">
        Card details: <strong>{{ $user->name }}</strong> · {{ $user->card_position }} · {{ $user->portal()?->card_label }}
        · {{ $user->photo_url ? 'photo added' : 'no photo yet (you can still add one on the card page)' }}
    </p>

    {{-- ============ Create / replace ============ --}}
    <form method="POST" action="{{ route('access-id.store') }}" class="form form-grid" autocomplete="off">
        @csrf

        <div class="form-group">
            <label for="aid_valid">Valid until</label>
            <input id="aid_valid" type="date" name="valid_until" value="{{ $validTo }}" required>
            @error('valid_until', 'accessId')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-group">
            <label for="aid_pin">New 6-digit PIN</label>
            <input id="aid_pin" type="password" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="off">
            @error('pin', 'accessId')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-group">
            <label for="aid_pin_confirmation">Confirm PIN</label>
            <input id="aid_pin_confirmation" type="password" name="pin_confirmation" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="off">
        </div>

        <div class="form-group form-wide">
            <label for="aid_password">Current password</label>
            <input id="aid_password" type="password" name="current_password" required autocomplete="current-password">
            @error('current_password', 'accessId')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ $hasCard ? 'Make a new ID' : 'Create my Access ID' }}</button>
        </div>
    </form>
    <p class="muted" style="font-size:.82rem;">Your PIN is kept secret and is not printed on the card. Making a new ID switches the old card off.</p>

    {{-- ============ Revoke ============ --}}
    @if ($hasCard)
        <hr class="access-divider">
        <form method="POST" action="{{ route('access-id.destroy') }}" class="form form-grid" autocomplete="off"
              onsubmit="return confirm('Switch off this Access ID? The printed card will stop working.');">
            @csrf
            @method('DELETE')

            <div class="form-group form-wide">
                <label for="aid_revoke_password">Lost the card? Enter your password to switch it off</label>
                <input id="aid_revoke_password" type="password" name="current_password" required autocomplete="current-password">
                @error('current_password', 'accessIdRevoke')<span class="form-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-danger-outline">Revoke Access ID</button>
            </div>
        </form>
    @endif
</section>
