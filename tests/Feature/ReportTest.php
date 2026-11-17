<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_profit_report_summarizes_sales_costs_and_operating_expenses_for_the_selected_period(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->recordSale('TRX-REPORT-1', 'Budi', 18000, '2026-10-13 10:00:00', 2, 5000, 10000);
        $this->recordSale('TRX-REPORT-2', 'Siti', 12000, '2026-10-12 10:00:00', 1, 4000, 12000);
        CashEntry::create([
            'type' => 'pengeluaran',
            'category' => 'Listrik',
            'description' => 'Tagihan',
            'amount' => 2000,
            'transaction_date' => '2026-10-13',
        ]);
        CashEntry::create([
            'type' => 'pengeluaran',
            'category' => 'Internet',
            'description' => 'Tagihan lama',
            'amount' => 900,
            'transaction_date' => '2026-09-30',
        ]);

        $response = $this->get(route('laporan', [
            'report' => 'profit',
            'from' => '2026-10-12',
            'to' => '2026-10-13',
        ]))->assertOk()
            ->assertSee('Laba Rugi')
            ->assertSee('14.000');

        $this->assertSame([
            'revenue' => 30000,
            'cost_of_goods_sold' => 14000,
            'operating_expenses' => 2000,
            'gross_profit' => 16000,
            'net_profit' => 14000,
        ], $response->viewData('reportData')['summary']);
    }

    public function test_stock_report_shows_current_inventory_and_movement_in_the_period(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        $product = $this->product('Kertas A4', 24, 2500, 1800);
        ProductStockMovement::create([
            'product_id' => $product->id,
            'type' => 'masuk',
            'quantity' => 10,
            'stock_before' => 14,
            'stock_after' => 24,
            'notes' => 'Penerimaan stok',
            'created_at' => '2026-10-13 10:00:00',
            'updated_at' => '2026-10-13 10:00:00',
        ]);

        $this->get(route('laporan', [
            'report' => 'stock',
            'from' => '2026-10-13',
            'to' => '2026-10-13',
        ]))->assertOk()
            ->assertSee('Kertas A4')
            ->assertSee('24')
            ->assertViewHas('reportData', fn (array $data): bool => $data['summary']['Nilai persediaan'] === 43200
                && $data['summary']['Produk menipis / habis'] === 0
                && $data['summary']['Pergerakan periode'] === 1);
    }

    public function test_purchase_report_includes_supplier_payment_status_and_unpaid_amount(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::create(['name' => 'CV Sumber ATK']);
        Purchase::create([
            'purchase_number' => 'PB-REPORT-1',
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'hutang',
            'total' => 125000,
        ]);

        $this->get(route('laporan', [
            'report' => 'purchases',
            'from' => '2026-10-13',
            'to' => '2026-10-13',
        ]))->assertOk()
            ->assertSee('PB-REPORT-1')
            ->assertSee('CV Sumber ATK')
            ->assertSee('HUTANG')
            ->assertViewHas('reportData', fn (array $data): bool => $data['summary']['Belum lunas'] === 125000);
    }

    public function test_income_report_groups_sales_by_date_and_payment_method(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->recordSale('TRX-INCOME-1', 'Pelanggan', 18000, '2026-10-13 10:00:00', 2, 5000, 10000);

        $this->get(route('laporan', [
            'report' => 'income',
            'from' => '2026-10-13',
            'to' => '2026-10-13',
        ]))->assertOk()
            ->assertSee('CASH')
            ->assertViewHas('reportData', fn (array $data): bool => $data['rows'][0][2] === 1
                && $data['rows'][0][3] === 18000
                && $data['summary']['Total pendapatan'] === 18000);
    }

    public function test_expense_report_lists_only_operating_expenses_in_the_period(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        CashEntry::create([
            'type' => 'pengeluaran',
            'category' => 'Listrik',
            'description' => 'Tagihan Oktober',
            'amount' => 1200,
            'transaction_date' => '2026-10-13',
        ]);
        CashEntry::create([
            'type' => 'keluar',
            'category' => 'Prive',
            'description' => 'Bukan biaya operasional',
            'amount' => 500,
            'transaction_date' => '2026-10-13',
        ]);

        $this->get(route('laporan', [
            'report' => 'expenses',
            'from' => '2026-10-13',
            'to' => '2026-10-13',
        ]))->assertOk()
            ->assertSee('Tagihan Oktober')
            ->assertDontSee('Bukan biaya operasional')
            ->assertViewHas('reportData', fn (array $data): bool => $data['summary']['Total pengeluaran'] === 1200);
    }

    public function test_bestseller_report_ranks_items_by_quantity_and_preserves_sales_snapshot(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        $sale = $this->recordSale('TRX-BEST-1', 'Pelanggan', 18000, '2026-10-13 10:00:00', 2, 5000, 10000);

        Product::query()->where('name', 'Produk TRX-BEST-1')->update(['name' => 'Nama Produk Baru']);

        $this->get(route('laporan', [
            'report' => 'bestsellers',
            'from' => '2026-10-13',
            'to' => '2026-10-13',
        ]))->assertOk()
            ->assertSee('Produk TRX-BEST-1')
            ->assertDontSee('Nama Produk Baru')
            ->assertViewHas('reportData', fn (array $data): bool => $data['rows'][0][2] === 2
                && $data['rows'][0][3] === 20000
                && $data['rows'][0][4] === 10000);
    }

    public function test_cash_report_combines_sales_paid_purchases_and_manual_movements(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->recordSale('TRX-CASH-1', 'Pelanggan', 18000, '2026-10-13 10:00:00', 2, 5000, 10000);
        $supplier = Supplier::create(['name' => 'CV Kas Supplier']);
        Purchase::create([
            'purchase_number' => 'PB-CASH-1',
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'total' => 7000,
        ]);
        CashEntry::create([
            'type' => 'masuk',
            'category' => 'Setoran modal',
            'description' => 'Saldo awal',
            'amount' => 2000,
            'transaction_date' => '2026-10-13',
        ]);
        CashEntry::create([
            'type' => 'transfer',
            'category' => 'Transfer saldo',
            'description' => 'Pindah ke dompet',
            'amount' => 3000,
            'account' => CashEntry::ACCOUNT_DRAWER,
            'to_account' => CashEntry::ACCOUNT_WALLET,
            'transaction_date' => '2026-10-13',
        ]);

        $this->get(route('laporan', [
            'report' => 'cash',
            'from' => '2026-10-13',
            'to' => '2026-10-13',
        ]))->assertOk()
            ->assertSee('TRX-CASH-1')
            ->assertSee('PB-CASH-1')
            ->assertSee('Saldo awal')
            ->assertSee('Pindah ke dompet')
            ->assertSee('Laci toko → Dompet toko')
            ->assertSee('Transfer')
            ->assertViewHas('reportData', fn (array $data): bool => $data['summary']['Kas masuk'] === 20000
                && $data['summary']['Kas keluar'] === 7000
                && $data['summary']['Perubahan kas bersih'] === 13000);
    }

    public function test_print_view_renders_selected_report_and_data(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->recordSale('TRX-PRINT-1', 'Pelanggan', 18000, '2026-10-13 10:00:00', 2, 5000, 10000);

        $this->get(route('laporan.print', [
            'report' => 'sales',
            'from' => '2026-10-13',
            'to' => '2026-10-13',
        ]))->assertOk()
            ->assertSee('Laporan Penjualan')
            ->assertSee('TRX-PRINT-1')
            ->assertSee('Rp 18.000')
            ->assertSee('window.print()', false);
    }

    public function test_csv_export_uses_report_data_and_neutralizes_spreadsheet_formulas(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->recordSale('TRX-CSV-1', '=HYPERLINK("https://example.test")', 18000, '2026-10-13 10:00:00', 2, 5000, 10000);

        $response = $this->get(route('laporan.export', [
            'report' => 'sales',
            'from' => '2026-10-13',
            'to' => '2026-10-13',
        ]));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('laporan-penjualan-2026-10-13-sampai-2026-10-13.csv');
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
        $this->assertStringContainsString('TRX-CSV-1', $response->streamedContent());
    }

    public function test_report_rejects_unknown_type_and_reversed_date_range(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('laporan'))->get(route('laporan', ['report' => 'other']))
            ->assertRedirect(route('laporan'))
            ->assertSessionHasErrors('report');

        $this->from(route('laporan'))->get(route('laporan', [
            'from' => '2026-10-14',
            'to' => '2026-10-13',
        ]))->assertRedirect(route('laporan'))
            ->assertSessionHasErrors('to');
    }

    public function test_report_endpoints_require_authentication(): void
    {
        $this->get(route('laporan'))->assertRedirect(route('login'));
        $this->get(route('laporan.export'))->assertRedirect(route('login'));
        $this->get(route('laporan.print'))->assertRedirect(route('login'));
    }

    private function recordSale(
        string $number,
        string $customer,
        int $total,
        string $date,
        int $quantity,
        int $costPrice,
        int $unitPrice,
    ): Sale {
        $product = $this->product('Produk '.$number, 50, $unitPrice, $costPrice);
        $lineTotal = $unitPrice * $quantity;
        $discount = $lineTotal - $total;
        $sale = Sale::create([
            'transaction_number' => $number,
            'customer_name' => $customer,
            'subtotal' => $lineTotal,
            'discount_type' => 'nominal',
            'discount_value' => $discount,
            'discount_amount' => $discount,
            'total' => $total,
            'payment_method' => 'cash',
            'amount_received' => $total,
            'change_amount' => 0,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit' => $product->unit,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'cost_price' => $costPrice,
            'subtotal' => $unitPrice * $quantity,
        ]);

        return $sale;
    }

    private function product(string $name, int $stock, int $salePrice, int $costPrice): Product
    {
        return Product::create([
            'category_id' => ProductCategory::create(['name' => 'Kategori '.$name])->id,
            'name' => $name,
            'unit' => 'pcs',
            'tracks_stock' => true,
            'sale_price' => $salePrice,
            'cost_price' => $costPrice,
            'stock_qty' => $stock,
            'minimum_stock' => 5,
            'is_active' => true,
        ]);
    }
}
