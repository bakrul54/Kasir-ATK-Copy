<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductWholesaleGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProductPrices
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    public const FIELDS = [
        'sale_price',
        'cost_price',
        'wholesale_price',
        'wholesale_min_qty',
        'promo_price',
    ];

    public function handle(Product $product, array $prices, User $user, ?int $wholesaleGroupId = null): void
    {
        DB::transaction(function () use ($product, $prices, $user, $wholesaleGroupId): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $previousValues = [];
            $newValues = [];

            foreach ($prices as $field => $value) {
                $oldValue = $lockedProduct->getAttribute($field);
                $oldValue = $oldValue === null ? null : (int) $oldValue;
                $newValue = $value === null || $value === '' ? null : (int) $value;

                if ($oldValue !== $newValue) {
                    $previousValues[$field] = $oldValue;
                    $newValues[$field] = $newValue;
                }
            }

            $previousGroupId = $lockedProduct->wholesale_group_id;
            if ($wholesaleGroupId !== null) {
                $group = ProductWholesaleGroup::query()->lockForUpdate()->findOrFail($wholesaleGroupId);

                if (Product::query()
                    ->where('wholesale_group_id', $group->id)
                    ->whereKeyNot($lockedProduct->id)
                    ->where('unit', '!=', $lockedProduct->unit)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'wholesale_group_id' => 'Produk dalam satu grup grosir harus menggunakan satuan yang sama.',
                    ]);
                }
            }

            $groupChanged = $previousGroupId !== $wholesaleGroupId;
            if ($newValues === [] && ! $groupChanged) {
                return;
            }

            $lockedProduct->update([
                ...$newValues,
                'wholesale_group_id' => $wholesaleGroupId,
            ]);

            $history = null;
            if ($newValues !== []) {
                $history = ProductPriceHistory::create([
                    'product_id' => $lockedProduct->id,
                    'changed_by' => $user->id,
                    'old_prices' => $previousValues,
                    'new_prices' => $newValues,
                ]);
            }

            $this->recordActivity->handle(
                $user,
                $groupChanged && $newValues === [] ? 'prices.wholesale_group_updated' : 'prices.updated',
                $groupChanged && $newValues === []
                    ? "Memperbarui grup grosir {$lockedProduct->name}."
                    : "Memperbarui harga {$lockedProduct->name}.",
                $history ?? $lockedProduct,
                [
                    'fields' => array_keys($newValues),
                    'wholesale_group_id' => $wholesaleGroupId,
                ],
            );
        });
    }
}
