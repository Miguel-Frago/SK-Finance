<?php

namespace App\Http\Controllers;

use App\Support\AccessQr;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Share sign-up": a page with big QR codes that open the Create account and Sign in pages, for a room full of
 * phones on the same wifi. The address inside the QR is the address THIS page was opened with, so open it
 * with the address the phones will use (e.g. http://192.168.1.10:8000/join), not with sk-finance.test.
 */
class JoinController extends Controller
{
    public function show(Request $request): View
    {
        $base = $request->getSchemeAndHttpHost();
        $host = $request->getHost();

        $registerUrl = $base . '/request-access';   // new people start by asking the Admin for access
        $loginUrl    = $base . '/login';

        return view('join.show', [
            'registerUrl' => $registerUrl,
            'loginUrl'    => $loginUrl,
            'registerSvg' => AccessQr::svg($registerUrl, 360),
            'loginSvg'    => AccessQr::svg($loginUrl, 360),
            // a phone can never open these addresses, so the QR codes would be useless
            'pcOnly'      => in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test'),
        ]);
    }
}
