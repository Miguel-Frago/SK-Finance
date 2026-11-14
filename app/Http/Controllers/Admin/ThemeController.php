<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Saves the colour theme of the Admin. It uses the "admin" guard on purpose: a barangay account can be signed in
 * in the same browser, and its theme must never be overwritten from the Admin pages.
 */
class ThemeController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sidebar' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent'  => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'preset'  => ['nullable', 'string', 'max:20'],
        ]);

        Auth::guard('admin')->user()->forceFill([
            'theme' => [
                'sidebar' => strtolower($data['sidebar']),
                'accent'  => strtolower($data['accent']),
                'preset'  => $data['preset'] ?? 'custom',
            ],
        ])->save();

        return response()->json(['ok' => true]);
    }
}
