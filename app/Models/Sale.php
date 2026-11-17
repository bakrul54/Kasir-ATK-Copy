<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'transaction_number',
    'user_id',
    'customer_id',
    'cashier_shift_id',
    'customer_name',
    'subtotal',
    'discount_type',
    'discount_value',
    'discount_amount',
    'total',
    'tip_amount',
    'payment_method',
    'amount_received',
    'change_amount',
    'submission_token',
    'canceled_by',
    'canceled_at',
    'cancellation_reason',
])]
class Sale extends Model
{
    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'tip_amount' => 'integer',
            'amount_received' => 'integer',
            'change_amount' => 'integer',
            'canceled_at' => 'datetime',
        ];
    }

    public function scopeNotCancelled(Builder $query): void
    {
        $query->whereNull('canceled_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
    }

    public function refundEntry(): HasOne
    {
        return $this->hasOne(CashEntry::class);
    }
}
