<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Models\ActivityLog;
use App\Models\Budget;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Support\CategoryPaths;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransactionController extends Controller
{
    private const PER_PAGE = 15;

    /** Columns the list can be sorted by (the keys the table headers send). */
    private const SORTS = ['date', 'description', 'item', 'method', 'amount'];

    /* ------------------------------------------------------------
     | List (current budget) with filters and totals
     * ------------------------------------------------------------ */

    public function index(Request $request): View
    {
        $budget = Budget::current();

        if (! $budget) {
            return view('transactions.index', ['budget' => null]);
        }

        $filters = $this->filters($request);
        $sort    = $filters['sort'] ?? 'date';
        $dir     = $filters['dir'] ?? 'desc';
        $query   = $this->filteredQuery($budget, $filters);
        $paths   = CategoryPaths::forBudget($budget);

        // "Show voided" lists the voided expenses too (struck through); the totals and the printed report never include them
        $listQuery    = $request->boolean('voided') ? (clone $query)->withoutGlobalScope('notVoided') : clone $query;
        $transactions = $this->sorted($listQuery->with(['category', 'subcategory', 'attachments']), $sort, $dir)
            ->paginate($request->boolean('print') ? 100000 : self::PER_PAGE) // ?print=1 prints every row, not just one page
            ->withQueryString();

        return view('transactions.index', [
            'budget'       => $budget,
            'filters'      => $filters,
            'sort'         => $sort,
            'dir'          => $dir,
            'transactions' => $transactions,
            'totals'       => $this->totals($query),
            // The printed report always lists every row that matches the filters, not just the page on screen
            'printRows'    => Gate::allows('print')
                ? $this->sorted((clone $query)->with(['category', 'subcategory', 'attachments']), $sort, $dir)->get()
                : collect(),
            'paths'        => $paths,
            'catalog'      => $this->catalog($budget, $paths),
        ]);
    }

    /* ------------------------------------------------------------
     | Record an expense
     * ------------------------------------------------------------ */

    public function create(): View|RedirectResponse
    {
        $budget = Budget::current();

        if (! $budget) {
            return redirect()->route('budget.index')
                ->withErrors(['budget' => 'Create a budget first; expenses are recorded against its items.']);
        }

        return view('transactions.create', $this->formData($budget) + ['transaction' => null]);
    }

    public function store(TransactionRequest $request): RedirectResponse
    {
        $budget      = $request->item()->category->budget;
        $transaction = Transaction::create($this->payload($request));

        $this->syncAttachments($request, $transaction, $budget);

        session(['budget_id' => $budget->id]);

        return redirect()->route('transactions.index')
            ->with('success', 'Expense of ₱' . number_format((float) $transaction->amount, 2) . " recorded under {$request->item()->name}.");
    }

    /* ------------------------------------------------------------
     | Edit / delete
     * ------------------------------------------------------------ */

    public function edit(Transaction $transaction): View
    {
        $budget = $transaction->category->budget;

        // Show the budget this transaction belongs to in the top-bar switcher
        session(['budget_id' => $budget->id]);

        return view('transactions.edit', $this->formData($budget, $transaction) + ['transaction' => $transaction]);
    }

    public function update(TransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $budget = $request->item()->category->budget;

        $transaction->update($this->payload($request));

        $this->syncAttachments($request, $transaction, $budget);

        session(['budget_id' => $budget->id]);

        return redirect()->route('transactions.index')->with('success', 'Transaction updated.');
    }

    /** Expenses are never deleted: a voided one stays in the books (receipts kept) and leaves every total. */
    public function void(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(
            ['void_reason' => ['required', 'string', 'min:10', 'max:255']],
            ['void_reason.required' => 'Write why this expense is voided.', 'void_reason.min' => 'Write a reason of at least 10 letters, so the books explain why.'],
        );

        $transaction->voidWith(trim($data['void_reason']), (int) auth()->id());

        return back()->with('success', 'Expense voided. It stays in the books, marked as voided, and no longer counts in any total.');
    }

    /* ------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------ */

    /** Clean data for create/update. Sets category_id from the item. (Receipts are handled by syncAttachments.) */
    private function payload(TransactionRequest $request): array
    {
        $data = $request->safe()->only([
            'date', 'description', 'payee', 'subcategory_id', 'amount',
            'payment_method', 'voucher_number', 'check_number',
        ]);

        $data['category_id']  = $request->item()->category_id;
        $data['amount']       = round((float) $data['amount'], 2);
        $data['check_number'] = $data['payment_method'] === 'bank' ? ($data['check_number'] ?? null) : null;

        return $data;
    }

    /**
     * Delete the receipts ticked "Remove", then store the newly chosen files. The limits (number of files,
     * size of each, total size) were already checked by TransactionRequest.
     */
    private function syncAttachments(TransactionRequest $request, Transaction $transaction, Budget $budget): void
    {
        $removed = 0;
        $added   = 0;

        $ids = array_map('intval', (array) $request->input('remove_attachments', []));

        if ($ids !== []) {
            // only this transaction's own receipts can be removed here
            foreach ($transaction->attachments()->whereIn('id', $ids)->get() as $attachment) {
                $attachment->delete();   // the model deletes the file too
                $removed++;
            }
        }

        foreach (array_filter((array) $request->file('receipts', [])) as $file) {
            $transaction->attachments()->create([
                'path'          => $file->store("receipts/{$budget->year}", 'public'),
                'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'size'          => (int) $file->getSize(),
            ]);
            $added++;
        }

        if ($added > 0 || $removed > 0) {
            $parts = array_filter([
                $added > 0 ? "added {$added} " . Str::plural('receipt', $added) : null,
                $removed > 0 ? "removed {$removed} " . Str::plural('receipt', $removed) : null,
            ]);

            ActivityLog::record('updated', $transaction, $transaction->activityLabel() . ': ' . implode(', ', $parts));
        }
    }

    /** Everything the create/edit form needs. */
    private function formData(Budget $budget, ?Transaction $transaction = null): array
    {
        return [
            'budget'      => $budget,
            'catalog'     => $this->catalog($budget, CategoryPaths::forBudget($budget)),
            'accounts'    => ['bank' => $budget->bank_balance, 'cash_on_hand' => $budget->cash_balance],
            // What the transaction being edited already uses, so the form can add it back to the balances
            'origin'      => $transaction ? [
                'item'   => $transaction->subcategory_id,
                'method' => $transaction->payment_method,
                'amount' => (float) $transaction->amount,
            ] : null,
            'defaultDate' => now()->year === (int) $budget->year ? now()->toDateString() : "{$budget->year}-01-01",
        ];
    }

    /**
     * Categories that have items, each with its items and their remaining balance:
     * [categoryId => ['path' => '...', 'items' => [['id', 'label', 'allocated', 'balance'], ...]]]
     */
    private function catalog(Budget $budget, array $paths): array
    {
        $items = Subcategory::whereIn('category_id', array_keys($paths))
            ->withSum('transactions', 'amount')
            ->orderBy('id')
            ->get()
            ->groupBy('category_id');

        $catalog = [];

        foreach ($paths as $categoryId => $path) {
            $group = $items->get($categoryId);

            if (! $group) {
                continue;
            }

            $catalog[$categoryId] = [
                'path'  => $path,
                'items' => $group->map(fn (Subcategory $item) => [
                    'id'        => $item->id,
                    'label'     => $item->display_name,
                    'allocated' => (float) $item->allocated_amount,
                    'balance'   => $item->balance,
                ])->values()->all(),
            ];
        }

        return $catalog;
    }

    /* ------------------------------------------------------------
     | Filters and totals for the list page
     * ------------------------------------------------------------ */

    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from'      => ['nullable', 'date'],
            'date_to'        => array_merge(['nullable', 'date'], $request->filled('date_from') ? ['after_or_equal:date_from'] : []),
            'category_id'    => ['nullable', 'integer'],
            'subcategory_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(array_keys(Transaction::PAYMENT_METHODS))],
            'search'         => ['nullable', 'string', 'max:100'],
            'sort'           => ['nullable', Rule::in(self::SORTS)],
            'dir'            => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    /** Sort the list by one of the columns the table headers offer; the id keeps equal rows in a stable order. */
    private function sorted(Builder $query, string $sort, string $dir): Builder
    {
        match ($sort) {
            'description' => $query->orderBy('description', $dir),
            'item'        => $query->orderBy(
                Subcategory::query()->select('name')->whereColumn('subcategories.id', 'transactions.subcategory_id'),
                $dir
            ),
            'method'      => $query->orderBy('payment_method', $dir),
            'amount'      => $query->orderBy('amount', $dir),
            default       => $query->orderBy('date', $dir),
        };

        return $query->orderBy('id', $dir);
    }

    private function filteredQuery(Budget $budget, array $filters): Builder
    {
        $query = Transaction::query()->filter([
            'budget_id'      => $budget->id,
            'subcategory_id' => $filters['subcategory_id'] ?? null,
            'payment_method' => $filters['payment_method'] ?? null,
            'date_from'      => $filters['date_from'] ?? null,
            'date_to'        => $filters['date_to'] ?? null,
            'search'         => $filters['search'] ?? null,
        ]);

        // Picking a category includes everything nested inside it
        if (! empty($filters['category_id'])) {
            $category = $budget->categories()->find($filters['category_id']);

            $query->whereIn('category_id', $category ? $category->treeIds() : [0]);
        }

        return $query;
    }

    /** Count and amounts of the filtered rows (all pages, not just the visible one). */
    private function totals(Builder $query): object
    {
        return (clone $query)->toBase()->selectRaw(
            'COUNT(*) AS rows_count, '
            . 'COALESCE(SUM(amount), 0) AS total, '
            . "COALESCE(SUM(CASE WHEN payment_method = 'bank' THEN amount ELSE 0 END), 0) AS bank_total, "
            . "COALESCE(SUM(CASE WHEN payment_method = 'cash_on_hand' THEN amount ELSE 0 END), 0) AS cash_total"
        )->first();
    }
}
