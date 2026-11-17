<?php

namespace App\Http\Controllers;

use App\Actions\UpdateProductPrices;
use App\Actions\RecordActivity;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductWholesaleGroup;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PriceManagementController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()
            ->with('category', 'latestPriceHistory')
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = $request->string('q')->toString();
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->query('status') === 'promo', fn ($query) => $query->whereNotNull('promo_price'))
            ->when($request->query('status') === 'aktif', fn ($query) => $query->where('is_active', true))
            ->when($request->query('status') === 'nonaktif', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('harga', [
            'products' => $products,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'wholesaleGroups' => ProductWholesaleGroup::query()
                ->withCount('products')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(Request $request, Product $product, UpdateProductPrices $updateProductPrices): RedirectResponse
    {
        $pricePath = 'prices.'.$product->id;
        $validator = Validator::make($request->all(), [
            "{$pricePath}.sale_price" => ['required', 'integer', 'min:0', 'max:999999999999'],
            "{$pricePath}.cost_price" => ['required', 'integer', 'min:0', 'max:999999999999'],
            "{$pricePath}.wholesale_price" => ['nullable', 'integer', 'min:0', "lt:{$pricePath}.sale_price"],
            "{$pricePath}.wholesale_min_qty" => [
                'nullable',
                "required_with:{$pricePath}.wholesale_price",
                'integer',
                'min:1',
                'max:4294967295',
            ],
            "{$pricePath}.wholesale_group_id" => ['nullable', 'integer', 'exists:product_wholesale_groups,id'],
            "{$pricePath}.promo_price" => ['nullable', 'integer', 'min:0', "lt:{$pricePath}.sale_price"],
        ], [
            "{$pricePath}.wholesale_price.lt" => 'Harga grosir harus lebih rendah dari harga normal.',
            "{$pricePath}.wholesale_min_qty.required_with" => 'Jumlah minimum wajib diisi jika harga grosir diatur.',
            "{$pricePath}.promo_price.lt" => 'Harga promo harus lebih rendah dari harga normal.',
        ]);

        $prices = $validator->validate()['prices'][$product->id];
        $wholesaleGroupId = array_key_exists('wholesale_group_id', $prices)
            ? ($prices['wholesale_group_id'] === null ? null : (int) $prices['wholesale_group_id'])
            : $product->wholesale_group_id;
        unset($prices['wholesale_group_id']);

        if (($prices['wholesale_price'] ?? null) === null) {
            $prices['wholesale_min_qty'] = null;
        }

        $updateProductPrices->handle(
            $product,
            $prices,
            $request->user(),
            $wholesaleGroupId,
        );

        return to_route('harga')->with('success', 'Harga produk berhasil diperbarui.');
    }

    public function storeWholesaleGroup(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:product_wholesale_groups,name'],
            'minimum_quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ]);

        $group = ProductWholesaleGroup::create($data);
        $recordActivity->handle(
            $request->user(),
            'wholesale_group.created',
            "Menambahkan grup grosir {$group->name}.",
            $group,
        );

        return to_route('harga')->with('success', 'Grup grosir berhasil ditambahkan.');
    }

    public function updateWholesaleGroup(
        Request $request,
        ProductWholesaleGroup $wholesaleGroup,
        RecordActivity $recordActivity,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('product_wholesale_groups', 'name')->ignore($wholesaleGroup),
            ],
            'minimum_quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ]);

        DB::transaction(function () use ($wholesaleGroup, $data, $request, $recordActivity): void {
            $lockedGroup = ProductWholesaleGroup::query()->lockForUpdate()->findOrFail($wholesaleGroup->id);
            $lockedGroup->update($data);
            $recordActivity->handle(
                $request->user(),
                'wholesale_group.updated',
                "Memperbarui grup grosir {$lockedGroup->name}.",
                $lockedGroup,
            );
        });

        return to_route('harga')->with('success', 'Grup grosir berhasil diperbarui.');
    }

    public function destroyWholesaleGroup(
        Request $request,
        ProductWholesaleGroup $wholesaleGroup,
        RecordActivity $recordActivity,
    ): RedirectResponse {
        DB::transaction(function () use ($wholesaleGroup, $request, $recordActivity): void {
            $lockedGroup = ProductWholesaleGroup::query()->lockForUpdate()->findOrFail($wholesaleGroup->id);
            $recordActivity->handle(
                $request->user(),
                'wholesale_group.deleted',
                "Menghapus grup grosir {$lockedGroup->name}.",
                $lockedGroup,
                ['product_count' => $lockedGroup->products()->count()],
            );
            $lockedGroup->delete();
        });

        return to_route('harga')->with('success', 'Grup grosir dihapus dan produk terkait dikembalikan ke harga grosir mandiri.');
    }

    public function history(Product $product): View
    {
        return view('harga-riwayat', [
            'product' => $product->load('category'),
            'histories' => $product->priceHistories()
                ->with('user')
                ->latest()
                ->paginate(20),
        ]);
    }
}
