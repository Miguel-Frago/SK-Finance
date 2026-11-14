<?php

use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Models\MeetingMinute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mnUser(string $role, string $slug = 'cantoria-no-4'): User
{
    $user = User::factory()->create(['name' => ucfirst($role) . ' Person', 'email' => "{$role}-{$slug}@example.com"]);
    $user->forceFill(['role' => $role, 'barangay_id' => Barangay::where('slug', $slug)->value('id')])->save();

    return $user;
}

function mnData(array $over = []): array
{
    return array_merge([
        'meeting_type' => 'regular',
        'title'        => '3rd Regular Meeting',
        'meeting_date' => '2026-03-15',
        'start_time'   => '08:00',
        'end_time'     => '10:30',
        'venue'        => 'Barangay Hall',
        'presiding'    => 'Pedro Chairman',
        'present'      => "Ana Cruz\nBen Reyes",
        'absent'       => 'Carl Diaz',
        'agenda'       => 'Sports equipment',
        'discussion'   => 'We agreed to buy sports equipment.',
        'resolutions'  => 'Resolution No. 1',
        'next_meeting' => 'April 15, 2026',
        'prepared_by'  => 'Sofia Secretary',
        'attested_by'  => 'Pedro Chairman',
        'status'       => 'draft',
    ], $over);
}

/** Saved minutes, in Cantoria No. 4 unless another barangay slug is given. */
function mnMinute(array $over = [], string $slug = 'cantoria-no-4'): MeetingMinute
{
    $minute = new MeetingMinute();
    $minute->forceFill(array_merge(mnData(), ['barangay_id' => Barangay::where('slug', $slug)->value('id')], $over))->save();

    return $minute;
}

/* ------------------------------------------------------------
 | The Secretary writes the minutes
 * ------------------------------------------------------------ */

it('lets the Secretary write minutes, saved as a draft in her own barangay', function () {
    $secretary = mnUser('secretary');

    $this->actingAs($secretary)->post('/minutes', mnData())->assertRedirect();

    $minute = MeetingMinute::first();

    expect($minute->title)->toBe('3rd Regular Meeting')
        ->and($minute->status)->toBe('draft')
        ->and($minute->barangay_id)->toEqual($secretary->barangay_id)
        ->and($minute->created_by)->toEqual($secretary->id)
        ->and($minute->meeting_date->format('Y-m-d'))->toBe('2026-03-15');

    $this->get(route('minutes.show', $minute))->assertOk()->assertSee('3rd Regular Meeting')->assertSee('Barangay Hall');
});

it('starts the form with the Chairman as presiding officer and the Secretary as the one who prepared it', function () {
    $chairman = mnUser('chairman');
    $this->actingAs(mnUser('secretary'))->get('/minutes/create')->assertOk()
        ->assertSee('Write minutes')
        ->assertSee($chairman->name)
        ->assertSee('Secretary Person');
});

it('needs a title, a date, what was discussed, who prepared it and a real kind and status', function () {
    $this->actingAs(mnUser('secretary'));

    foreach (['title', 'meeting_date', 'discussion', 'prepared_by', 'meeting_type', 'status'] as $field) {
        $this->post('/minutes', mnData([$field => '']))->assertSessionHasErrors($field);
    }

    $this->post('/minutes', mnData(['meeting_type' => 'party']))->assertSessionHasErrors('meeting_type');
    $this->post('/minutes', mnData(['status' => 'secret']))->assertSessionHasErrors('status');

    expect(MeetingMinute::count())->toBe(0);
});

it('needs the end time to be after the start time', function () {
    $this->actingAs(mnUser('secretary'))
        ->post('/minutes', mnData(['start_time' => '10:00', 'end_time' => '09:00']))
        ->assertSessionHasErrors('end_time');

    $this->post('/minutes', mnData(['start_time' => '', 'end_time' => '']))->assertSessionHasNoErrors();
});

it('lets only the Secretary add, edit or delete minutes', function () {
    $minute = mnMinute(['status' => 'final']);

    foreach (['treasurer', 'chairman', 'kagawad'] as $role) {
        $user = mnUser($role);

        $this->actingAs($user)->get('/minutes/create')->assertForbidden();
        $this->actingAs($user)->post('/minutes', mnData())->assertForbidden();
        $this->actingAs($user)->get(route('minutes.edit', $minute))->assertForbidden();
        $this->actingAs($user)->put(route('minutes.update', $minute), mnData(['title' => 'Changed']))->assertForbidden();
        $this->actingAs($user)->delete(route('minutes.destroy', $minute))->assertForbidden();
    }

    expect($minute->refresh()->title)->toBe('3rd Regular Meeting');
});

it('does not give the Chairman minutes rights when the Treasurer lets her edit the finances', function () {
    $chairman = mnUser('chairman');
    Barangay::find($chairman->barangay_id)->forceFill(['chairman_can_edit' => true])->save();

    $this->actingAs($chairman->fresh())->get('/minutes/create')->assertForbidden();
});

it('lets the Secretary edit minutes and records the change', function () {
    $secretary = mnUser('secretary');
    $minute    = mnMinute();

    $this->actingAs($secretary)->get(route('minutes.edit', $minute))->assertOk()->assertSee('Edit minutes');

    $this->put(route('minutes.update', $minute), mnData(['title' => 'Corrected title', 'status' => 'final']))
        ->assertRedirect(route('minutes.show', $minute));

    expect($minute->refresh()->title)->toBe('Corrected title')->and($minute->status)->toBe('final');

    $log = ActivityLog::where('subject_type', 'MeetingMinute')->where('action', 'updated')->first();
    expect($log)->not->toBeNull()->and($log->details)->toHaveKey('title');
});

