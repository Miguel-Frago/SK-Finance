<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkFundAllocation extends Model
{
    use BelongsToBarangay, LogsActivity;

    protected $fillable = ['year', 'allocation_amount', 'monthly_allocation', 'remarks'];

    protected $casts = [
        'year' => 'integer',
        'allocation_amount' => 'decimal:2',
        'monthly_allocation' => 'decimal:2',
    ];

    public function transfers(): HasMany
    {
        return $this->hasMany(SkFundTransfer::class);
    }

    public function getSkFundTransferredAttribute(): float
    {
        return (float) $this->transfers()->where('source', 'sk_fund')->sum('amount');
    }

    public function getSurplusTransferredAttribute(): float
    {
        return (float) $this->transfers()->where('source', 'sk_surplus')->sum('amount');
    }

    public function getRemainingSkFundAttribute(): float
    {
        return max(0, (float) $this->allocation_amount - $this->sk_fund_transferred);
    }
}
