@extends('layouts.app')

@section('title', 'Record Expense')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Record expense</h2>
            <p class="muted">
                {{ $budget->display_name }} &middot;
                Bank <strong>@peso($accounts['bank'])</strong> &middot;
                Cash on hand <strong>@peso($accounts['cash_on_hand'])</strong>
            </p>
        </div>
        <a href="{{ route('transactions.index') }}" class="btn btn-secondary">Back to transactions</a>
    </div>

    @if (empty($catalog))
        <section class="card card-narrow">
            <h3 class="card-title">There are no budget items to spend from yet</h3>
            <p class="muted">Every expense is charged to an item. Add items to a category first.</p>
            <a href="{{ route('budget.index') }}" class="btn btn-primary">Go to Budget &amp; Allocation</a>
        </section>
    @else
        @include('transactions._form', [
            'action'      => route('transactions.store'),
            'submitLabel' => 'Record expense',
        ])
    @endif
@endsection
