<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings of the signed-in person's barangay portal. Only the SK Treasurer reaches this (can:barangay-settings).
 * One switch: lets the SK Chairman add, edit and delete budgets, expenses and fund movements too.
 */
class BarangaySettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $barangay = $request->user()->portal();

        abort_if($barangay === null, 404);

        $barangay->chairman_can_edit = $request->boolean('chairman_can_edit');
        $barangay->save();   // the change is written to the activity log by the model

        return redirect()->route('profile.edit')->with(
            'status_barangay',
            $barangay->chairman_can_edit
                ? 'The SK Chairman can now add and edit finance records.'
                : 'The SK Chairman can no longer add or edit finance records.'
        );
    }
}
