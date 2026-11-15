<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkFundTransfer extends Model
{
    use BelongsToBarangay, LogsActivity;

    protected $fillable = [
        'sk_fund_allocation_id', 'transfer_date', 'source', 'amount', 'reference', 'remarks',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(SkFundAllocation::class, 'sk_fund_allocation_id');
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->source === 'sk_surplus' ? 'SK Surplus' : '10% SK Fund';
    }
}
