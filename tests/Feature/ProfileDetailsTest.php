<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/** A real 1x1 PNG picture, so the tests do not need PHP's GD extension. */
function tinyPng(string $name = 'me.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent($name, $png);
}

/** Delete a photo a test saved, so the real uploads folder stays clean. */
function cleanPhoto(?User $user): void
{
    if ($user && $user->photo_path) {
        File::delete(public_path($user->photo_path));
    }
}

it('saves the position', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('profile.details'), [
        'position' => 'SK Treasurer',
    ])->assertRedirect(route('profile.edit'));

    expect($user->refresh()->card_position)->toBe('SK Treasurer');
});

it('does not let the profile change the barangay of the account', function () {
    $user = User::factory()->create();
    $before = $user->barangay_id;

    $this->actingAs($user)->put(route('profile.details'), [
        'position'    => 'SK Treasurer',
        'barangay_id' => \App\Models\Barangay::where('slug', 'suyo')->value('id'),
    ])->assertRedirect(route('profile.edit'));

    expect($user->refresh()->barangay_id)->toEqual($before);
});

it('falls back to the role when no position is typed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('profile.details'), []);

    expect($user->refresh()->card_position)->toBe($user->role_label);
});

it('uploads a photo, replaces it and removes it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('profile.details'), [
        'photo'    => tinyPng(),
    ])->assertSessionHasNoErrors();

    $first = $user->refresh()->photo_path;
    expect($first)->toStartWith('uploads/profile/')
        ->and(File::exists(public_path($first)))->toBeTrue();

    $this->put(route('profile.details'), [
        'photo'    => tinyPng(),
    ]);

    $second = $user->refresh()->photo_path;
    expect($second)->not->toBe($first)
        ->and(File::exists(public_path($first)))->toBeFalse()
        ->and(File::exists(public_path($second)))->toBeTrue();

    $this->put(route('profile.details'), ['remove_photo' => '1']);

    expect($user->refresh()->photo_path)->toBeNull()
        ->and(File::exists(public_path($second)))->toBeFalse();
});

it('rejects a file that is not a picture', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('profile.details'), [
        'photo'    => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
    ])->assertSessionHasErrors('photo', null, 'details');

    expect($user->refresh()->photo_path)->toBeNull();
});

it('puts the saved photo on the card page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('profile.details'), [
        'photo'    => tinyPng(),
    ]);

    $user->refresh();

    $this->post(route('access-id.store'), [
        'current_password' => 'password',
        'pin'              => '123456',
        'pin_confirmation' => '123456',
        'valid_until'      => now()->addYear()->toDateString(),
    ]);

    $this->get(route('access-id.card'))->assertOk()->assertSee($user->photo_path);

    cleanPhoto($user);
});

it('shows the details section on the profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee('Photo and ID details');
});
