<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the FY2026 Sangguniang Kabataan budget exactly as it appears on the signed
 * "Budget of Expenditures and Sources of Financing, FY 2026" and the AIP-style table
 * (GAP + SK YDEP), as a nested category tree.
 *
 *   php artisan db:seed --class=Fy2026BudgetSeeder
 *
 * It rebuilds the categories of the 2026 regular budget, so it refuses to run once any
 * transaction has been recorded against them. Safe to run more than once before that.
 * Bank/cash opening balances are left as they are.
 */
class Fy2026BudgetSeeder extends Seeder
{
    public function run(): void
    {
        $budget = Budget::firstOrCreate(
            ['barangay_id' => Barangay::defaultId(), 'year' => 2026, 'type' => 'regular'],   // Cantoria No. 4's budget
            ['total_budget' => 303749.70, 'opening_bank_balance' => 0, 'opening_cash_balance' => 0]
        );

        $categoryIds = Category::where('budget_id', $budget->id)->pluck('id');

        if (Transaction::whereIn('category_id', $categoryIds)->exists()) {
            $this->command?->error('FY2026 already has transactions, so its categories were left untouched.');

            return;
        }

        DB::transaction(function () use ($budget) {
            // Newest first, so nested categories are removed before the ones that contain them
            Category::where('budget_id', $budget->id)->orderByDesc('id')->get()->each->delete();

            $budget->update(['total_budget' => 303749.70]);

            foreach ($this->structure() as $order => $node) {
                $this->createNode($budget, $node, null, $order);
            }
        });

        $budget->refresh();

        $this->command?->info(sprintf(
            'FY2026 budget built: allocated ₱%s of ₱%s (unallocated ₱%s).',
            number_format($budget->total_allocated, 2),
            number_format((float) $budget->total_budget, 2),
            number_format($budget->unallocated, 2)
        ));
    }

    private function createNode(Budget $budget, array $node, ?int $parentId, int $order): void
    {
        $category = $budget->categories()->create([
            'parent_id'   => $parentId,
            'name'        => $node['name'],
            'code'        => $node['code'] ?? null,
            'description' => $node['description'] ?? null,
            'sort_order'  => $order,
        ]);

        // items are [reference code, name, amount]
        foreach ($node['items'] ?? [] as [$code, $name, $amount]) {
            $category->subcategories()->create([
                'code'             => $code,
                'name'             => $name,
                'allocated_amount' => $amount,
            ]);
        }

        foreach ($node['children'] ?? [] as $childOrder => $child) {
            $this->createNode($budget, $child, $category->id, $childOrder);
        }
    }

    private function structure(): array
    {
        return [
            // ---------------------------------------------------------------- GAP: ₱210,036.50
            [
                'code'     => 'GAP',
                'name'     => 'General Administration Program',
                'children' => [
                    [
                        'code'  => 'PS',
                        'name'  => 'Personal Services',
                        'items' => [
                            ['5-01-02-100', 'SK Honoraria', 75924.00],
                        ],
                    ],
                    [
                        'code'  => 'MOOE',
                        'name'  => 'Maintenance and Other Operating Expenses',
                        'items' => [
                            ['5-02-01-010', 'Traveling Expenses', 20000.00],
                            ['5-02-02-010', 'Training Expenses', 20000.00],
                            ['5-02-03-010', 'Office Supplies Expenses', 10000.00],
                            ['5-02-05-030', 'Internet Subscription Expenses', 20000.00],
                            ['5-02-11-010', 'Fidelity Bond Premiums', 5000.00],
                            ['5-02-99-050', 'Membership Dues and Contributions', 9112.50],
                        ],
                    ],
                    [
                        'code'  => 'CO',
                        'name'  => 'Capital Outlay',
                        'items' => [
                            [null, 'Office Equipments (Laptop and Printer)', 50000.00],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------------- SK YDEP: ₱93,713.20
            [
                'code'     => 'SK YDEP',
                'name'     => 'SK Youth Development Programs and Empowered Programs, Plans, and Activities',
                'children' => [
                    ['code' => 'I',    'name' => 'Equitable Access to Quality Education', 'items' => [[null, 'School Supplies', 5000.00]]],
                    ['code' => 'II',   'name' => 'Environmental Protection',              'items' => [[null, 'Clean and Green Program', 1000.00]]],
                    ['code' => 'III',  'name' => 'Climate Change Protection',             'items' => [[null, 'Supplies and Materials', 5000.00]]],
                    ['code' => 'IV',   'name' => 'Disaster Risk Reduction and Resiliency', 'items' => [[null, 'Supplies and Materials', 2000.00]]],
                    ['code' => 'V',    'name' => 'Youth Employment and Livelihood',       'items' => [[null, 'Supplies and Materials', 3000.00]]],
                    ['code' => 'VI',   'name' => 'Health and Anti-Drug Abuse',            'items' => [[null, 'Supplies and Materials', 5000.00]]],
                    ['code' => 'VII',  'name' => 'Gender Sensitivity',                    'items' => [[null, 'Supplies and Materials', 5000.00]]],
                    [
                        'code'  => 'VIII',
                        'name'  => 'Sports Development',
                        'items' => [
                            [null, 'Supplies and Materials', 30000.00],
                            [null, 'Food Supplies', 20000.00],
                            [null, 'Officiating Fees', 10000.00],
                        ],
                    ],
                    ['code' => 'IX',   'name' => 'Capacity Building',                     'items' => [[null, 'Lingo ng Kabataan Celebration', 7713.20]]],
                ],
            ],
        ];
    }
}
