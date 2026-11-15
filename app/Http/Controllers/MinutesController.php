<?php

namespace App\Http\Controllers;

use App\Models\MeetingMinute;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Minutes of meetings. The SK Secretary writes, edits and prints them; the SK Chairman can print the final ones;
 * everyone else in the barangay can read the FINAL ones. Drafts are seen only by the Secretary.
 * (Each barangay only ever sees its own minutes: MeetingMinute is scoped to the barangay of the signed-in person.)
 */
class MinutesController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canViewMinutes(), 403);

        $filters = $request->validate([
            'q'      => ['nullable', 'string', 'max:100'],
            'type'   => ['nullable', Rule::in(array_keys(MeetingMinute::TYPES))],
            'year'   => ['nullable', 'digits:4'],
            'status' => ['nullable', Rule::in(array_keys(MeetingMinute::STATUSES))],
        ]);

        $visible = fn () => MeetingMinute::query()
            ->when(! $user->canManageMinutes(), fn ($q) => $q->where('status', MeetingMinute::FINAL));

        $minutes = $visible()
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('title', 'like', "%{$term}%")
                        ->orWhere('venue', 'like', "%{$term}%")
                        ->orWhere('agenda', 'like', "%{$term}%")
                        ->orWhere('discussion', 'like', "%{$term}%");
                });
            })
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('meeting_type', $type))
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->whereYear('meeting_date', $year))
            ->when($user->canManageMinutes() ? ($filters['status'] ?? null) : null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('meeting_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $years = $visible()->pluck('meeting_date')->map(fn ($date) => $date->format('Y'))->unique()->sortDesc()->values();

        return view('minutes.index', compact('minutes', 'filters', 'years'));
    }

    public function show(Request $request, MeetingMinute $minute): View
    {
        abort_unless($request->user()->canViewMinutes(), 403);
        $this->authorizeRead($request->user(), $minute);

        return view('minutes.show', ['minute' => $minute]);
    }

    public function create(Request $request): View
    {
        $chairman = User::activeHolder($request->user()->portalId(), User::ROLE_CHAIRMAN);

        return view('minutes.create', [
            'minute'   => null,
            'defaults' => [
                'meeting_date' => now()->toDateString(),
                'presiding'    => $chairman?->name,
                'prepared_by'  => $request->user()->name,
                'attested_by'  => $chairman?->name,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $minute = new MeetingMinute($this->validated($request));
        $minute->created_by = $request->user()->id;
        $minute->save();   // the barangay is stamped by the model

        return redirect()->route('minutes.show', $minute)->with('success', 'The minutes were saved.');
    }

    public function edit(MeetingMinute $minute): View
    {
        return view('minutes.edit', ['minute' => $minute, 'defaults' => []]);
    }

    public function update(Request $request, MeetingMinute $minute): RedirectResponse
    {
        $minute->update($this->validated($request));

        return redirect()->route('minutes.show', $minute)->with('success', 'The minutes were updated.');
    }

    public function destroy(MeetingMinute $minute): RedirectResponse
    {
        // Final minutes are a record the barangay has read: they are not deleted (they can still be corrected).
        if ($minute->isFinal()) {
            return redirect()->route('minutes.show', $minute)
                ->withErrors(['minutes' => 'Final minutes cannot be deleted. They can still be corrected.']);
        }

        $minute->delete();

        return redirect()->route('minutes.index')->with('success', 'The draft was deleted.');
    }

    /** A page made for paper (opens in its own tab). */
    public function print(Request $request, MeetingMinute $minute): View
    {
        $this->authorizeRead($request->user(), $minute);

        return view('minutes.print', ['minute' => $minute->load('barangay')]);
    }

    /** Drafts are the Secretary's own: everyone else gets a 404, as if they did not exist. */
    private function authorizeRead(User $user, MeetingMinute $minute): void
    {
        abort_if(! $user->canViewMinutes() || (! $user->canManageMinutes() && ! $minute->isFinal()), 404);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'meeting_type' => ['required', Rule::in(array_keys(MeetingMinute::TYPES))],
            'title'        => ['required', 'string', 'max:255'],
            'meeting_date' => ['required', 'date'],
            'start_time'   => ['nullable', 'date_format:H:i'],
            'end_time'     => ['nullable', 'date_format:H:i', Rule::when(filled($request->input('start_time')), ['after:start_time'])],
            'venue'        => ['nullable', 'string', 'max:255'],
            'presiding'    => ['nullable', 'string', 'max:255'],
            'present'      => ['nullable', 'string', 'max:5000'],
            'absent'       => ['nullable', 'string', 'max:5000'],
            'agenda'       => ['nullable', 'string', 'max:10000'],
            'discussion'   => ['required', 'string', 'max:20000'],
            'resolutions'  => ['nullable', 'string', 'max:10000'],
            'next_meeting' => ['nullable', 'string', 'max:255'],
            'prepared_by'  => ['required', 'string', 'max:255'],
            'attested_by'  => ['nullable', 'string', 'max:255'],
            'status'       => ['required', Rule::in(array_keys(MeetingMinute::STATUSES))],
        ], [
            'meeting_type.required' => 'Please choose the kind of meeting.',
            'discussion.required'   => 'Please write what was discussed and decided.',
            'end_time.after'        => 'The end time must be after the start time.',
        ], [
            'meeting_date' => 'meeting date',
            'prepared_by'  => 'prepared by',
        ]);
    }
}
