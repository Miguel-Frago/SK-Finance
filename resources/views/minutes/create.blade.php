@extends('layouts.app')

@section('title', 'Write Minutes')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Write minutes</h2>
            <p class="muted">Save it as a draft while you work. Mark it Final when it is ready for the barangay to read.</p>
        </div>
        <a href="{{ route('minutes.index') }}" class="btn btn-secondary">Back to the minutes</a>
    </div>

    @include('minutes._form', ['action' => route('minutes.store'), 'submitLabel' => 'Save the minutes'])
@endsection
