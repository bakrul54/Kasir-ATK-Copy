<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelSale
{
    private const MAX_STOCK = 4294967295;

    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly RefundSale $refundSale,
    ) {}

    public function handle(Sale $sale, User $user, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $user, $reason): Sale {
            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($lockedSale->canceled_at !== null) {
                throw ValidationException::withMessages([
                    'sale' => 'Transaksi ini sudah dibatalkan.',
                ]);
            }

            $outboundMovements = $lockedSale->stockMovements()
                ->where('type', 'keluar')
                ->orderBy('product_id')
                ->get();

            foreach ($outboundMovements as $movement) {
                $product = Product::query()->lockForUpdate()->findOrFail($movement->product_id);
                $stockBefore = $product->stock_qty;

                if ($movement->quantity > self::MAX_STOCK - $stockBefore) {
                    throw ValidationException::withMessages([
                        'sale' => "Stok {$product->name} tidak dapat dikembalikan karena melebihi kapasitas.",
                    ]);
                }

                $stockAfter = $stockBefore + $movement->quantity;
                $product->update(['stock_qty' => $stockAfter]);

                ProductStockMovement::create([
                    'product_id' => $product->id,
                    'sale_id' => $lockedSale->id,
                    'user_id' => $user->id,
                    'type' => 'masuk',
                    'quantity' => $movement->quantity,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'notes' => "Pembatalan transaksi {$lockedSale->transaction_number}",
                ]);
            }

            $lockedSale->update([
                'canceled_by' => $user->id,
                'canceled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
            $this->refundSale->handle($lockedSale, $user);

            $this->recordActivity->handle(
                $user,
                'sale.cancelled',
                "Membatalkan transaksi {$lockedSale->transaction_number}.",
                $lockedSale,
                ['reason' => $reason, 'total' => $lockedSale->total],
            );

            return $lockedSale;
        });
    }
}
