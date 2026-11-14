<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Saves which SK Guide character the signed-in Treasurer or Chairman chose (boy or girl). Each account has its own. */
class TreasurerGuideController extends Controller
{
    public const CHARACTERS = ['boy', 'girl'];

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'character' => ['required', 'in:' . implode(',', self::CHARACTERS)],
        ]);

        // The column comes from a migration. If it was not run yet, say so instead of failing with a blank error.
        if (! Schema::hasColumn('users', 'guide_character')) {
            return response()->json(['ok' => false, 'message' => 'Not saved yet: run "php artisan migrate" once.'], 409);
        }

        $request->user()->forceFill(['guide_character' => $data['character']])->save();

        return response()->json(['ok' => true, 'character' => $data['character']]);
    }
}