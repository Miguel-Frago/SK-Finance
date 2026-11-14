<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>SK Access ID · {{ $card['name'] }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/sk-logo-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <style>@page { size: letter; margin: .5in; }</style>
</head>
<body class="idc-page">

<div class="idc-bar idc-noprint">
    <label for="idcSize" class="muted" style="margin:0;">Paper size</label>
    <select id="idcSize">
        <option value="size-eighth" selected>1/8 index card (4.25 x 2.75 in)</option>
        <option value="size-cr80">ID card size (85.6 x 54 mm)</option>
    </select>
    <button type="button" class="btn btn-primary" onclick="window.print()">Print ID (front and back)</button>
    <a class="btn btn-secondary" download="sk-access-id-qr.svg"
       href="data:image/svg+xml;base64,{{ base64_encode($qrSvg) }}">Download QR only (SVG)</a>
    <form method="POST" action="{{ route('access-id.card.done') }}">
        @csrf
        <button type="submit" class="btn btn-ghost">Done, close this page</button>
    </form>
</div>

<div class="idc-note idc-noprint">
    <strong>Save or print this now.</strong> The QR code is not shown again after you press Done.
    Your photo comes from <em>Photo and ID details</em> in your profile. You can click the photo box to try another picture here (it only changes this page and is not saved).
    For safety your <strong>PIN is not printed on the card</strong>: the card plus the PIN you remember are what keep the system locked.
    For the <strong>1/8 index card</strong>, print on a letter (short bond) sheet: front and back print one under the other, each exactly
    4.25 x 2.75 in, with a thin dashed line to cut along. In the print window, choose Letter, set Scale to 100% and turn on Background graphics.
</div>

<div class="idc-sheet size-eighth" id="idcSheet">

    {{-- ============ FRONT ============ --}}
    <article class="idc-card" aria-label="Front of the Access ID">
        <div class="idc-left">
            <img class="idc-logo" src="{{ asset('images/sk-logo.png') }}" alt="">
            <label class="idc-photo" for="idcPhotoInput" id="idcPhoto" title="Click to change the photo">
                @if (! empty($card['photo']))
                    <img src="{{ $card['photo'] }}" alt="Photo">
                @else
                <svg id="idcPhotoIcon" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>
                </svg>
                <span id="idcPhotoText">1x1 PHOTO</span>
                @endif
            </label>
            <input type="file" id="idcPhotoInput" accept="image/*" hidden>
            <h3>SK Finance</h3>
            <p>SK Finance Access ID</p>
        </div>

        <div class="idc-right">
            <h2 class="idc-title">Access ID</h2>
            <p class="idc-sub">Sangguniang Kabataan</p>

            <div class="idc-label">Full Name</div>
            <div class="idc-field">{{ $card['name'] }}</div>

            <div class="idc-label">Position</div>
            <div class="idc-field">{{ $card['position'] }}</div>

            <div class="idc-label">Barangay / Municipality</div>
            <div class="idc-field">{{ $card['barangay'] }}</div>

            <div class="idc-row">
                <div>
                    <div class="idc-label">ID No.</div>
                    <div class="idc-field">{{ $card['id_no'] }}</div>
                </div>
                <div>
                    <div class="idc-label">Valid Until</div>
                    <div class="idc-field">{{ $card['valid_until'] }}</div>
                </div>
            </div>
        </div>
    </article>

    {{-- ============ BACK ============ --}}
    <article class="idc-card" aria-label="Back of the Access ID">
        <div class="idc-left">
            <p class="idc-scan">Scan to Access</p>
            <div class="idc-qr">{!! $qrSvg !!}</div>
        </div>

        <div class="idc-right">
            <h2 class="idc-title">SK Finance</h2>
            <p class="idc-sub">SK Finance Portal</p>

            <div class="idc-row">
                <div>
                    <div class="idc-label">Date Issued</div>
                    <div class="idc-field">{{ $card['issued'] }}</div>
                </div>
                <div>
                    <div class="idc-label">Valid Until</div>
                    <div class="idc-field">{{ $card['valid_until'] }}</div>
                </div>
            </div>

            <div class="idc-label">Cardholder Signature</div>
            <div class="idc-field is-tall"></div>

            <div class="idc-label">SK Chairperson / Authorized Signatory</div>
            <div class="idc-field is-tall"></div>

            <p class="idc-foot">This ID is non-transferable. If found, please return to the Barangay Hall.</p>
        </div>
    </article>
</div>

<script>
    // Paper size for printing
    document.getElementById('idcSize').addEventListener('change', function () {
        document.getElementById('idcSheet').className = 'idc-sheet ' + this.value;
    });

    // Show the chosen photo on this page only (nothing is uploaded or stored)
    (function () {
        var input = document.getElementById('idcPhotoInput');
        var box = document.getElementById('idcPhoto');
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) { return; }
            var reader = new FileReader();
            reader.onload = function (e) {
                box.innerHTML = '<img alt="Photo" src="' + e.target.result + '">';
            };
            reader.readAsDataURL(file);
        });
    })();
</script>
</body>
</html>