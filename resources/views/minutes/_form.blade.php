{{--
    Minutes form shared by create and edit.
    Expects: $action, $submitLabel, $minute (null when creating), $defaults (starting values when creating)
--}}
@php
    $val = fn (string $key) => old($key, $minute?->{$key} ?? ($defaults[$key] ?? null));
    $dateValue = old('meeting_date', $minute?->meeting_date?->format('Y-m-d') ?? ($defaults['meeting_date'] ?? ''));
@endphp

<form method="POST" action="{{ $action }}" class="form">
    @csrf
    @if ($minute)
        @method('PUT')
    @endif

    <div class="card form-main">
        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">1</span>
                <div>
                    <h3>The meeting</h3>
                    <p>What kind of meeting it was, and when and where it took place.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group">
                    <label for="meeting_type">Kind of meeting</label>
                    <select id="meeting_type" name="meeting_type" required>
                        @foreach (\App\Models\MeetingMinute::TYPES as $key => $label)
                            <option value="{{ $key }}" @selected($val('meeting_type') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('meeting_type')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="meeting_date">Date</label>
                    <input id="meeting_date" type="date" name="meeting_date" required value="{{ $dateValue }}">
                    @error('meeting_date')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group form-wide">
                    <label for="title">Title</label>
                    <input id="title" type="text" name="title" maxlength="255" required value="{{ $val('title') }}" placeholder="e.g. 3rd Regular Meeting of the Sangguniang Kabataan">
                    @error('title')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="start_time">Started at</label>
                    <input id="start_time" type="time" name="start_time" value="{{ $val('start_time') }}">
                    @error('start_time')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="end_time">Ended at</label>
                    <input id="end_time" type="time" name="end_time" value="{{ $val('end_time') }}">
                    @error('end_time')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="venue">Venue</label>
                    <input id="venue" type="text" name="venue" maxlength="255" value="{{ $val('venue') }}" placeholder="e.g. Barangay Hall">
                    @error('venue')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="presiding">Presiding officer</label>
                    <input id="presiding" type="text" name="presiding" maxlength="255" value="{{ $val('presiding') }}">
                    @error('presiding')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>

        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">2</span>
                <div>
                    <h3>Who attended</h3>
                    <p>One name per line.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group">
                    <label for="present">Present</label>
                    <textarea id="present" name="present" rows="6" maxlength="5000">{{ $val('present') }}</textarea>
                    @error('present')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="absent">Absent</label>
                    <textarea id="absent" name="absent" rows="6" maxlength="5000">{{ $val('absent') }}</textarea>
                    @error('absent')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>

        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">3</span>
                <div>
                    <h3>What happened</h3>
                    <p>The agenda, what was discussed and decided, and the resolutions or action points.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group form-wide">
                    <label for="agenda">Agenda</label>
                    <textarea id="agenda" name="agenda" rows="4" maxlength="10000">{{ $val('agenda') }}</textarea>
                    @error('agenda')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group form-wide">
                    <label for="discussion">Discussion and decisions</label>
                    <textarea id="discussion" name="discussion" rows="10" maxlength="20000" required>{{ $val('discussion') }}</textarea>
                    @error('discussion')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group form-wide">
                    <label for="resolutions">Resolutions and action points</label>
                    <textarea id="resolutions" name="resolutions" rows="5" maxlength="10000">{{ $val('resolutions') }}</textarea>
                    @error('resolutions')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group form-wide">
                    <label for="next_meeting">Next meeting</label>
                    <input id="next_meeting" type="text" name="next_meeting" maxlength="255" value="{{ $val('next_meeting') }}" placeholder="e.g. November 15, 2026, 2:00 PM, Barangay Hall">
                    @error('next_meeting')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>

        <section class="form-section">
            <div class="form-section-head">
                <span class="step-num">4</span>
                <div>
                    <h3>Signatures and status</h3>
                    <p>The names printed under the minutes, and whether the barangay can read them yet.</p>
                </div>
            </div>

            <div class="form-fields">
                <div class="form-group">
                    <label for="prepared_by">Prepared by (SK Secretary)</label>
                    <input id="prepared_by" type="text" name="prepared_by" maxlength="255" required value="{{ $val('prepared_by') }}">
                    @error('prepared_by')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="attested_by">Attested by (SK Chairperson)</label>
                    <input id="attested_by" type="text" name="attested_by" maxlength="255" value="{{ $val('attested_by') }}">
                    @error('attested_by')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-group form-wide">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="draft" @selected(($val('status') ?? 'draft') === 'draft')>Draft: only I can see it</option>
                        <option value="final" @selected($val('status') === 'final')>Final: everyone in the barangay can read it</option>
                    </select>
                    @error('status')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>

        <div class="form-actions">
            <a href="{{ $minute ? route('minutes.show', $minute) : route('minutes.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
        </div>
    </div>
</form>
