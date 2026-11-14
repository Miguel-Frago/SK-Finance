@extends('layouts.app')

@section('title', 'Edit Minutes')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Edit minutes</h2>
            <p class="muted">{{ $minute->title }} &middot; every change is recorded in the activity log.</p>
        </div>
        <a href="{{ route('minutes.show', $minute) }}" class="btn btn-secondary">Back to the minutes</a>
    </div>

    @include('minutes._form', ['action' => route('minutes.update', $minute), 'submitLabel' => 'Save changes'])
@endsection
