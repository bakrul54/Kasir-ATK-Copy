<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'store_name',
    'address',
    'whatsapp',
    'logo_path',
    'printer_paper_size',
    'receipt_header',
    'receipt_footer',
    'receipt_show_logo',
    'receipt_auto_print',
    'payment_methods',
    'payment_information',
    'wholesale_enabled',
    'promo_enabled',
    'default_minimum_stock',
    'units',
])]
class StoreSetting extends Model
{
    public const PAYMENT_METHODS = ['cash', 'transfer', 'qris'];

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }

    protected function casts(): array
    {
        return [
            'receipt_show_logo' => 'boolean',
            'receipt_auto_print' => 'boolean',
            'payment_methods' => 'array',
            'wholesale_enabled' => 'boolean',
            'promo_enabled' => 'boolean',
            'default_minimum_stock' => 'integer',
            'units' => 'array',
        ];
    }
}
