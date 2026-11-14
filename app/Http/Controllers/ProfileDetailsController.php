<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Profile photo and position: the details printed on the Access ID (the barangay comes from the account itself).
 * The photo is saved in public/uploads/profile (no "storage:link" needed) under a random file name.
 */
class ProfileDetailsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('details', [
            'position'     => ['nullable', 'string', 'max:80'],
            'photo'        => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_photo' => ['nullable', 'boolean'],
        ], [
            'photo.image'  => 'Please choose a picture (JPG, PNG or WEBP).',
            'photo.mimes'  => 'The photo must be a JPG, PNG or WEBP picture.',
            'photo.max'    => 'The photo is too big. Please use one under 3 MB.',
            'photo.uploaded' => 'The photo could not be uploaded. Please use one under 3 MB.',
        ]);

        $user = $request->user();

        $user->position = filled($data['position'] ?? null) ? trim($data['position']) : null;

        if ($request->hasFile('photo')) {
            $this->deletePhoto($user->photo_path);

            $directory = public_path('uploads/profile');
            File::ensureDirectoryExists($directory);

            $name = Str::random(40) . '.' . $request->file('photo')->extension();
            $request->file('photo')->move($directory, $name);

            $user->photo_path = 'uploads/profile/' . $name;
        } elseif ($request->boolean('remove_photo')) {
            $this->deletePhoto($user->photo_path);
            $user->photo_path = null;
        }

        $user->save();

        return redirect()->route('profile.edit')->with('status_details', 'Your ID details were saved.');
    }

    /** Only ever deletes files inside uploads/profile. */
    private function deletePhoto(?string $path): void
    {
        if ($path && str_starts_with($path, 'uploads/profile/') && ! str_contains($path, '..')) {
            File::delete(public_path($path));
        }
    }
}