it('records who created the minutes', function () {
    $secretary = mnUser('secretary');

    $this->actingAs($secretary)->post('/minutes', mnData());

    expect(ActivityLog::where('subject_type', 'MeetingMinute')->where('action', 'created')->where('user_id', $secretary->id)->exists())->toBeTrue();
});

it('deletes a draft but keeps final minutes', function () {
    $secretary = mnUser('secretary');
    $draft     = mnMinute(['title' => 'Draft one']);
    $final     = mnMinute(['title' => 'Final one', 'status' => 'final']);

    $this->actingAs($secretary)->delete(route('minutes.destroy', $draft))->assertRedirect(route('minutes.index'));
    $this->delete(route('minutes.destroy', $final))->assertSessionHasErrors('minutes');

    expect(MeetingMinute::whereKey($draft->id)->exists())->toBeFalse()
        ->and(MeetingMinute::whereKey($final->id)->exists())->toBeTrue();
});

/* ------------------------------------------------------------
 | Who can read what
 * ------------------------------------------------------------ */

it('shows the Secretary her drafts and the final minutes', function () {
    mnMinute(['title' => 'Draft one']);
    mnMinute(['title' => 'Final one', 'status' => 'final']);

    $this->actingAs(mnUser('secretary'))->get('/minutes')->assertOk()
        ->assertSee('Draft one')->assertSee('Final one')->assertSee('Write minutes');
});

it('shows everyone else only the final minutes', function () {
    mnMinute(['title' => 'Draft one']);
    $final = mnMinute(['title' => 'Final one', 'status' => 'final']);
    $draft = MeetingMinute::where('title', 'Draft one')->first();

    foreach (['treasurer', 'chairman', 'kagawad'] as $role) {
        $user = mnUser($role);

        $this->actingAs($user)->get('/minutes')->assertOk()
            ->assertSee('Final one')->assertDontSee('Draft one')->assertDontSee('Write minutes');

        $this->actingAs($user)->get(route('minutes.show', $final))->assertOk()->assertSee('Final one');
        $this->actingAs($user)->get(route('minutes.show', $draft))->assertNotFound();
    }
});

it('keeps each barangay\'s minutes to itself', function () {
    $other = mnMinute(['title' => 'Suyo meeting', 'status' => 'final'], 'suyo');

    $secretary = mnUser('secretary');

    $this->actingAs($secretary)->get('/minutes')->assertOk()->assertDontSee('Suyo meeting');
    $this->get(route('minutes.show', $other))->assertNotFound();
    $this->put(route('minutes.update', $other), mnData(['title' => 'Hacked']))->assertNotFound();

    expect($other->refresh()->title)->toBe('Suyo meeting');
});

it('filters the minutes by search, kind and year', function () {
    mnMinute(['title' => 'Budget meeting', 'meeting_date' => '2026-02-01', 'status' => 'final']);
    mnMinute(['title' => 'Assembly of the youth', 'meeting_type' => 'assembly', 'meeting_date' => '2025-06-01', 'status' => 'final']);

    $secretary = mnUser('secretary');

    $this->actingAs($secretary)->get('/minutes?q=Budget')->assertOk()->assertSee('Budget meeting')->assertDontSee('Assembly of the youth');
    $this->get('/minutes?type=assembly')->assertOk()->assertSee('Assembly of the youth')->assertDontSee('Budget meeting');
    $this->get('/minutes?year=2026')->assertOk()->assertSee('Budget meeting')->assertDontSee('Assembly of the youth');
});

it('shows what was typed as text, not as code', function () {
    $minute = mnMinute(['discussion' => '<script>alert(1)</script>', 'status' => 'final']);

    $this->actingAs(mnUser('secretary'))->get(route('minutes.show', $minute))->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

    $this->get(route('minutes.print', $minute))->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});

/* ------------------------------------------------------------
 | Printing
 * ------------------------------------------------------------ */

it('prints the minutes for the Secretary, with the barangay, the signatures and a DRAFT mark', function () {
    $minute = mnMinute();

    $this->actingAs(mnUser('secretary'))->get(route('minutes.print', $minute))->assertOk()
        ->assertSee('Barangay Cantoria No. 4')
        ->assertSee('3rd Regular Meeting')
        ->assertSee('Sofia Secretary')
        ->assertSee('Prepared by')
        ->assertSee('Attested by')
        ->assertSee('DRAFT');
});

it('prints final minutes without the DRAFT mark', function () {
    $minute = mnMinute(['status' => 'final']);

    $this->actingAs(mnUser('secretary'))->get(route('minutes.print', $minute))->assertOk()->assertDontSee('DRAFT');
});

it('lets the Chairman print final minutes but not drafts', function () {
    $final = mnMinute(['status' => 'final']);
    $draft = mnMinute(['title' => 'Draft one']);

    $chairman = mnUser('chairman');

    $this->actingAs($chairman)->get(route('minutes.print', $final))->assertOk();
    $this->get(route('minutes.print', $draft))->assertNotFound();
});

it('does not let the Treasurer or a Kagawad print the minutes', function () {
    $minute = mnMinute(['status' => 'final']);

    foreach (['treasurer', 'kagawad'] as $role) {
        $this->actingAs(mnUser($role))->get(route('minutes.print', $minute))->assertForbidden();
    }
});

it('shows the Print button only to those who may print', function () {
    $minute = mnMinute(['status' => 'final']);

    $this->actingAs(mnUser('secretary'))->get(route('minutes.show', $minute))->assertSee('Print');
    $this->actingAs(mnUser('kagawad'))->get(route('minutes.show', $minute))->assertDontSee(route('minutes.print', $minute), false);
});
