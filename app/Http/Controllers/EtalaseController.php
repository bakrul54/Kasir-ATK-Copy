<?php

namespace App\Http\Controllers;

use App\Actions\RecordActivity;
use App\Actions\UpdateProductPrices;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use App\Models\StoreSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EtalaseController extends Controller
{
    public function index(Request $request): View
    {
        $settings = StoreSetting::current();
        $products = Product::query()
            ->with('category')
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = $request->string('q')->toString();
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->string('status')->isNotEmpty(), function ($query) use ($request) {
                match ($request->string('status')->toString()) {
                    'tersedia' => $query->where('is_active', true)->where(fn ($q) => $q->where('tracks_stock', false)->orWhereColumn('stock_qty', '>', 'minimum_stock')),
                    'menipis' => $query->where('is_active', true)->where('tracks_stock', true)->where('stock_qty', '>', 0)->whereColumn('stock_qty', '<=', 'minimum_stock'),
                    'habis' => $query->where('is_active', true)->where('tracks_stock', true)->where('stock_qty', 0),
                    'promo' => $query->where('is_active', true)->whereNotNull('promo_price'),
                    'nonaktif' => $query->where('is_active', false),
                    default => null,
                };
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('etalase', [
            'products' => $products,
            'categories' => ProductCategory::query()->withCount('products')->orderBy('name')->get(),
            'product' => null,
            'openForm' => false,
            'settings' => $settings,
        ]);
    }

    public function create(): View
    {
        return $this->formView(new Product, true);
    }

    public function store(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $data = $this->validatedProduct($request);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('products', 'public');
        }

        DB::transaction(function () use ($data, $request, $recordActivity): void {
            $product = Product::create($data);

            if ($product->tracks_stock && $product->stock_qty > 0) {
                ProductStockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'opname',
                    'quantity' => $product->stock_qty,
                    'stock_before' => 0,
                    'stock_after' => $product->stock_qty,
                    'notes' => 'Stok awal saat produk dibuat.',
                ]);
            }

            $recordActivity->handle(
                $request->user(),
                'product.created',
                "Menambahkan produk {$product->name}.",
                $product,
            );
        });

        return to_route('etalase')->with('success', 'Produk berhasil ditambahkan.');
    }

    public function edit(Product $product): View
    {
        return $this->formView($product, true);
    }

    public function update(
        Request $request,
        Product $product,
        UpdateProductPrices $updateProductPrices,
        RecordActivity $recordActivity,
    ): RedirectResponse {
        $data = $this->validatedProduct($request, $product);
        $prices = Arr::only($data, UpdateProductPrices::FIELDS);
        $details = Arr::except($data, UpdateProductPrices::FIELDS);

        if ($request->hasFile('photo')) {
            $newPhotoPath = $request->file('photo')->store('products', 'public');
            if ($product->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
            }
            $data['photo_path'] = $newPhotoPath;
            $details['photo_path'] = $newPhotoPath;
        }

        DB::transaction(function () use ($product, $details, $prices, $request, $updateProductPrices, $recordActivity): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($lockedProduct->tracks_stock && ! $details['tracks_stock'] && $lockedProduct->stock_qty > 0) {
                throw ValidationException::withMessages([
                    'tracks_stock' => 'Stok produk harus dikosongkan melalui Inventory sebelum pencatatan stok dapat dimatikan.',
                ]);
            }

            $lockedProduct->update($details);
            $updateProductPrices->handle($product, $prices, $request->user());
            $recordActivity->handle(
                $request->user(),
                'product.updated',
                "Memperbarui produk {$lockedProduct->name}.",
                $lockedProduct,
            );
        });

        return to_route('etalase')->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Request $request, Product $product, RecordActivity $recordActivity): RedirectResponse
    {
        if ($product->stockMovements()->exists() || $product->saleItems()->exists()) {
            return to_route('etalase')->withErrors([
                'product' => 'Produk tidak dapat dihapus karena sudah memiliki riwayat stok atau transaksi. Nonaktifkan melalui form ubah produk.',
            ]);
        }

        $photoPath = $product->photo_path;
        DB::transaction(function () use ($request, $product, $recordActivity): void {
            $recordActivity->handle(
                $request->user(),
                'product.deleted',
                "Menghapus produk {$product->name}.",
                $product,
            );
            $product->delete();
        });

        if ($photoPath) {
            Storage::disk('public')->delete($photoPath);
        }

        return to_route('etalase')->with('success', 'Produk berhasil dihapus.');
    }

    public function storeCategory(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:product_categories,name'],
        ]);

        $category = ProductCategory::create($data);
        $recordActivity->handle(
            $request->user(),
            'category.created',
            "Menambahkan kategori {$category->name}.",
            $category,
        );

        return to_route('etalase')->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function destroyCategory(Request $request, ProductCategory $category, RecordActivity $recordActivity): RedirectResponse
    {
        if ($category->products()->exists()) {
            return to_route('etalase')->withErrors([
                'category' => 'Kategori tidak dapat dihapus selama masih digunakan oleh produk.',
            ]);
        }

        DB::transaction(function () use ($request, $category, $recordActivity): void {
            $recordActivity->handle(
                $request->user(),
                'category.deleted',
                "Menghapus kategori {$category->name}.",
                $category,
            );
            $category->delete();
        });

        return to_route('etalase')->with('success', 'Kategori berhasil dihapus.');
    }

    private function formView(Product $product, bool $openForm): View
    {
        return view('etalase', [
            'products' => Product::query()->with('category')->orderBy('name')->paginate(12),
            'categories' => ProductCategory::query()->withCount('products')->orderBy('name')->get(),
            'product' => $product,
            'openForm' => $openForm,
            'settings' => StoreSetting::current(),
        ]);
    }

    private function validatedProduct(Request $request, ?Product $product = null): array
    {
        $settings = StoreSetting::current();
        $units = array_values(array_unique([
            ...$settings->units,
            ...($product?->unit ? [$product->unit] : []),
        ]));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product)],
            'unit' => ['required', 'string', 'max:30', Rule::in($units)],
            'tracks_stock' => ['required', 'boolean'],
            'sale_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'cost_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'stock_qty' => $product ? ['prohibited'] : ['nullable', 'required_if:tracks_stock,1', 'integer', 'min:0', 'max:4294967295'],
            'minimum_stock' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'wholesale_price' => $settings->wholesale_enabled
                ? ['nullable', 'integer', 'min:0', 'lt:sale_price']
                : ['prohibited'],
            'wholesale_min_qty' => $settings->wholesale_enabled
                ? ['nullable', 'required_with:wholesale_price', 'integer', 'min:1', 'max:4294967295']
                : ['prohibited'],
            'promo_price' => $settings->promo_enabled
                ? ['nullable', 'integer', 'min:0', 'lt:sale_price']
                : ['prohibited'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_active' => ['required', 'boolean'],
        ], [
            'wholesale_price.lt' => 'Harga grosir harus lebih rendah dari harga jual.',
            'promo_price.lt' => 'Harga promo harus lebih rendah dari harga jual.',
            'photo.max' => 'Ukuran foto maksimal 2 MB.',
        ]);

        $data['minimum_stock'] = $data['tracks_stock']
            ? ($data['minimum_stock'] ?? $product?->minimum_stock ?? $settings->default_minimum_stock)
            : 0;

        if (! $data['tracks_stock']) {
            $data['stock_qty'] = 0;
        }

        if (array_key_exists('wholesale_price', $data) && $data['wholesale_price'] === null) {
            $data['wholesale_min_qty'] = null;
        }

        unset($data['photo']);

        return $data;
    }
}
