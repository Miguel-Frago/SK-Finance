<?php

namespace App\Http\Requests;

use App\Models\Budget;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for recording and editing an expense (used by store and update).
 *
 * Besides the field rules it enforces the money rules:
 *   - the item must belong to the chosen category (and stay in the same budget when editing)
 *   - the date must fall inside the budget's year
 *   - the amount cannot exceed the item's remaining balance
 *   - the payment cannot make the bank account or cash on hand negative
 */
class TransactionRequest extends FormRequest
{
    private ?Subcategory $item = null;

    public function authorize(): bool
    {
        return true; // the routes are already behind the auth middleware
    }

    public function rules(): array
    {
        return [
            'date'           => ['required', 'date'],
            'description'    => ['required', 'string', 'max:255'],
            'payee'          => ['nullable', 'string', 'max:255'],
            'category_id'    => ['required', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['required', 'integer', 'exists:subcategories,id'],
            'amount'         => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'payment_method' => ['required', Rule::in(array_keys(Transaction::PAYMENT_METHODS))],
            'voucher_number' => ['nullable', 'string', 'max:100'],
            'check_number'   => ['nullable', 'string', 'max:100'],
            'receipts'             => ['nullable', 'array', 'max:' . TransactionAttachment::limits()['files']],
            'receipts.*'           => ['bail', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:' . TransactionAttachment::limits()['file_kb']],
            'remove_attachments'   => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
        ];
    }

    public function attributes(): array
    {
        return [
            'category_id'    => 'category',
            'subcategory_id' => 'item',
            'payment_method' => 'payment method',
            'voucher_number' => 'voucher number',
            'check_number'   => 'check number',
            'receipts.*'     => 'receipt',
        ];
    }

    public function messages(): array
    {
        $limits = TransactionAttachment::limits();

        return [
            'amount.gt'           => 'The amount must be greater than zero.',
            'amount.decimal'      => 'The amount can have at most 2 decimal places.',
            'receipts.max'        => "You can attach at most {$limits['files']} receipts to one expense.",
            'receipts.*.mimes'    => 'Each receipt must be a JPG, PNG or PDF file.',
            'receipts.*.max'      => "Each receipt can be at most {$limits['file_label']} MB.",
            'receipts.*.file'     => "A receipt could not be uploaded. Each file can be at most {$limits['file_label']} MB, "
                . "and the server may also limit the total size of one upload.",
        ];
    }

    /** The budget item being spent from (loaded together with its category and budget). */
    public function item(): Subcategory
    {
        return $this->item ??= Subcategory::with('category.budget')->findOrFail($this->integer('subcategory_id'));
    }

    /** The transaction being edited, or null when recording a new one. */
    public function transaction(): ?Transaction
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction ? $transaction : null;
    }

    /* ------------------------------------------------------------
     | Money rules (run only after the basic field rules pass)
     * ------------------------------------------------------------ */

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $errors = $validator->errors();

            if ($errors->isEmpty()) {
                $this->checkRules($errors);
            }

            // the receipt totals need valid files, so they are only checked when the files themselves passed
            $badFiles = collect($errors->keys())->contains(
                fn (string $key) => str_starts_with($key, 'receipts') || str_starts_with($key, 'remove_attachments')
            );

            if (! $badFiles) {
                $this->checkAttachments($errors);
            }
        });
    }

    /**
     * Receipts already kept + receipts being added must stay within the number and total size allowed
     * for one expense (config/sk.php). Receipts ticked "Remove" do not count.
     */
    private function checkAttachments(MessageBag $errors): void
    {
        $limits  = TransactionAttachment::limits();
        $removed = array_map('intval', (array) $this->input('remove_attachments', []));
        $kept    = $this->transaction()?->attachments->reject(fn ($file) => in_array($file->id, $removed, true)) ?? collect();
        $added   = collect((array) $this->file('receipts', []))->filter();

        $count = $kept->count() + $added->count();
        $bytes = (int) $kept->sum('size') + (int) $added->sum(fn ($file) => $file->getSize());

        if ($count > $limits['files']) {
            $errors->add('receipts', "An expense can have at most {$limits['files']} receipts; this one would have {$count}. Remove some first.");
        }

        if ($bytes > $limits['total_bytes']) {
            $errors->add('receipts', "The receipts of one expense can add up to at most {$limits['total_label']} MB; these add up to "
                . TransactionAttachment::mb(round($bytes / 1048576, 1)) . ' MB.');
        }
    }

    private function checkRules(MessageBag $errors): void
    {
        $item    = $this->item();
        $budget  = $item->category->budget;
        $current = $this->transaction();

        if ((int) $this->input('category_id') !== (int) $item->category_id) {
            $errors->add('subcategory_id', 'The selected item does not belong to the selected category.');

            return;
        }

        if ($current && (int) $current->category->budget_id !== (int) $budget->id) {
            $errors->add('subcategory_id', 'A transaction cannot be moved to a different budget.');

            return;
        }

        $amount = round((float) $this->input('amount'), 2);

        $this->checkDate($errors, $budget);
        $this->checkItemBalance($errors, $item, $amount, $current);
        $this->checkAccountBalance($errors, $budget, $amount, $current);
    }

    private function checkDate(MessageBag $errors, Budget $budget): void
    {
        if (Carbon::parse($this->input('date'))->year !== (int) $budget->year) {
            $errors->add('date', "The date must fall within {$budget->year}, the year of {$budget->display_name}.");
        }
    }

    /** The transaction being edited does not count against itself. */
    private function checkItemBalance(MessageBag $errors, Subcategory $item, float $amount, ?Transaction $current): void
    {
        $remaining = $item->balance;

        if ($current && (int) $current->subcategory_id === (int) $item->id) {
            $remaining += (float) $current->amount;
        }

        if ($amount > round($remaining, 2)) {
            $errors->add('amount', 'This is more than the remaining balance of ' . $this->peso(max(0, $remaining))
                . " for \"{$item->name}\". Lower the amount or increase the item's allocation first.");
        }
    }

    /** Bank or cash must stay at zero or above. The transaction being edited gives its old amount back first. */
    private function checkAccountBalance(MessageBag $errors, Budget $budget, float $amount, ?Transaction $current): void
    {
        $method    = $this->input('payment_method');
        $available = $method === 'bank' ? $budget->bank_balance : $budget->cash_balance;

        if ($current && $current->payment_method === $method) {
            $available += (float) $current->amount;
        }

        if ($amount > round($available, 2)) {
            $account = $method === 'bank' ? 'the bank account' : 'cash on hand';

            $errors->add('payment_method', "Not enough funds in {$account}: " . $this->peso(max(0, $available))
                . ' available, but this payment is ' . $this->peso($amount) . '.');
        }
    }

    private function peso(float $value): string
    {
        return '₱' . number_format($value, 2);
    }
}
