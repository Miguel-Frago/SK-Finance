@extends('layouts.app')

@section('title', 'Edit Transaction')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Edit transaction</h2>
            <p class="muted">
                {{ $budget->display_name }} &middot;
                Bank <strong>@peso($accounts['bank'])</strong> &middot;
                Cash on hand <strong>@peso($accounts['cash_on_hand'])</strong>
            </p>
        </div>
        <a href="{{ route('transactions.index') }}" class="btn btn-secondary">Back to transactions</a>
    </div>

    @include('transactions._form', [
        'action'      => route('transactions.update', $transaction),
        'submitLabel' => 'Save changes',
    ])
@endsection
