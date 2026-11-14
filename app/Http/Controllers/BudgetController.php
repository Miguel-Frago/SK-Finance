<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\PrintReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class BudgetController extends Controller
{
    /* ------------------------------------------------------------
     | Budget overview
     * ------------------------------------------------------------ */

    public function index(): View
    {
        $budget = Budget::current();

        $tree          = $budget ? $budget->categoryTree() : collect();
        $parentOptions = $this->parentOptions($tree);

        $printRows = $budget ? PrintReport::budgetRows($tree) : []; // for the printed report

        return view('budget.index', compact('budget', 'tree', 'parentOptions', 'printRows'));
    }

    /** Create the regular budget for a fiscal year. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year'                 => ['required', 'integer', 'min:2000', 'max:2100'],
            'total_budget'         => ['required', 'numeric', 'min:0'],
            'opening_bank_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_cash_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (Budget::where('year', $data['year'])->where('type', 'regular')->exists()) {
            return back()->withInput()->withErrors(['year' => "A regular budget for {$data['year']} already exists."]);
        }

        $budget = Budget::create([
            'year'                 => $data['year'],
            'type'                 => 'regular',
            'total_budget'         => $data['total_budget'],
            'opening_bank_balance' => $data['opening_bank_balance'] ?? 0,
            'opening_cash_balance' => $data['opening_cash_balance'] ?? 0,
        ]);

        session(['budget_id' => $budget->id]);

        return redirect()->route('budget.index')->with('success', "Budget for {$budget->display_name} created.");
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        $data = $request->validate([
            'label'                => ['nullable', 'string', 'max:255'],
            'total_budget'         => ['required', 'numeric', 'min:0'],
            'opening_bank_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_cash_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (round((float) $data['total_budget'], 2) < $budget->total_allocated) {
            return back()->withErrors([
                'total_budget' => 'Total budget cannot be lower than the amount already allocated (₱' . number_format($budget->total_allocated, 2) . ').',
            ]);
        }

        $budget->update([
            'label'                => $data['label'] ?? null,
            'total_budget'         => $data['total_budget'],
            'opening_bank_balance' => $data['opening_bank_balance'] ?? 0,
            'opening_cash_balance' => $data['opening_cash_balance'] ?? 0,
        ]);

        return redirect()->route('budget.index')->with('success', 'Budget updated.');
    }

    /** Switch the budget the whole app is showing. */
    public function switch(Request $request): RedirectResponse
    {
        $request->validate(['budget_id' => ['required', 'exists:budgets,id']]);

        session(['budget_id' => $request->integer('budget_id')]);

        return back();
    }

    /* ------------------------------------------------------------
     | Supplemental budgets (carry-over of unspent funds)
     * ------------------------------------------------------------ */

    public function createSupplemental(Request $request): View|RedirectResponse
    {
        $sources = Budget::latestFirst()->get();

        if ($sources->isEmpty()) {
            return redirect()->route('budget.index')
                ->withErrors(['budget' => 'Create a regular budget first; a supplemental budget is carried over from an existing one.']);
        }

        $source  = $sources->firstWhere('id', $request->integer('source')) ?? Budget::current();
        $preview = $source->carryOverPreview();

        return view('budget.supplemental', compact('sources', 'source', 'preview'));
    }

    public function storeSupplemental(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_id'            => ['required', 'exists:budgets,id'],
            'year'                 => ['required', 'integer', 'min:2000', 'max:2100'],
            'label'                => ['nullable', 'string', 'max:255'],
            'total_budget'         => ['required', 'numeric', 'min:0'],
            'opening_bank_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_cash_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $source = Budget::findOrFail($data['source_id']);

        try {
            $supplemental = $source->createSupplemental((int) $data['year'], $data['label'] ?? null, [
                'total_budget'         => $data['total_budget'],
                'opening_bank_balance' => $data['opening_bank_balance'] ?? 0,
                'opening_cash_balance' => $data['opening_cash_balance'] ?? 0,
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['year' => $e->getMessage()]);
        }

        session(['budget_id' => $supplemental->id]);

        return redirect()->route('budget.index')
            ->with('success', "{$supplemental->display_name} created. It is tracked separately from the regular budget.");
    }

    /* ------------------------------------------------------------
     | Categories (can be nested) - optionally created together with their items
     * ------------------------------------------------------------ */

    public function storeCategory(Request $request, Budget $budget): RedirectResponse
    {
        $data = $request->validate([
            'parent_id'   => ['nullable', 'integer'],
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ] + $this->itemRules());

        if (! empty($data['parent_id']) && ! $budget->categories()->whereKey($data['parent_id'])->exists()) {
            return back()->withInput()->withErrors(['parent_id' => 'That parent category does not belong to this budget.']);
        }

        [$rows, $error] = $this->itemRows($request, $budget);

        if ($error) {
            return back()->withInput()->withErrors(['items' => $error]);
        }

        DB::transaction(function () use ($budget, $data, $rows) {
            $category = $budget->categories()->create([
                'parent_id'   => $data['parent_id'] ?? null,
                'name'        => $data['name'],
                'code'        => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            foreach ($rows as $row) {
                $category->subcategories()->create($row);
            }
        });

        $count = count($rows);
        $note  = $count ? " with {$count} item" . ($count > 1 ? 's' : '') : '';

        return redirect()->route('budget.index')->with('success', "Category \"{$data['name']}\" added{$note}.");
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $category->update($data);

        return redirect()->route('budget.index')->with('success', 'Category updated.');
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        $ids = $category->treeIds();

        if (Transaction::whereIn('category_id', $ids)->exists()) {
            return back()->withErrors(['category' => "\"{$category->name}\" (or something inside it) already has transactions and cannot be deleted."]);
        }

        DB::transaction(function () use ($ids) {
            // Newest first, so nested categories go before the ones that contain them
            Category::whereIn('id', $ids)->orderByDesc('id')->get()->each->delete();
        });

        return redirect()->route('budget.index')->with('success', 'Category deleted.');
    }

    /* ------------------------------------------------------------
     | Items (subcategories): add several at once, edit, delete
     * ------------------------------------------------------------ */

    public function storeSubcategory(Request $request, Category $category): RedirectResponse
    {
        $request->validate($this->itemRules());

        [$rows, $error] = $this->itemRows($request, $category->budget);

        if ($error) {
            return back()->withErrors(['items' => $error]);
        }

        if (empty($rows)) {
            return back()->withErrors(['items' => 'Fill in at least one item (a name and an amount).']);
        }

        DB::transaction(function () use ($category, $rows) {
            foreach ($rows as $row) {
                $category->subcategories()->create($row);
            }
        });

        $count = count($rows);

        return redirect()->route('budget.index')->with('success', $count . ' item' . ($count > 1 ? 's' : '') . ' added.');
    }

    public function updateSubcategory(Request $request, Subcategory $subcategory): RedirectResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'allocated_amount' => ['required', 'numeric', 'min:0'],
            'code'             => ['nullable', 'string', 'max:50'],
        ]);

        $budget = $subcategory->category->budget;

        // This item's current allocation is freed up before checking the pool
        if ($error = $this->allocationError($budget, (float) $data['allocated_amount'], (float) $subcategory->allocated_amount)) {
            return back()->withErrors(['allocated_amount' => $error]);
        }

        $subcategory->update($data);

        return redirect()->route('budget.index')->with('success', 'Item updated.');
    }

    public function destroySubcategory(Subcategory $subcategory): RedirectResponse
    {
        if ($subcategory->transactions()->exists()) {
            return back()->withErrors(['subcategory' => "\"{$subcategory->name}\" already has transactions and cannot be deleted."]);
        }

        $subcategory->delete();

        return redirect()->route('budget.index')->with('success', 'Item deleted.');
    }

    /* ------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------ */

    private function itemRules(): array
    {
        return [
            'items'                     => ['nullable', 'array'],
            'items.*.code'              => ['nullable', 'string', 'max:50'],
            'items.*.name'              => ['nullable', 'string', 'max:255'],
            'items.*.allocated_amount'  => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Turns the submitted item rows into clean arrays (blank rows are skipped) and checks
     * that together they fit in the unallocated pool. Returns [rows, errorMessage|null].
     */
    private function itemRows(Request $request, Budget $budget): array
    {
        $rows = [];

        foreach ((array) $request->input('items', []) as $row) {
            $name   = trim((string) ($row['name'] ?? ''));
            $code   = trim((string) ($row['code'] ?? ''));
            $amount = $row['allocated_amount'] ?? null;

            if ($name === '' && $code === '' && ($amount === null || $amount === '')) {
                continue; // empty row
            }

            if ($name === '') {
                return [[], 'Every item row that has an amount or code also needs a name.'];
            }

            $rows[] = [
                'name'             => $name,
                'code'             => $code !== '' ? $code : null,
                'allocated_amount' => round((float) ($amount ?: 0), 2),
            ];
        }

        if ($error = $this->allocationError($budget, array_sum(array_column($rows, 'allocated_amount')))) {
            return [[], $error];
        }

        return [$rows, null];
    }

    /** Returns an error message when the amount does not fit in the unallocated pool. */
    private function allocationError(Budget $budget, float $amount, float $replacing = 0.0): ?string
    {
        $available = round($budget->unallocated + $replacing, 2);

        if (round($amount, 2) > $available) {
            return 'Allocation of ₱' . number_format($amount, 2)
                . ' is more than the unallocated pool of ₱' . number_format($available, 2) . '.';
        }

        return null;
    }

    /** id => "SK YDEP › VIII. Sports Development" for the "Place under" dropdown. */
    private function parentOptions(Collection $tree, string $prefix = ''): array
    {
        $options = [];

        foreach ($tree as $category) {
            $label = $prefix . $category->name;

            $options[$category->id] = $label;
            $options += $this->parentOptions($category->children, $label . ' › ');
        }

        return $options;
    }
}