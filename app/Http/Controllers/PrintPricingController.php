<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StoreSetting;
use App\Support\PrintPricing;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PrintPricingController extends Controller
{
    public function index(): View
    {
        $settings = StoreSetting::current();
        $products = Product::query()
            ->with('wholesaleGroup')
            ->whereIn('name', array_column(PrintPricing::CATEGORIES, 'product_name'))
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('tracks_stock', false)->orWhere('stock_qty', '>', 0))
            ->get()
            ->keyBy('name');

        return view('print-pricing', [
            'printCategories' => $this->printCategories($products, $settings),
        ]);
    }

    /**
     * @return array<string, array{name: string, product_name: string, product: array<string, mixed>|null}>
     */
    private function printCategories(Collection $products, StoreSetting $settings): array
    {
        $categories = [];

        foreach (PrintPricing::CATEGORIES as $key => $category) {
            $product = $products->get($category['product_name']);

            $categories[$key] = [
                ...$category,
                'product' => $product ? PrintPricing::productData($product, $settings) : null,
            ];
        }

        return $categories;
    }
}
