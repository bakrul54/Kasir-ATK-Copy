<?php

namespace App\Http\Controllers;

use App\Actions\AdjustProductStock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockManagementController extends Controller
{
    public function index(Request $request): View
    {
        $stockProducts = Product::query()->where('tracks_stock', true);
        $products = (clone $stockProducts)
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
            ->when($request->query('status') === 'menipis', fn ($query) => $query->where('stock_qty', '>', 0)->whereColumn('stock_qty', '<=', 'minimum_stock'))
            ->when($request->query('status') === 'habis', fn ($query) => $query->where('stock_qty', 0))
            ->when($request->query('status') === 'aman', fn ($query) => $query->whereColumn('stock_qty', '>', 'minimum_stock'))
            ->when($request->query('status') === 'nonaktif', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(12, ['*'], 'products_page')
            ->withQueryString();

        return view('stok', [
            'products' => $products,
            'categories' => ProductCategory::query()
                ->whereHas('products', fn ($query) => $query->where('tracks_stock', true))
                ->orderBy('name')
                ->get(),
            'stockOptions' => Product::query()
                ->select(['id', 'name', 'sku', 'stock_qty', 'unit'])
                ->where('tracks_stock', true)
                ->orderBy('name')
                ->get(),
            'movements' => ProductStockMovement::query()
                ->with(['product:id,name,unit', 'user:id,name'])
                ->latest()
                ->paginate(15, ['*'], 'movements_page'),
            'stats' => [
                'total' => (clone $stockProducts)->count(),
                'low' => (clone $stockProducts)->where('stock_qty', '>', 0)->whereColumn('stock_qty', '<=', 'minimum_stock')->count(),
                'empty' => (clone $stockProducts)->where('stock_qty', 0)->count(),
                'value' => (clone $stockProducts)->selectRaw('COALESCE(SUM(stock_qty * cost_price), 0) as stock_value')->value('stock_value'),
            ],
            'selectedProductId' => $request->integer('product_id') ?: null,
        ]);
    }

    public function storeMovement(Request $request, AdjustProductStock $adjustProductStock): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type' => ['required', 'in:masuk,keluar,opname'],
            'quantity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['type'] !== 'opname' && $data['quantity'] < 1) {
            return back()->withErrors([
                'quantity' => 'Jumlah barang masuk atau keluar harus lebih dari nol.',
            ])->withInput();
        }

        $adjustProductStock->handle(
            Product::query()->findOrFail($data['product_id']),
            $data['type'],
            $data['quantity'],
            $data['notes'] ?? null,
            $request->user(),
        );

        $messages = [
            'masuk' => 'Stok masuk berhasil dicatat.',
            'keluar' => 'Stok keluar berhasil dicatat.',
            'opname' => 'Hasil stok opname berhasil disimpan.',
        ];

        return to_route('stok')->with('success', $messages[$data['type']]);
    }
}
