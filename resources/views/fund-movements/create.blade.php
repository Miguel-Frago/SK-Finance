@extends('layouts.app')

@section('title', 'Record Fund Movement')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Record fund movement</h2>
            <p class="muted">
                {{ $budget->display_name }} &middot;
                Bank <strong>@peso($balances['bank'])</strong> &middot;
                Cash on hand <strong>@peso($balances['cash'])</strong> &middot;
                Budget not yet spent <strong>@peso($balances['unspent'])</strong>
            </p>
        </div>
        <a href="{{ route('fund-movements.index') }}" class="btn btn-secondary">Back to fund movements</a>
    </div>

    @include('fund-movements._form', [
        'action'      => route('fund-movements.store'),
        'submitLabel' => 'Record movement',
    ])
@endsection
