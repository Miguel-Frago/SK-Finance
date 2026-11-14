<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:14px;padding:28px;">
        <p style="margin:0 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;">SK Finance &middot; Municipality of Luna, La Union</p>
        <h2 style="margin:0 0 18px;font-size:20px;line-height:1.3;">{{ $heading }}</h2>

        @foreach ($lines as $line)
            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">{!! nl2br(e($line)) !!}</p>
        @endforeach

        @if ($code)
            <p style="margin:18px 0;text-align:center;">
                <span style="display:inline-block;padding:12px 22px;border:2px dashed #94a3b8;border-radius:12px;background:#f8fafc;font-family:Consolas,Menlo,monospace;font-size:26px;font-weight:700;letter-spacing:.14em;">{{ $code }}</span>
            </p>
        @endif

        @if ($actionUrl)
            <p style="margin:18px 0;text-align:center;">
                <a href="{{ $actionUrl }}" style="display:inline-block;padding:11px 22px;border-radius:10px;background:#1d4ed8;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">{{ $actionLabel ?? 'Open SK Finance' }}</a>
            </p>
        @endif

        <p style="margin:22px 0 0;font-size:12px;line-height:1.5;color:#64748b;">
            Sent by the SK Federation of Luna through SK Finance. You can reply to this email.
        </p>
    </div>
</body>
</html>
