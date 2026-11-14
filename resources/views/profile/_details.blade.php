<section class="card" id="id-details">
    <h3 class="card-title">Photo and ID details</h3>
    <p class="muted">These are printed on your Access ID. Add them here once and they are used every time you make a card.</p>

    @if (session('status_details'))
        <div class="alert alert-success">{{ session('status_details') }}</div>
    @endif

    <form method="POST" action="{{ route('profile.details') }}" enctype="multipart/form-data" class="form form-grid">
        @csrf
        @method('PUT')

        <div class="form-group form-wide">
            <label for="d_photo">Photo (square, 1x1 works best)</label>
            <div class="photo-edit">
                <span class="photo-edit-box" id="photoBox">
                    @if ($user->photo_url)
                        <img src="{{ $user->photo_url }}" alt="Your photo" id="photoPreview">
                    @else
                        <img alt="" id="photoPreview" hidden>
                        <span id="photoEmpty">No photo</span>
                    @endif
                </span>
                <div>
                    <input id="d_photo" type="file" name="photo" accept="image/png,image/jpeg,image/webp">
                    @if ($user->photo_url)
                        <label class="ax-check" style="margin-top:.5rem;">
                            <input type="checkbox" name="remove_photo" value="1"> Remove my photo
                        </label>
                    @endif
                    @error('photo', 'details')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
        </div>

        <div class="form-group">
            <label for="d_position">Position on the card <small class="hint">(leave empty to use "{{ $user->role_label }}")</small></label>
            <input id="d_position" type="text" name="position" value="{{ old('position', $user->position) }}" maxlength="80" placeholder="{{ $user->role_label }}">
            @error('position', 'details')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save ID details</button>
        </div>
    </form>
</section>

<script>
    // Show the chosen picture right away, before saving
    (function () {
        var input = document.getElementById('d_photo');
        var preview = document.getElementById('photoPreview');
        var empty = document.getElementById('photoEmpty');
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) { return; }
            var reader = new FileReader();
            reader.onload = function (e) {
                preview.src = e.target.result;
                preview.hidden = false;
                if (empty) { empty.hidden = true; }
            };
            reader.readAsDataURL(file);
        });
    })();
</script>
