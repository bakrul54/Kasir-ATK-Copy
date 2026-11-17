<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustProductStock
{
    private const MAX_STOCK = 4294967295;

    public function __construct(private readonly RecordActivity $recordActivity) {}

    public function handle(
        Product $product,
        string $type,
        int $quantity,
        ?string $notes,
        User $user,
        ?Purchase $purchase = null,
        ?Sale $sale = null,
    ): void {
        DB::transaction(function () use ($product, $type, $quantity, $notes, $user, $purchase, $sale): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);

            if (! $lockedProduct->tracks_stock) {
                throw ValidationException::withMessages([
                    'product_id' => 'Stok hanya dapat diubah untuk produk yang menggunakan pencatatan stok.',
                ]);
            }

            $stockBefore = $lockedProduct->stock_qty;
            $stockAfter = match ($type) {
                'masuk' => $stockBefore + $quantity,
                'keluar' => $stockBefore - $quantity,
                'opname' => $quantity,
            };

            if ($stockAfter < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah stok keluar melebihi stok yang tersedia.',
                ]);
            }

            if ($stockAfter > self::MAX_STOCK) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah stok melebihi kapasitas yang dapat disimpan.',
                ]);
            }

            if ($stockAfter === $stockBefore) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah stok sama dengan stok saat ini, tidak ada perubahan untuk dicatat.',
                ]);
            }

            $lockedProduct->update(['stock_qty' => $stockAfter]);

            $movement = ProductStockMovement::create([
                'product_id' => $lockedProduct->id,
                'purchase_id' => $purchase?->id,
                'sale_id' => $sale?->id,
                'user_id' => $user->id,
                'type' => $type,
                'quantity' => $quantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'notes' => $notes,
            ]);

            if ($purchase === null && $sale === null) {
                $this->recordActivity->handle(
                    $user,
                    'stock.adjusted',
                    "Mencatat pergerakan stok {$type} untuk {$lockedProduct->name}.",
                    $movement,
                    ['quantity' => $quantity, 'stock_before' => $stockBefore, 'stock_after' => $stockAfter],
                );
            }
        });
    }
}
