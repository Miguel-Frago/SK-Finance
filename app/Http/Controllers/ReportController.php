<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\ReportSetting;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Support\CategoryPaths;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Report Generation (DRAFT LAYOUT).
 *
 * Builds a printable "statement of expenses" for the current budget: the transactions that match the
 * filters, followed by their evidence (the receipt images or PDFs attached to each expense, one per page).
 * The final report format is not decided yet, so this only prepares the data and a draft page.
 */
class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $budget = Budget::current();

        if (! $budget) {
            return view('reports.index', ['budget' => null]);
        }

        $filters = $request->validate([
            'date_from'      => ['nullable', 'date'],
            'date_to'        => array_merge(['nullable', 'date'], $request->filled('date_from') ? ['after_or_equal:date_from'] : []),
            'category_id'    => ['nullable', 'integer'],
            'subcategory_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(array_keys(Transaction::PAYMENT_METHODS))],
            'evidence'       => ['nullable', Rule::in(['with', 'without'])],  // only expenses that do / do not have a receipt
            'show'           => ['nullable', Rule::in(['one', 'none', '2', '3'])],   // receipts: one per page, or hidden ('2'/'3' = old saved links, treated as 'one')
        ]);

        $transactions = $this->query($budget, $filters)
            ->with(['category', 'subcategory', 'attachments'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $paths = CategoryPaths::forBudget($budget);

        return view('reports.index', [
            'budget'       => $budget,
            'filters'      => $filters,
            'show'         => ($filters['show'] ?? 'one') === 'none' ? 'none' : 'one',
            'transactions' => $transactions,
            'summary'      => $this->summary($transactions),
            'paths'        => $paths,
            'signatories'  => ReportSetting::read('signatories'),   // shared by the treasurer and the SK chairman
            'items'        => Subcategory::whereIn('category_id', array_keys($paths))->orderBy('id')->get()->groupBy('category_id'),
        ]);
    }

    /**
     * Save the names, positions, signatures and report no. shown at the bottom of the report.
     * Open to the treasurer AND the SK chairman, and shared between them (one set for everyone).
     */
    public function saveSignatories(Request $request): JsonResponse
    {
        $sig = ['nullable', 'string', 'max:800000', 'regex:#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#'];

        $rules = ['reportNo' => ['nullable', 'string', 'max:40']];

        foreach (['prepared', 'noted', 'approved'] as $key) {
            $rules[$key]           = ['nullable', 'array'];
            $rules["$key.name"]    = ['nullable', 'string', 'max:80'];
            $rules["$key.pos"]     = ['nullable', 'string', 'max:80'];
            $rules["$key.sig"]     = $sig;
        }

        $data  = $request->validate($rules);
        $clean = [];

        if (array_key_exists('reportNo', $data)) {
            $clean['reportNo'] = $data['reportNo'];
        }

        foreach (['prepared', 'noted', 'approved'] as $key) {
            if (isset($data[$key])) {
                $clean[$key] = array_intersect_key($data[$key], array_flip(['name', 'pos', 'sig']));
            }
        }

        ReportSetting::write('signatories', $clean);

        \App\Models\ActivityLog::record('updated', null, 'Updated the names and signatures on the report');

        return response()->json(['ok' => true]);
    }

    private function query(Budget $budget, array $filters): Builder
    {
        $query = Transaction::query()->filter([
            'budget_id'      => $budget->id,
            'subcategory_id' => $filters['subcategory_id'] ?? null,
            'payment_method' => $filters['payment_method'] ?? null,
            'date_from'      => $filters['date_from'] ?? null,
            'date_to'        => $filters['date_to'] ?? null,
        ]);

        // Picking a category includes everything nested inside it
        if (! empty($filters['category_id'])) {
            $category = $budget->categories()->find($filters['category_id']);

            $query->whereIn('category_id', $category ? $category->treeIds() : [0]);
        }

        return $query
            ->when(($filters['evidence'] ?? null) === 'with', fn ($q) => $q->whereHas('attachments'))
            ->when(($filters['evidence'] ?? null) === 'without', fn ($q) => $q->whereDoesntHave('attachments'));
    }

    private function summary($transactions): array
    {
        $withEvidence = $transactions->filter(fn (Transaction $t) => $t->attachments->isNotEmpty())->count();

        return [
            'count'         => $transactions->count(),
            'total'         => round((float) $transactions->sum('amount'), 2),
            'bank'          => round((float) $transactions->where('payment_method', 'bank')->sum('amount'), 2),
            'cash'          => round((float) $transactions->where('payment_method', 'cash_on_hand')->sum('amount'), 2),
            'with_evidence' => $withEvidence,
            'receipt_pages' => $transactions->sum(fn (Transaction $t) => $t->attachments->count()),   // one printed page per receipt file
            'missing'       => $transactions->count() - $withEvidence,
            'first_date'    => $transactions->first()?->date,
            'last_date'     => $transactions->last()?->date,
        ];
    }
}
