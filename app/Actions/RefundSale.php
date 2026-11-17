<?php

namespace App\Actions;

use App\Models\CashEntry;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundSale
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    public function handle(Sale $sale, User $user): CashEntry
    {
        return DB::transaction(function () use ($sale, $user): CashEntry {
            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($lockedSale->canceled_at === null) {
                throw ValidationException::withMessages([
                    'sale' => 'Batalkan transaksi sebelum mencatat refund.',
                ]);
            }

            if ($lockedSale->refundEntry()->exists()) {
                throw ValidationException::withMessages([
                    'sale' => 'Refund untuk transaksi ini sudah tercatat.',
                ]);
            }

            $refund = CashEntry::create([
                'user_id' => $user->id,
                'sale_id' => $lockedSale->id,
                'type' => 'keluar',
                'category' => 'Refund penjualan',
                'description' => "Refund transaksi {$lockedSale->transaction_number}",
                'amount' => $lockedSale->total + $lockedSale->tip_amount,
                'transaction_date' => today(),
                'account' => $lockedSale->payment_method === 'cash'
                    ? CashEntry::ACCOUNT_DRAWER
                    : CashEntry::ACCOUNT_NON_CASH,
            ]);

            $this->recordActivity->handle(
                $user,
                'sale.refunded',
                "Mencatat refund transaksi {$lockedSale->transaction_number}.",
                $lockedSale,
                ['amount' => $refund->amount],
            );

            return $refund;
        });
    }
}
