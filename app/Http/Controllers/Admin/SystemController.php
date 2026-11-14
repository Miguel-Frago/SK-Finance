<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SystemCheck;
use Illuminate\View\View;

/**
 * "System check" for the Admin: the same checks as `php artisan sk:check`, but run by the WEB server, so the PHP settings
 * shown here are the ones that really apply when somebody uploads a file (the command line can read another php.ini).
 * It shows settings only: no accounts, no money, no messages.
 */
class SystemController extends Controller
{
    public function index(): View
    {
        $results = (new SystemCheck())->run();

        return view('admin.system', [
            'results'  => $results,
            'failures' => SystemCheck::failures($results),
            'warnings' => count(array_filter($results, fn ($r) => $r['status'] === SystemCheck::WARN)),
            'server'   => [
                'PHP version'            => PHP_VERSION,
                'upload_max_filesize'    => (string) ini_get('upload_max_filesize'),
                'post_max_size'          => (string) ini_get('post_max_size'),
                'Server software'        => (string) ($_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name()),
                'Environment'            => app()->environment(),
            ],
        ]);
    }
}
