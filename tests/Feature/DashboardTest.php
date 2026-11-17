<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_live_sales_purchase_profit_stock_and_category_data(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $stationery = ProductCategory::create(['name' => 'ATK']);
        $copyServices = ProductCategory::create(['name' => 'Fotocopy']);
        $pen = $this->product($stationery, 'Pulpen', 3, 5);
        $this->product($stationery, 'HVS F4', 0, 5);
        $copy = $this->product($copyServices, 'Fotocopy A4', 0, 0, false);
        $inactive = $this->product($stationery, 'Produk Nonaktif', 0, 3, true, false);

        $todaySale = $this->sale('TRX-DASH-TODAY', 30000, '2026-10-13 10:00:00');
        $this->saleItem($todaySale, $pen, 3, 10000, 5000);
        $secondTodaySale = $this->sale('TRX-DASH-TODAY-2', 15000, '2026-10-13 11:00:00');
        $this->saleItem($secondTodaySale, $copy, 5, 3000, 1000);
        $yesterdaySale = $this->sale('TRX-DASH-YESTERDAY', 20000, '2026-10-12 10:00:00');
        $this->saleItem($yesterdaySale, $pen, 2, 10000, 5000);

        $supplier = Supplier::create(['name' => 'Supplier ATK']);
        Purchase::create([
            'purchase_number' => 'PB-DASH-1',
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'total' => 12000,
        ]);
        CashEntry::create([
            'type' => 'pengeluaran',
            'category' => 'Listrik',
            'description' => 'Tagihan hari ini',
            'amount' => 3000,
            'transaction_date' => '2026-10-13',
        ]);

        $response = $this->get(route('dashboard'))->assertOk()
            ->assertSee('Pendapatan Hari Ini')
            ->assertSee('Rp 45.000')
            ->assertSee('2 Transaksi')
            ->assertSee('Rp 12.000')
            ->assertSee('Rp 22.000')
            ->assertSee('Pulpen')
            ->assertSee('Fotocopy A4')
            ->assertSee('Stok Menipis')
            ->assertSee('HVS F4')
            ->assertDontSee('Produk Nonaktif');

        $this->assertSame(45000, $response->viewData('todayRevenue'));
        $this->assertSame(2, $response->viewData('todayTransactions'));
        $this->assertSame(12000, $response->viewData('todayPurchases'));
        $this->assertSame(22000, $response->viewData('estimatedNetProfit'));
        $this->assertSame(20000, $response->viewData('yesterdayRevenue'));
        $this->assertSame(1, $response->viewData('yesterdayTransactions'));
        $this->assertSame(125.0, $response->viewData('todayRevenueTrend')['percentage']);
        $this->assertSame(2, $response->viewData('lowStockCount'));
        $this->assertSame(2, $response->viewData('weeklyChart')->filter(fn (array $day): bool => $day['revenue'] > 0)->count());
        $this->assertSame(65000, $response->viewData('weeklyRevenueTotal'));
        $this->assertSame(2, $response->viewData('categoryBreakdown')->count());
        $this->assertSame('Fotocopy A4', $response->viewData('bestSellers')->first()->product_name);
        $this->assertSame(5, $response->viewData('bestSellers')->first()->quantity_sold);
    }

    public function test_dashboard_uses_zero_values_and_empty_states_when_there_is_no_business_data(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get(route('dashboard'))->assertOk()
            ->assertSee('Rp 0')
            ->assertSee('Belum ada transaksi')
            ->assertSee('Belum ada produk terjual')
            ->assertSee('Tidak ada produk dengan stok menipis.')
            ->assertDontSee('1.275.000')
            ->assertDontSee('Fotocopy A4 BW');

        $this->assertSame(0, $response->viewData('todayRevenue'));
        $this->assertSame(0, $response->viewData('todayTransactions'));
        $this->assertSame(0, $response->viewData('todayPurchases'));
        $this->assertSame(0, $response->viewData('estimatedNetProfit'));
        $this->assertSame(0, $response->viewData('weeklyRevenueTotal'));
        $this->assertSame(0, $response->viewData('chartMaximum'));
        $this->assertCount(7, $response->viewData('weeklyChart'));
        $this->assertCount(0, $response->viewData('bestSellers'));
        $this->assertCount(0, $response->viewData('lowStockProducts'));
    }

    private function product(
        ProductCategory $category,
        string $name,
        int $stock,
        int $minimumStock,
        bool $tracksStock = true,
        bool $active = true,
    ): Product {
        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'unit' => 'pcs',
            'tracks_stock' => $tracksStock,
            'sale_price' => 10000,
            'cost_price' => 5000,
            'stock_qty' => $stock,
            'minimum_stock' => $minimumStock,
            'is_active' => $active,
        ]);
    }

    private function sale(string $number, int $total, string $createdAt): Sale
    {
        return Sale::query()->forceCreate([
            'transaction_number' => $number,
            'subtotal' => $total,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'payment_method' => 'cash',
            'amount_received' => $total,
            'change_amount' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function saleItem(Sale $sale, Product $product, int $quantity, int $price, int $cost): void
    {
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit' => $product->unit,
            'quantity' => $quantity,
            'unit_price' => $price,
            'cost_price' => $cost,
            'subtotal' => $quantity * $price,
        ]);
    }
}
