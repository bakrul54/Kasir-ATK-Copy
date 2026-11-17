<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'category_id',
    'name',
    'sku',
    'unit',
    'tracks_stock',
    'sale_price',
    'cost_price',
    'stock_qty',
    'minimum_stock',
    'wholesale_price',
    'wholesale_min_qty',
    'wholesale_group_id',
    'promo_price',
    'photo_path',
    'is_active',
])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'tracks_stock' => 'boolean',
            'is_active' => 'boolean',
            'sale_price' => 'integer',
            'cost_price' => 'integer',
            'stock_qty' => 'integer',
            'minimum_stock' => 'integer',
            'wholesale_price' => 'integer',
            'wholesale_min_qty' => 'integer',
            'wholesale_group_id' => 'integer',
            'promo_price' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function latestPriceHistory(): HasOne
    {
        return $this->hasOne(ProductPriceHistory::class)->latestOfMany();
    }

    public function wholesaleGroup(): BelongsTo
    {
        return $this->belongsTo(ProductWholesaleGroup::class, 'wholesale_group_id');
    }

    public function availability(): string
    {
        if (! $this->is_active) {
            return 'nonaktif';
        }

        if (! $this->tracks_stock) {
            return 'tersedia';
        }

        if ($this->stock_qty === 0) {
            return 'habis';
        }

        return $this->stock_qty <= $this->minimum_stock ? 'menipis' : 'tersedia';
    }
}
