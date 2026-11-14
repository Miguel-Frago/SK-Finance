<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Saves the colour theme of the signed-in person (each account has its own). */
class ThemeController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sidebar' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent'  => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'preset'  => ['nullable', 'string', 'max:20'],
        ]);

        $request->user()->forceFill([
            'theme' => [
                'sidebar' => strtolower($data['sidebar']),
                'accent'  => strtolower($data['accent']),
                'preset'  => $data['preset'] ?? 'custom',
            ],
        ])->save();

        return response()->json(['ok' => true]);
    }
}
