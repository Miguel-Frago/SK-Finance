<?php

namespace App\Http\Requests;

use App\Models\Budget;
use App\Models\FundMovement;
use App\Support\FundBalances;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for recording and editing a fund movement (used by store and update).
 *
 * Besides the field rules it enforces the money rules:
 *   - a receipt needs an account; withdrawals and deposits must not have one
 *   - the date must fall inside the budget's year
 *   - the change cannot leave the bank account or cash on hand below zero
 *     (a withdrawal cannot exceed the bank, a deposit cannot exceed cash on hand)
 */
class FundMovementRequest extends FormRequest
{
    private ?Budget $budget = null;

    public function authorize(): bool
    {
        return true; // the routes are already behind the auth middleware
    }

    public function rules(): array
    {
        return [
            'date'      => ['required', 'date'],
            'type'      => ['required', Rule::in(array_keys(FundMovement::TYPES))],
            'account'   => ['required_if:type,receipt', 'nullable', Rule::in(array_keys(FundMovement::ACCOUNTS))],
            'amount'    => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'reference' => ['nullable', 'string', 'max:100'],
            'remarks'   => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'account.required_if' => 'Choose whether the funds went into the bank or into cash on hand.',
            'amount.gt'           => 'The amount must be greater than zero.',
            'amount.decimal'      => 'The amount can have at most 2 decimal places.',
        ];
    }

    /** The movement being edited, or null when recording a new one. */
    public function movement(): ?FundMovement
    {
        $movement = $this->route('fundMovement');

        return $movement instanceof FundMovement ? $movement : null;
    }

    /** An edited movement stays in its own budget; a new one goes into the current budget. */
    public function budget(): ?Budget
    {
        return $this->budget ??= ($this->movement()?->budget ?? Budget::current());
    }

    /** Clean data for create/update. Withdrawals and deposits never carry an account. */
    public function movementData(): array
    {
        $data = $this->safe()->only(['date', 'type', 'account', 'amount', 'reference', 'remarks']);
        $data = array_merge(['account' => null, 'reference' => null, 'remarks' => null], $data);

        $data['amount']  = round((float) $data['amount'], 2);
        $data['account'] = $data['type'] === 'receipt' ? $data['account'] : null;

        return $data;
    }

    /* ------------------------------------------------------------
     | Money rules (run only after the basic field rules pass)
     * ------------------------------------------------------------ */

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                $this->checkRules($validator->errors());
            }
        });
    }

    private function checkRules(MessageBag $errors): void
    {
        $budget = $this->budget();

        if (! $budget) {
            $errors->add('date', 'Create a budget first; fund movements are recorded against it.');

            return;
        }

        if ($this->input('type') !== 'receipt' && $this->filled('account')) {
            $errors->add('account', 'Only funds received use an account. Withdrawals and deposits move money between the bank and cash on hand.');
        }

        $this->checkDate($errors, $budget);
        $this->checkBalances($errors, $budget);
    }

    private function checkDate(MessageBag $errors, Budget $budget): void
    {
        if (Carbon::parse($this->input('date'))->year !== (int) $budget->year) {
            $errors->add('date', "The date must fall within {$budget->year}, the year of {$budget->display_name}.");
        }
    }

    /**
     * Take the edited movement's old effect out, apply the new one, and make sure
     * neither the bank nor cash on hand ends up below zero.
     */
    private function checkBalances(MessageBag $errors, Budget $budget): void
    {
        $isReceipt = $this->input('type') === 'receipt';

        $new = new FundMovement([
            'type'    => $this->input('type'),
            'account' => $isReceipt ? $this->input('account') : null,
            'amount'  => round((float) $this->input('amount'), 2),
        ]);

        $before = FundBalances::without($budget, $this->movement());
        $after  = FundBalances::plus($before, $new);
        $action = $this->movement() ? 'This change' : 'This movement';

        foreach (FundBalances::problems($before, $after, $new, $action) as $message) {
            $errors->add('amount', $message);
        }
    }
}
