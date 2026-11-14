{{--
    One category card. Calls itself for every category nested inside it.
    Expects: $category (with children + subcategories loaded by Budget::categoryTree()),
             $budget, $unallocated, $depth
--}}
@php
    $allocated = $category->tree_allocated;
    $spent     = $category->tree_spent;
    $balance   = round($allocated - $spent, 2);
    $usedPct   = $allocated > 0 ? round($spent / $allocated * 100, 1) : 0;
    $usedLevel = \App\Support\Usage::level($usedPct);   // low = yellow, medium = orange, high = red

    // Donut slices: this category's own items first, then each nested category as one slice.
    $segments   = [];
    $subColors  = [];
    $colorIndex = 0;

    foreach ($category->subcategories as $item) {
        $subColors[$item->id] = \App\Support\Donut::color($colorIndex++);
        $segments[] = ['label' => $item->name, 'value' => (float) $item->allocated_amount, 'color' => $subColors[$item->id]];
    }

    foreach ($category->children as $child) {
        $segments[] = ['label' => $child->name, 'value' => $child->tree_allocated, 'color' => \App\Support\Donut::color($colorIndex++)];
    }
@endphp

<section class="card category-card {{ $depth > 0 ? 'category-nested' : '' }}" id="category-{{ $category->id }}">
    <div class="category-head">
        <div>
            <h3 class="card-title">
                @if ($category->code)<span class="code-tag">{{ $category->code }}</span>@endif{{ $category->name }}
            </h3>
            @if ($category->description)
                <p class="muted">{{ $category->description }}</p>
            @endif
        </div>
        <div class="category-totals">
            <span class="t-allocated">Allocated <strong>@peso($allocated)</strong></span>
            <span class="t-spent">Spent <strong>@peso($spent)</strong></span>
            <span class="t-balance">Balance <strong class="{{ $balance < 0 ? 'neg' : '' }}">@peso($balance)</strong></span>
        </div>
    </div>

    @can('manage')
    <div class="btn-row btn-row-small">
        <a href="#add-items-{{ $category->id }}" class="btn btn-small btn-primary">Add items</a>
        <a href="#add-child-{{ $category->id }}" class="btn btn-small btn-secondary">Add sub-category</a>
        <a href="#edit-cat-{{ $category->id }}" class="btn btn-small btn-secondary">Edit category</a>
        <form method="POST" action="{{ route('budget.categories.destroy', $category) }}"
              onsubmit="return confirm('Delete the category &quot;{{ addslashes($category->name) }}&quot; and everything inside it?')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-small btn-danger-outline">Delete category</button>
        </form>
    </div>

    {{-- Edit category --}}
    <div id="edit-cat-{{ $category->id }}" class="panel panel-inner">
        <form method="POST" action="{{ route('budget.categories.update', $category) }}" class="form form-grid form-grid-3">
            @csrf
            @method('PUT')
            <div class="form-group">
                <label>Code (optional)</label>
                <input type="text" name="code" value="{{ $category->code }}" maxlength="50">
            </div>
            <div class="form-group">
                <label>Category name</label>
                <input type="text" name="name" value="{{ $category->name }}" maxlength="255" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" value="{{ $category->description }}" maxlength="1000">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-small">Save</button>
                <a href="#" class="btn btn-ghost btn-small">Cancel</a>
            </div>
        </form>
    </div>

    {{-- Add items to this category --}}
    <div id="add-items-{{ $category->id }}" class="panel panel-inner">
        <form method="POST" action="{{ route('budget.subcategories.store', $category) }}" class="form form-grid">
            @csrf
            @include('budget._item-rows', ['itemRows' => 4])
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-small">Add items</button>
                <a href="#" class="btn btn-ghost btn-small">Cancel</a>
                <small class="hint">Unallocated pool: @peso($unallocated)</small>
            </div>
        </form>
    </div>

    {{-- Add a category inside this one --}}
    <div id="add-child-{{ $category->id }}" class="panel panel-inner">
        <form method="POST" action="{{ route('budget.categories.store', $budget) }}" class="form form-grid form-grid-3">
            @csrf
            <input type="hidden" name="parent_id" value="{{ $category->id }}">
            <div class="form-group">
                <label>Code (optional)</label>
                <input type="text" name="code" maxlength="50" placeholder="e.g. IX">
            </div>
            <div class="form-group">
                <label>Sub-category name</label>
                <input type="text" name="name" maxlength="255" required>
            </div>
            <div class="form-group">
                <label>Description (optional)</label>
                <input type="text" name="description" maxlength="1000">
            </div>
            @include('budget._item-rows', ['itemRows' => 3])
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-small">Add sub-category</button>
                <a href="#" class="btn btn-ghost btn-small">Cancel</a>
            </div>
        </form>
    </div>

    @endcan

    {{-- Items table (with the per-item usage bar) + donut for the whole category --}}
    <div class="category-body {{ $allocated > 0 ? 'has-chart' : '' }}">
        <div class="category-table">
            @if ($category->subcategories->isEmpty())
                <p class="empty">
                    @if ($category->children->isEmpty())
                        No items yet. @can('manage')Use <strong>Add items</strong> to start allocating this category's budget.@endcan
                    @else
                        This category has no items of its own; everything is in the sub-categories below.
                    @endif
                </p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Item</th>
                                <th class="num">Allocated</th>
                                <th class="num">Spent</th>
                                <th class="num">Balance</th>
                                <th>Used</th>
                                @can('manage')<th class="actions-col">Actions</th>@endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($category->subcategories as $sub)
                                @php
                                    $pct   = $sub->utilization_percent;
                                    $state = $pct > 100 ? 'over' : \App\Support\Usage::level($pct);
                                @endphp
                                <tr>
                                    <td>{{ $sub->code ?: '—' }}</td>
                                    <td><i class="dot" style="background: {{ $subColors[$sub->id] }}"></i>{{ $sub->name }}</td>
                                    <td class="num">@peso($sub->allocated_amount)</td>
                                    <td class="num">@peso($sub->spent)</td>
                                    <td class="num {{ $sub->balance < 0 ? 'neg' : '' }}">@peso($sub->balance)</td>
                                    <td>
                                        <div class="progress progress-{{ $state }}" title="{{ $pct }}% used">
                                            <span style="width: {{ min(100, $pct) }}%"></span>
                                        </div>
                                        <small class="pct-{{ $state === 'over' ? 'high' : $state }}">{{ $pct }}%</small>
                                    </td>
                                    @can('manage')
                                    <td class="actions-col">
                                        <a href="#edit-sub-{{ $sub->id }}" class="link-action">Edit</a>
                                        <form method="POST" action="{{ route('budget.subcategories.destroy', $sub) }}" class="inline-form"
                                              onsubmit="return confirm('Delete the item &quot;{{ addslashes($sub->name) }}&quot;?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="link-action link-danger">Delete</button>
                                        </form>
                                    </td>
                                    @endcan
                                </tr>
                                @can('manage')
                                <tr id="edit-sub-{{ $sub->id }}" class="edit-row">
                                    <td colspan="7">
                                        <form method="POST" action="{{ route('budget.subcategories.update', $sub) }}" class="form form-grid form-grid-3">
                                            @csrf
                                            @method('PUT')
                                            <div class="form-group">
                                                <label>Reference code</label>
                                                <input type="text" name="code" value="{{ $sub->code }}" maxlength="50">
                                            </div>
                                            <div class="form-group">
                                                <label>Item name</label>
                                                <input type="text" name="name" value="{{ $sub->name }}" maxlength="255" required>
                                            </div>
                                            <div class="form-group">
                                                <label>Allocated amount (₱)</label>
                                                <input type="number" name="allocated_amount" step="0.01" min="0" value="{{ $sub->allocated_amount }}" required>
                                            </div>
                                            <div class="form-actions">
                                                <button type="submit" class="btn btn-primary btn-small">Save</button>
                                                <a href="#" class="btn btn-ghost btn-small">Cancel</a>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                @endcan
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($allocated > 0)
            <aside class="category-chart">
                @include('budget._donut', [
                    'segments'     => $segments,
                    'centerTop'    => $usedPct . '%',
                    'centerBottom' => 'spent',
                    'centerLevel'  => $usedLevel,
                    'chartLabel'   => $category->name . ': ' . $usedPct . '% of the allocation is spent',
                    'chartSize'    => 'sm',
                ])
            </aside>
        @endif
    </div>

    {{-- Categories nested inside this one --}}
    @foreach ($category->children as $child)
        @include('budget._category', [
            'category'    => $child,
            'budget'      => $budget,
            'unallocated' => $unallocated,
            'depth'       => $depth + 1,
        ])
    @endforeach
</section>
