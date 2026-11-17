<?php

namespace App\Actions;

use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductWholesaleGroup;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordSale
{
    private const MAX_AMOUNT = PHP_INT_MAX;

    public function __construct(
        private readonly AdjustProductStock $adjustProductStock,
        private readonly RecordActivity $recordActivity,
    ) {}

    public function handle(array $data, User $user): Sale
    {
        $existingSale = Sale::query()
            ->where('submission_token', $data['submission_token'])
            ->first();

        if ($existingSale) {
            return $existingSale;
        }

        try {
            return DB::transaction(function () use ($data, $user): Sale {
                $settings = StoreSetting::current();
                if (! in_array($data['payment_method'], $settings->payment_methods, true)) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'Metode pembayaran tersebut sedang tidak diaktifkan.',
                    ]);
                }

                $customer = null;
                if (! empty($data['customer_id'])) {
                    $customer = Customer::query()->lockForUpdate()->find($data['customer_id']);

                    if (! $customer) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'Pelanggan yang dipilih sudah tidak tersedia.',
                        ]);
                    }
                }

                $shift = null;
                if ($user->role === 'cashier') {
                    $shift = CashierShift::query()
                        ->whereBelongsTo($user)
                        ->whereNull('closed_at')
                        ->lockForUpdate()
                        ->first();

                    if (! $shift) {
                        throw ValidationException::withMessages([
                            'shift' => 'Buka shift kasir terlebih dahulu sebelum mencatat transaksi.',
                        ]);
                    }
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

                $groupIds = $products->pluck('wholesale_group_id')->filter()->unique();
                $wholesaleGroups = ProductWholesaleGroup::query()
                    ->whereIn('id', $groupIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $groupQuantities = $items->reduce(function (array $quantities, array $item) use ($products): array {
                    $groupId = $products->get($item['product_id'])->wholesale_group_id;
                    if ($groupId !== null) {
                        $quantities[$groupId] = ($quantities[$groupId] ?? 0) + $item['quantity'];
                    }

                    return $quantities;
                }, []);

                $lineItems = [];
                $subtotal = 0;

                foreach ($items as $index => $item) {
                    $product = $products->get($item['product_id']);

                    if (! $product->is_active) {
                        throw ValidationException::withMessages([
                            "items.{$index}.product_id" => "Produk {$product->name} sudah tidak aktif.",
                        ]);
                    }

                    if ($product->tracks_stock && $item['quantity'] > $product->stock_qty) {
                        throw ValidationException::withMessages([
                            "items.{$index}.quantity" => "Stok {$product->name} tidak mencukupi. Tersedia {$product->stock_qty} {$product->unit}.",
                        ]);
                    }

                    $unitPrice = $this->unitPrice(
                        $product,
                        $item['quantity'],
                        $settings,
                        $product->wholesale_group_id === null
                            ? null
                            : ($groupQuantities[$product->wholesale_group_id] ?? 0),
                        $wholesaleGroups->get($product->wholesale_group_id)?->minimum_quantity,
                    );

                    if ($item['quantity'] > intdiv(self::MAX_AMOUNT, max(1, $unitPrice))) {
                        throw ValidationException::withMessages([
                            "items.{$index}.quantity" => "Subtotal {$product->name} melebihi kapasitas transaksi.",
                        ]);
                    }

                    $lineSubtotal = $unitPrice * $item['quantity'];
                    if ($lineSubtotal > self::MAX_AMOUNT - $subtotal) {
                        throw ValidationException::withMessages([
                            'items' => 'Total belanja melebihi kapasitas transaksi.',
                        ]);
                    }

                    $subtotal += $lineSubtotal;
                    $lineItems[] = [
                        'product' => $product,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'cost_price' => $product->cost_price,
                        'subtotal' => $lineSubtotal,
                    ];
                }

                $discountValue = (int) $data['discount_value'];
                $discountAmount = $data['discount_type'] === 'persen'
                    ? intdiv($subtotal, 100) * $discountValue + intdiv(($subtotal % 100) * $discountValue, 100)
                    : $discountValue;

                if ($discountAmount > $subtotal) {
                    throw ValidationException::withMessages([
                        'discount_value' => 'Diskon tidak boleh melebihi subtotal belanja.',
                    ]);
                }

                $total = $subtotal - $discountAmount;
                $tipAmount = (int) ($data['tip_amount'] ?? 0);

                if ($data['payment_method'] !== 'cash' && $tipAmount > 0) {
                    throw ValidationException::withMessages([
                        'tip_amount' => 'Tip hanya dapat ditambahkan pada pembayaran tunai.',
                    ]);
                }

                if ($tipAmount > self::MAX_AMOUNT - $total) {
                    throw ValidationException::withMessages([
                        'tip_amount' => 'Jumlah tip melebihi kapasitas transaksi.',
                    ]);
                }

                $amountReceived = $data['payment_method'] === 'cash'
                    ? (int) $data['amount_received']
                    : $total;

                if ($amountReceived < $total) {
                    throw ValidationException::withMessages([
                        'amount_received' => 'Uang diterima kurang dari total transaksi.',
                    ]);
                }

                if ($amountReceived < $total + $tipAmount) {
                    throw ValidationException::withMessages([
                        'tip_amount' => 'Tip tidak boleh melebihi uang lebih yang diterima.',
                    ]);
                }

                $sale = Sale::create([
                    'transaction_number' => 'TRX-TMP-'.Str::uuid(),
                    'submission_token' => $data['submission_token'],
                    'user_id' => $user->id,
                    'customer_id' => $customer?->id,
                    'cashier_shift_id' => $shift?->id,
                    'customer_name' => $customer?->name ?? ($data['customer_name'] ?? null),
                    'subtotal' => $subtotal,
                    'discount_type' => $data['discount_type'],
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'total' => $total,
                    'tip_amount' => $tipAmount,
                    'payment_method' => $data['payment_method'],
                    'amount_received' => $amountReceived,
                    'change_amount' => $amountReceived - $total - $tipAmount,
                ]);

                $sale->update([
                    'transaction_number' => sprintf('TRX-%s-%06d', $sale->created_at->format('Ymd'), $sale->id),
                ]);

                foreach ($lineItems as $lineItem) {
                    /** @var Product $product */
                    $product = $lineItem['product'];

                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'unit' => $product->unit,
                        'quantity' => $lineItem['quantity'],
                        'unit_price' => $lineItem['unit_price'],
                        'cost_price' => $lineItem['cost_price'],
                        'subtotal' => $lineItem['subtotal'],
                    ]);

                    if ($product->tracks_stock) {
                        $this->adjustProductStock->handle(
                            $product,
                            'keluar',
                            $lineItem['quantity'],
                            "Penjualan {$sale->transaction_number}",
                            $user,
                            sale: $sale,
                        );
                    }
                }

                $this->recordActivity->handle(
                    $user,
                    'sale.created',
                    "Mencatat transaksi {$sale->transaction_number}.",
                    $sale,
                    [
                        'total' => $sale->total,
                        'tip_amount' => $sale->tip_amount,
                        'payment_method' => $sale->payment_method,
                    ],
                );

                return $sale;
            });
        } catch (QueryException $exception) {
            $existingSale = Sale::query()
                ->where('submission_token', $data['submission_token'])
                ->first();

            if ($existingSale) {
                return $existingSale;
            }

            throw $exception;
        }
    }

    private function unitPrice(
        Product $product,
        int $quantity,
        StoreSetting $settings,
        ?int $groupQuantity = null,
        ?int $groupMinimumQuantity = null,
    ): int {
        $prices = [$product->sale_price];

        if ($settings->promo_enabled && $product->promo_price !== null) {
            $prices[] = $product->promo_price;
        }

        $minimumQuantity = $groupMinimumQuantity ?? $product->wholesale_min_qty;
        $eligibleQuantity = $groupMinimumQuantity === null ? $quantity : $groupQuantity;

        if ($settings->wholesale_enabled
            && $product->wholesale_price !== null
            && $minimumQuantity !== null
            && $eligibleQuantity !== null
            && $eligibleQuantity >= $minimumQuantity) {
            $prices[] = $product->wholesale_price;
        }

        return min($prices);
    }
}
