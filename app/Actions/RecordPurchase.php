<?php

namespace App\Actions;

use App\Models\CashEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordPurchase
{
    public function __construct(
        private readonly AdjustProductStock $adjustProductStock,
        private readonly UpdateProductPrices $updateProductPrices,
        private readonly RecordActivity $recordActivity,
    ) {}

    public function handle(array $data, User $user): Purchase
    {
        return DB::transaction(function () use ($data, $user): Purchase {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($data['supplier_id']);

            if (! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Supplier ini sudah nonaktif. Pilih supplier yang masih aktif.',
                ]);
            }

            if (! empty($data['invoice_number']) && $supplier->purchases()
                ->where('invoice_number', $data['invoice_number'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'invoice_number' => 'Nomor nota tersebut sudah pernah dicatat untuk supplier ini.',
                ]);
            }

            $items = collect($data['items']);
            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $items->pluck('product_id')->unique()->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Satu atau beberapa produk tidak lagi tersedia.',
                ]);
            }

            $total = 0;
            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id']);

                if (! $product->tracks_stock) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => 'Pembelian hanya dapat dicatat untuk produk yang menggunakan stok.',
                    ]);
                }

                if ($item['quantity'] > intdiv(PHP_INT_MAX, max(1, $item['unit_cost']))) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Subtotal baris melebihi kapasitas yang dapat disimpan.',
                    ]);
                }

                $subtotal = $item['quantity'] * $item['unit_cost'];

                if ($subtotal > PHP_INT_MAX - $total) {
                    throw ValidationException::withMessages([
                        'items' => 'Total pembelian melebihi kapasitas yang dapat disimpan.',
                    ]);
                }

                if ($item['quantity'] > 4294967295 - $product->stock_qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Jumlah pembelian {$product->name} melebihi kapasitas stok.",
                    ]);
                }

                $total += $subtotal;
            }

            $purchase = Purchase::create([
                'purchase_number' => 'PB-TMP-'.Str::uuid(),
                'supplier_id' => $supplier->id,
                'user_id' => $user->id,
                'invoice_number' => $data['invoice_number'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'payment_status' => $data['payment_status'],
                'payment_account' => $data['payment_account'] ?? CashEntry::ACCOUNT_DRAWER,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'receipt_path' => $data['receipt_path'] ?? null,
            ]);

            $purchase->update([
                'purchase_number' => sprintf('PB-%s-%06d', $purchase->purchase_date->format('Ymd'), $purchase->id),
            ]);

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $subtotal = $item['quantity'] * $item['unit_cost'];

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'subtotal' => $subtotal,
                ]);

                $this->adjustProductStock->handle(
                    $product,
                    'masuk',
                    $item['quantity'],
                    "Pembelian {$purchase->purchase_number}",
                    $user,
                    $purchase,
                );

                $this->updateProductPrices->handle($product, [
                    'cost_price' => $item['unit_cost'],
                ], $user);
            }

            $this->recordActivity->handle(
                $user,
                'purchase.created',
                "Mencatat pembelian {$purchase->purchase_number}.",
                $purchase,
                ['total' => $purchase->total, 'payment_status' => $purchase->payment_status],
            );

            return $purchase;
        });
    }
}
