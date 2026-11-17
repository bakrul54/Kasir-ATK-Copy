<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'sale_id',
    'type',
    'category',
    'description',
    'amount',
    'transaction_date',
    'account',
    'to_account',
])]
class CashEntry extends Model
{
    public const ACCOUNT_DRAWER = 'laci';

    public const ACCOUNT_WALLET = 'dompet';

    public const ACCOUNT_NON_CASH = 'non_tunai';

    /**
     * @return array<string, string>
     */
    public static function accountLabels(): array
    {
        return [
            self::ACCOUNT_DRAWER => 'Laci toko',
            self::ACCOUNT_WALLET => 'Dompet toko',
            self::ACCOUNT_NON_CASH => 'QRIS / Transfer',
        ];
    }

    protected $attributes = [
        'account' => self::ACCOUNT_DRAWER,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'transaction_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
