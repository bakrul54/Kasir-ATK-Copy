<?php

namespace App\Support;

use App\Models\Product;
use App\Models\StoreSetting;

class PrintPricing
{
    public const CATEGORIES = [
        'hitam-putih' => [
            'name' => 'Hitam Putih',
            'product_name' => 'Print Hitam Putih',
        ],
        'hitam-50' => [
            'name' => 'Hitam 50%',
            'product_name' => 'Print Hitam 50%',
        ],
        'full-hitam' => [
            'name' => 'Full Hitam',
            'product_name' => 'Print Full Hitam',
        ],
        'warna-10' => [
            'name' => 'Warna 10%',
            'product_name' => 'Print Warna <10%',
        ],
        'warna-50' => [
            'name' => 'Warna 50%',
            'product_name' => 'Print Warna <50%',
        ],
        'full-warna' => [
            'name' => 'Full Warna',
            'product_name' => 'Print Full Warna',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function productData(Product $product, StoreSetting $settings): array
    {
        return [
            'id' => (string) $product->id,
            'name' => $product->name,
            'unit' => $product->unit,
            'salePrice' => $product->sale_price,
            'promoPrice' => $settings->promo_enabled ? $product->promo_price : null,
            'wholesalePrice' => $settings->wholesale_enabled ? $product->wholesale_price : null,
            'wholesaleMinQty' => $settings->wholesale_enabled ? $product->wholesale_min_qty : null,
            'wholesaleGroupId' => $settings->wholesale_enabled && $product->wholesale_group_id !== null
                ? (string) $product->wholesale_group_id
                : null,
            'wholesaleGroupName' => $settings->wholesale_enabled ? $product->wholesaleGroup?->name : null,
            'wholesaleGroupMinQty' => $settings->wholesale_enabled
                ? $product->wholesaleGroup?->minimum_quantity
                : null,
            'stock' => $product->tracks_stock ? $product->stock_qty : null,
            'tracksStock' => $product->tracks_stock,
        ];
    }
}
