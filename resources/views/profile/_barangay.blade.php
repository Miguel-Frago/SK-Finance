@php $portal = $user->portal(); @endphp

@if ($user->isTreasurer() || $user->isChairman())
<section class="card" id="barangay-portal">
    <h3 class="card-title">Barangay portal</h3>
    <p class="muted">SK Finance of <strong>{{ $portal?->name }}</strong>, Luna, La Union. Only the people of this barangay can see its records.</p>

    @if (session('status_barangay'))
        <div class="alert alert-success">{{ session('status_barangay') }}</div>
    @endif

    @can('barangay-settings')
        <form method="POST" action="{{ route('barangay.settings') }}" class="form">
            @csrf
            @method('PUT')

            <label class="ax-check">
                <input type="checkbox" name="chairman_can_edit" value="1" @checked($portal?->chairman_can_edit)>
                Let the SK Chairman add and edit budgets, expenses and fund movements
            </label>
            <p class="muted" style="font-size:.88rem;">
                Off by default: the Chairman can then only view, print and edit the names and signatures on the report.
                You can switch this off again at any time. Every change the Chairman makes is recorded in the activity log.
            </p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    @else
        <p class="muted">
            The SK Chairman can add and edit finance records:
            <strong>{{ $portal?->chairman_can_edit ? 'allowed' : 'not allowed' }}</strong>.
            Only the SK Treasurer can change this.
        </p>
    @endcan
</section>
@endif
