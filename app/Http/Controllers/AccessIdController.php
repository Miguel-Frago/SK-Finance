<?php

namespace App\Http\Controllers;

use App\Support\AccessQr;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Creates / replaces / revokes the signed-in person's QR Access ID (Profile page).
 * The secret token is shown ONCE, right after it is created, and only its hash is kept.
 */
class AccessIdController extends Controller
{
    /** Set a PIN and make a new QR. Making a new one switches the old card off. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('accessId', [
            'current_password' => ['required', 'current_password'],
            'pin'              => ['required', 'digits:6', 'confirmed'],
            'valid_until'      => ['required', 'date', 'after:today'],
        ], [
            'pin.digits'     => 'The PIN must be exactly 6 digits.',
            'pin.confirmed'  => 'The two PINs do not match.',
            'valid_until.after' => 'The card must be valid until a future date.',
        ], ['current_password' => 'current password', 'valid_until' => 'valid until']);

        $user = $request->user();

        $token  = Str::random(48);
        $issued = now();

        $user->forceFill([
            'qr_token_hash' => AccessQr::hash($token),
            'qr_pin'        => $data['pin'],   // hashed by the User model
            'qr_issued_at'  => $issued,
        ])->save();

        // Everything the printable card needs. The secret QR address lives only in the session,
        // until the person presses "Done" (or the session ends). The PIN is NOT part of it.
        session()->put('access_id_card', [
            'url'         => AccessQr::url($token),
            'name'        => $user->name,
            'position'    => $user->card_position,
            'barangay'    => $user->portal()?->card_label ?? '',
            'photo'       => $user->photo_url,
            'id_no'       => sprintf('SKF-%s-%03d', $issued->format('Y'), $user->id),
            'issued'      => $issued->format('M d, Y'),
            'valid_until' => Carbon::parse($data['valid_until'])->format('M d, Y'),
        ]);

        return redirect()->route('access-id.card');
    }

    /** The printable ID (front and back) with the new QR code. Only available right after it is created. */
    public function card(): Response|RedirectResponse
    {
        $card = session('access_id_card');

        if (! $card) {
            return redirect()->route('profile.edit')->with('status_access_id',
                'The QR code is only shown right after it is created. To print a card again, make a new QR code.');
        }

        return response()
            ->view('profile.access-card', ['card' => $card, 'qrSvg' => AccessQr::svg($card['url'], 400)])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Close the card page and wipe the QR address from the session. */
    public function done(): RedirectResponse
    {
        session()->forget('access_id_card');

        return redirect()->route('profile.edit')->with('status_access_id', 'Access ID saved. Keep the printed card safe.');
    }

    /** Switch the card off completely. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('accessIdRevoke', [
            'current_password' => ['required', 'current_password'],
        ], [], ['current_password' => 'current password']);

        $request->user()->forceFill([
            'qr_token_hash' => null,
            'qr_pin'        => null,
            'qr_issued_at'  => null,
        ])->save();

        return redirect()->route('profile.edit')->with('status_access_id', 'Access ID revoked. The old card no longer works.');
    }
}
