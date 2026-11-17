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

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_summary_combines_cash_entries_and_counts_profit_from_sales(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);
        $sale = Sale::create([
            'transaction_number' => 'TRX-20261013-000001',
            'user_id' => $user->id,
            'subtotal' => 20000,
            'discount_type' => 'nominal',
            'discount_value' => 2000,
            'discount_amount' => 2000,
            'total' => 18000,
            'tip_amount' => 500,
            'payment_method' => 'cash',
            'amount_received' => 20500,
            'change_amount' => 2000,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->productId(),
            'product_name' => 'Buku Tulis',
            'unit' => 'pcs',
            'quantity' => 2,
            'unit_price' => 10000,
            'cost_price' => 5000,
            'subtotal' => 20000,
        ]);
        $supplier = Supplier::create(['name' => 'CV Kertas Jaya']);
        Purchase::create([
            'purchase_number' => 'PB-LUNAS',
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'total' => 9000,
        ]);
        Purchase::create([
            'purchase_number' => 'PB-HUTANG',
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'hutang',
            'total' => 7000,
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'type' => 'masuk',
            'category' => 'Setoran modal',
            'description' => 'Saldo awal',
            'amount' => 3000,
            'transaction_date' => '2026-10-13',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'type' => 'pengeluaran',
            'category' => 'Listrik',
            'description' => 'Tagihan listrik',
            'amount' => 2000,
            'transaction_date' => '2026-10-13',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'type' => 'keluar',
            'category' => 'Prive',
            'description' => 'Pengambilan pemilik',
            'amount' => 1000,
            'transaction_date' => '2026-10-13',
        ]);

        $response = $this->get(route('keuangan'));

        $response->assertOk()
            ->assertSee('PB-LUNAS')
            ->assertDontSee('PB-HUTANG')
            ->assertViewHas('stats', fn (array $stats): bool => $stats['accounts'][CashEntry::ACCOUNT_DRAWER] === 9500
                && $stats['accounts'][CashEntry::ACCOUNT_WALLET] === 0
                && $stats['accounts'][CashEntry::ACCOUNT_NON_CASH] === 0
                && $stats['cash_in'] === 21500
                && $stats['cash_out'] === 12000
                && $stats['revenue'] === 18000
                && $stats['cost_of_goods_sold'] === 10000
                && $stats['gross_profit'] === 8000
                && $stats['operating_expenses'] === 2000
                && $stats['net_profit'] === 6000
                && $stats['unpaid_purchases'] === 7000)
            ->assertViewHas('expenseCategories', fn ($categories): bool => $categories->sole()->category === 'Listrik'
                && $categories->sole()->total === 2000);
    }

    public function test_cash_entry_is_saved_with_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('keuangan.store'), [
            'type' => 'pengeluaran',
            'category' => 'Internet',
            'description' => 'Tagihan internet bulanan',
            'amount' => 350000,
            'transaction_date' => '2026-10-13',
        ])->assertRedirect(route('keuangan'))
            ->assertSessionHas('success');

        $entry = CashEntry::query()->firstOrFail();
        $this->assertModelExists($entry);
        $this->assertSame('2026-10-13', $entry->transaction_date->toDateString());
        $this->assertDatabaseHas('cash_entries', [
            'user_id' => $user->id,
            'type' => 'pengeluaran',
            'category' => 'Internet',
            'description' => 'Tagihan internet bulanan',
            'amount' => 350000,
        ]);
    }

    public function test_cash_entry_rejects_zero_amount_and_future_date(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $this->actingAs(User::factory()->create());

        $this->from(route('keuangan'))->post(route('keuangan.store'), [
            'type' => 'keluar',
            'category' => 'Prive',
            'description' => 'Pengambilan kas',
            'amount' => 0,
            'transaction_date' => '2026-10-14',
        ])->assertRedirect(route('keuangan'))
            ->assertSessionHasErrors(['amount', 'transaction_date']);

        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_transfer_between_accounts_changes_each_balance_without_becoming_an_expense(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('keuangan.store'), [
            'type' => 'transfer',
            'account' => CashEntry::ACCOUNT_DRAWER,
            'to_account' => CashEntry::ACCOUNT_WALLET,
            'amount' => 5000,
            'transaction_date' => '2026-10-13',
        ])->assertRedirect(route('keuangan'))
            ->assertSessionHas('success', 'Transfer antar saldo berhasil dicatat.');

        $this->assertDatabaseHas('cash_entries', [
            'user_id' => $user->id,
            'type' => 'transfer',
            'category' => 'Transfer saldo',
            'account' => CashEntry::ACCOUNT_DRAWER,
            'to_account' => CashEntry::ACCOUNT_WALLET,
            'amount' => 5000,
        ]);

        $this->get(route('keuangan'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['accounts'][CashEntry::ACCOUNT_DRAWER] === -5000
                && $stats['accounts'][CashEntry::ACCOUNT_WALLET] === 5000
                && $stats['accounts'][CashEntry::ACCOUNT_NON_CASH] === 0
                && $stats['operating_expenses'] === 0)
            ->assertSee('Transfer saldo')
            ->assertSee('Laci toko → Dompet toko');
    }

    public function test_transfer_rejects_the_same_source_and_destination_account(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('keuangan'))->post(route('keuangan.store'), [
            'type' => 'transfer',
            'account' => CashEntry::ACCOUNT_DRAWER,
            'to_account' => CashEntry::ACCOUNT_DRAWER,
            'amount' => 5000,
            'transaction_date' => '2026-10-13',
        ])->assertRedirect(route('keuangan'))
            ->assertSessionHasErrors(['to_account']);

        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_qris_sales_and_purchases_use_the_selected_account_not_the_cash_drawer(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);
        Sale::create([
            'transaction_number' => 'TRX-QRIS-000001',
            'user_id' => $user->id,
            'subtotal' => 25000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total' => 25000,
            'payment_method' => 'qris',
            'amount_received' => 25000,
            'change_amount' => 0,
        ]);
        Sale::create([
            'transaction_number' => 'TRX-CASH-000002',
            'user_id' => $user->id,
            'subtotal' => 10000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total' => 10000,
            'payment_method' => 'cash',
            'amount_received' => 10000,
            'change_amount' => 0,
        ]);
        $supplier = Supplier::create(['name' => 'CV Dompet Toko']);
        Purchase::create([
            'purchase_number' => 'PB-DOMPET',
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'payment_account' => CashEntry::ACCOUNT_WALLET,
            'total' => 1500,
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'type' => 'transfer',
            'category' => 'Transfer saldo',
            'amount' => 4000,
            'account' => CashEntry::ACCOUNT_DRAWER,
            'to_account' => CashEntry::ACCOUNT_WALLET,
            'transaction_date' => '2026-10-13',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'type' => 'pengeluaran',
            'category' => 'Parkir',
            'amount' => 500,
            'account' => CashEntry::ACCOUNT_DRAWER,
            'transaction_date' => '2026-10-13',
        ]);

        $this->get(route('keuangan'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['accounts'][CashEntry::ACCOUNT_DRAWER] === 5500
                && $stats['accounts'][CashEntry::ACCOUNT_WALLET] === 2500
                && $stats['accounts'][CashEntry::ACCOUNT_NON_CASH] === 25000)
            ->assertSee('QRIS / Transfer');
    }

    public function test_finance_ledger_filters_entries_by_period_and_search_term(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);
        CashEntry::create([
            'user_id' => $user->id,
            'type' => 'pengeluaran',
            'category' => 'Listrik',
            'description' => 'Tagihan Oktober',
            'amount' => 450000,
            'transaction_date' => '2026-10-13',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'type' => 'pengeluaran',
            'category' => 'Internet',
            'description' => 'Tagihan September',
            'amount' => 350000,
            'transaction_date' => '2026-09-30',
        ]);

        $this->get(route('keuangan', [
            'from' => '2026-10-13',
            'to' => '2026-10-13',
            'q' => 'Listrik',
        ]))->assertOk()
            ->assertSee('Tagihan Oktober')
            ->assertDontSee('Tagihan September')
            ->assertViewHas('stats', fn (array $stats): bool => $stats['operating_expenses'] === 450000)
            ->assertViewHas('expenseCategories', fn ($categories): bool => $categories->sole()->category === 'Listrik');
    }

    public function test_finance_endpoints_require_authentication(): void
    {
        $this->get(route('keuangan'))->assertRedirect(route('login'));
        $this->post(route('keuangan.store'), [])->assertRedirect(route('login'));
    }

    private function productId(): int
    {
        return Product::create([
            'category_id' => ProductCategory::create(['name' => 'ATK'])->id,
            'name' => 'Buku Tulis',
            'unit' => 'pcs',
            'tracks_stock' => true,
            'sale_price' => 10000,
            'cost_price' => 5000,
            'stock_qty' => 8,
            'is_active' => true,
        ])->id;
    }
}
