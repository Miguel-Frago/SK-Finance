<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Saves which SK Guide character the Admin picked (boy or girl). It uses the "admin" guard on purpose: a barangay
 * account can be signed in in the same browser, and its choice must never be overwritten from the Admin pages.
 */
class GuideCharacterController extends Controller
{
    public const CHARACTERS = ['boy', 'girl'];

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'character' => ['required', 'in:' . implode(',', self::CHARACTERS)],
        ]);

        // The column comes from a migration. If it was not run yet, say so instead of failing with a blank error.
        if (! Schema::hasColumn('admins', 'guide_character')) {
            return response()->json(['ok' => false, 'message' => 'Not saved yet: run "php artisan migrate" once.'], 409);
        }

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $admin->forceFill(['guide_character' => $data['character']])->save();

        return response()->json(['ok' => true, 'character' => $data['character']]);
    }
}