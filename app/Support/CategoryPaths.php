<?php

namespace App\Support;

use App\Models\Budget;
use App\Models\Category;

/**
 * Readable paths for a budget's categories, e.g. "SK YDEP › VIII. Sports Development".
 * Used by dropdowns and tables that need to show where a category sits in the tree.
 */
class CategoryPaths
{
    /**
     * @return array<int, string> category id => path, in tree order (a parent comes before its children)
     */
    public static function forBudget(Budget $budget): array
    {
        $byParent = $budget->categories()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Category $category) => $category->parent_id ?? 0);

        $paths = [];

        $walk = function (int $parentId, string $prefix) use (&$walk, &$paths, $byParent) {
            foreach ($byParent->get($parentId, collect()) as $category) {
                $label = $prefix . $category->name;

                $paths[$category->id] = $label;
                $walk($category->id, $label . ' › ');
            }
        };

        $walk(0, '');

        return $paths;
    }
}
