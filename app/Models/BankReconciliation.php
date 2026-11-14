<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One comparison of the bank statement with the books. Saved once and never changed: the history is the evidence. */
class BankReconciliation extends Model
{
    use BelongsToBarangay, LogsActivity;

    protected $fillable = ['budget_id', 'statement_date', 'statement_balance', 'outstanding_checks', 'deposits_in_transit', 'notes'];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date', 'statement_balance' => 'decimal:2', 'outstanding_checks' => 'decimal:2',
            'deposits_in_transit' => 'decimal:2', 'system_balance' => 'decimal:2', 'difference' => 'decimal:2',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function matches(): bool
    {
        return abs((float) $this->difference) < 0.005;
    }

    /** The statement balance after the two usual adjustments. */
    public function adjustedBalance(): float
    {
        return round((float) $this->statement_balance - (float) $this->outstanding_checks + (float) $this->deposits_in_transit, 2);
    }
}
