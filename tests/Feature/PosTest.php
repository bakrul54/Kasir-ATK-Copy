<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductWholesaleGroup;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    private const SUBMISSION_TOKEN = '00000000-0000-4000-8000-000000000001';

    public function test_sale_uses_current_prices_and_decreases_stock_for_product_and_service_lines(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $user = User::factory()->create();
        $paper = $this->product('HVS A4', 20, 5000, 3000);
        $service = $this->product('Fotocopy A4 BW', 0, 300, 100, false);
        $this->actingAs($user);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'customer_name' => 'Budi Santoso',
            'payment_method' => 'cash',
            'amount_received' => 12000,
            'discount_type' => 'nominal',
            'discount_value' => 1000,
            'items' => [
                ['product_id' => $paper->id, 'quantity' => 2],
                ['product_id' => $service->id, 'quantity' => 10],
            ],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHas('success');

        $sale = Sale::query()->firstOrFail();
        $this->assertSame('TRX-20261013-000001', $sale->transaction_number);
        $this->assertSame('Budi Santoso', $sale->customer_name);
        $this->assertSame(13000, $sale->subtotal);
        $this->assertSame(1000, $sale->discount_amount);
        $this->assertSame(12000, $sale->total);
        $this->assertSame(0, $sale->change_amount);
        $this->assertSame(2, $sale->items()->count());
        $this->assertDatabaseHas('products', ['id' => $paper->id, 'stock_qty' => 18]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $paper->id,
            'quantity' => 2,
            'unit_price' => 5000,
            'cost_price' => 3000,
            'subtotal' => 10000,
        ]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $paper->id,
            'sale_id' => $sale->id,
            'type' => 'keluar',
            'quantity' => 2,
            'stock_before' => 20,
            'stock_after' => 18,
        ]);
    }

    public function test_print_quote_uses_catalogue_products_and_the_current_cashier_prices(): void
    {
        $this->actingAs(User::factory()->create());
        $group = ProductWholesaleGroup::create([
            'name' => 'Print',
            'minimum_quantity' => 12,
        ]);
        $blackWhite = $this->product('Print Hitam Putih', 0, 1000, 300, false);
        $color = $this->product('Print Warna <10%', 0, 1500, 500, false);
        $blackWhite->update([
            'wholesale_price' => 800,
            'wholesale_min_qty' => null,
            'wholesale_group_id' => $group->id,
        ]);
        $color->update([
            'wholesale_price' => 1200,
            'wholesale_min_qty' => null,
            'wholesale_group_id' => $group->id,
        ]);

        $this->get(route('kasir'))
            ->assertOk()
            ->assertSee($blackWhite->name)
            ->assertSee($color->name)
            ->assertSee('"id":"'.$blackWhite->id.'"', false)
            ->assertViewHas('printProducts', fn (array $products): bool => $products[(string) $blackWhite->id]['tracksStock'] === false
                && $products[(string) $blackWhite->id]['stock'] === null
                && $products[(string) $color->id]['tracksStock'] === false
                && $products[(string) $color->id]['stock'] === null);

        $this->post(route('kasir.store'), [
            'submission_token' => '00000000-0000-4000-8000-000000000002',
            'payment_method' => 'qris',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [
                ['product_id' => $blackWhite->id, 'quantity' => 6, 'unit_price' => 1],
                ['product_id' => $color->id, 'quantity' => 6, 'unit_price' => 1],
            ],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHas('success');

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(12000, $sale->subtotal);
        $this->assertDatabaseHas('products', [
            'id' => $blackWhite->id,
            'tracks_stock' => false,
            'stock_qty' => 0,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $color->id,
            'tracks_stock' => false,
            'stock_qty' => 0,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $blackWhite->id,
            'product_name' => 'Print Hitam Putih',
            'unit' => 'lembar',
            'quantity' => 6,
            'unit_price' => 800,
            'cost_price' => 300,
            'subtotal' => 4800,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $color->id,
            'product_name' => 'Print Warna <10%',
            'quantity' => 6,
            'unit_price' => 1200,
            'cost_price' => 500,
            'subtotal' => 7200,
        ]);
        $this->get(route('kasir.detail', $sale))
            ->assertOk()
            ->assertSee('Print Hitam Putih')
            ->assertSee('Print Warna <10%');
        $this->get(route('kasir.receipt', $sale))
            ->assertOk()
            ->assertSee('Print Hitam Putih')
            ->assertSee('Print Warna <10%');
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kasir');
    }

    public function test_pos_shows_available_print_and_service_products_while_searching_other_products(): void
    {
        $this->actingAs(User::factory()->create());
        $category = ProductCategory::create(['name' => 'Cetak dan Jasa']);
        $printProduct = $this->product('Cetak Foto 4R', 0, 3000, 1000, false);
        $printProduct->update(['category_id' => $category->id]);
        $outOfStockProduct = $this->product('Map Cetak', 0, 5000, 2000);
        $outOfStockProduct->update(['category_id' => $category->id]);
        $inactiveProduct = $this->product('Jilid Nonaktif', 0, 5000, 2000, false);
        $inactiveProduct->update(['category_id' => $category->id, 'is_active' => false]);
        $searchedProduct = $this->product('Pulpen Biru', 10, 2000, 1000);

        $this->get(route('kasir', ['product_q' => 'Pulpen']))
            ->assertOk()
            ->assertSee('Pulpen Biru')
            ->assertSee('id="print-and-service-products"', false)
            ->assertSee('Cetak Foto 4R')
            ->assertSee('class="btn sm primary add-to-cart"', false)
            ->assertViewHas('printAndServiceProducts', fn ($products): bool => $products->modelKeys() === [$printProduct->id])
            ->assertViewHas('products', fn ($products): bool => $products->contains('id', $searchedProduct->id));
    }

    public function test_print_quote_page_maps_coverage_categories_to_catalogue_products(): void
    {
        $this->actingAs(User::factory()->create());
        $blackWhite = $this->product('Print Hitam Putih', 0, 1300, 300, false);
        $color = $this->product('Print Warna <10%', 0, 1500, 500, false);

        $this->get(route('print-pricing'))
            ->assertOk()
            ->assertViewHas('printCategories', fn (array $categories): bool => $categories['hitam-putih']['product']['id'] === (string) $blackWhite->id
                && $categories['hitam-putih']['product']['salePrice'] === 1300
                && $categories['warna-10']['product']['id'] === (string) $color->id
                && $categories['warna-10']['product']['salePrice'] === 1500);
    }

    public function test_sale_applies_wholesale_price_when_mixed_group_quantity_reaches_the_minimum(): void
    {
        $this->actingAs(User::factory()->create());
        $group = ProductWholesaleGroup::create([
            'name' => 'Print',
            'minimum_quantity' => 20,
        ]);
        $blackWhite = $this->product('Print Hitam Putih', 0, 300, 100, false);
        $color = $this->product('Print Warna', 0, 1500, 500, false);
        $blackWhite->update([
            'wholesale_price' => 200,
            'wholesale_min_qty' => 20,
            'wholesale_group_id' => $group->id,
        ]);
        $color->update([
            'wholesale_price' => 1000,
            'wholesale_min_qty' => 20,
            'wholesale_group_id' => $group->id,
        ]);

        $this->get(route('kasir'))
            ->assertOk()
            ->assertSee('data-wholesale-group-name="Print"', false)
            ->assertSee('data-wholesale-group-min-qty="20"', false)
            ->assertSee('id="wholesale-progress"', false);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 13600,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [
                ['product_id' => $blackWhite->id, 'quantity' => 8],
                ['product_id' => $color->id, 'quantity' => 12],
            ],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHas('success');

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(13600, $sale->subtotal);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $blackWhite->id,
            'unit_price' => 200,
            'subtotal' => 1600,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $color->id,
            'unit_price' => 1000,
            'subtotal' => 12000,
        ]);
    }

    public function test_sale_keeps_regular_prices_when_mixed_group_quantity_is_below_the_minimum(): void
    {
        $this->actingAs(User::factory()->create());
        $group = ProductWholesaleGroup::create([
            'name' => 'Print',
            'minimum_quantity' => 20,
        ]);
        $blackWhite = $this->product('Print Hitam Putih', 0, 300, 100, false);
        $color = $this->product('Print Warna', 0, 1500, 500, false);
        $blackWhite->update(['wholesale_price' => 200, 'wholesale_group_id' => $group->id]);
        $color->update(['wholesale_price' => 1000, 'wholesale_group_id' => $group->id]);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 17700,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [
                ['product_id' => $blackWhite->id, 'quantity' => 9],
                ['product_id' => $color->id, 'quantity' => 10],
            ],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHas('success');

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(17700, $sale->subtotal);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $blackWhite->id,
            'unit_price' => 300,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $color->id,
            'unit_price' => 1500,
        ]);
    }

    public function test_duplicate_submission_token_records_only_one_sale_and_decreases_stock_once(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Buku', 10, 10000, 5000);
        $saleData = [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 20000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];

        $this->post(route('kasir.store'), $saleData)
            ->assertRedirect(route('kasir'))
            ->assertSessionHas('success');
        $this->post(route('kasir.store'), $saleData)
            ->assertRedirect(route('kasir'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 8]);
        $this->assertDatabaseCount('product_stock_movements', 1);
    }

    public function test_owner_cancels_sale_records_refund_and_removes_it_from_revenue_reports(): void
    {
        $this->travelTo('2026-10-19 12:00:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $product = $this->product('Buku', 10, 1000, 500);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 2000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(1000, $sale->total);
        $this->assertSame(1000, $sale->change_amount);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 9]);

        $this->post(route('kasir.cancel', $sale), [
            'reason' => 'Transaksi tercatat ganda',
            'refund_confirmed' => '1',
        ])
            ->assertRedirect(route('kasir.detail', $sale))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'canceled_by' => $owner->id,
            'cancellation_reason' => 'Transaksi tercatat ganda',
        ]);
        $this->assertNotNull($sale->fresh()->canceled_at);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 10]);
        $this->assertDatabaseHas('product_stock_movements', [
            'sale_id' => $sale->id,
            'type' => 'masuk',
            'quantity' => 1,
            'stock_before' => 9,
            'stock_after' => 10,
            'notes' => 'Pembatalan transaksi '.$sale->transaction_number,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'sale.cancelled',
            'subject_type' => Sale::class,
            'subject_id' => $sale->id,
        ]);
        $this->assertDatabaseHas('cash_entries', [
            'user_id' => $owner->id,
            'sale_id' => $sale->id,
            'type' => 'keluar',
            'category' => 'Refund penjualan',
            'description' => 'Refund transaksi '.$sale->transaction_number,
            'amount' => 1000,
            'transaction_date' => '2026-10-19 00:00:00',
        ]);

        $this->from(route('kasir.detail', $sale))
            ->post(route('kasir.cancel', $sale), [
                'reason' => 'Transaksi tercatat ganda',
                'refund_confirmed' => '1',
            ])
            ->assertRedirect(route('kasir.detail', $sale))
            ->assertSessionHasErrors('sale');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 10]);
        $this->assertDatabaseCount('product_stock_movements', 2);

        $this->get(route('kasir.detail', $sale))
            ->assertOk()
            ->assertSee('Transaksi dibatalkan')
            ->assertSee('Transaksi tercatat ganda')
            ->assertSee('Refund Rp 1.000 sudah dicatat sebagai kas keluar');
        $this->get(route('kasir.receipt', $sale))
            ->assertOk()
            ->assertSee('TRANSAKSI DIBATALKAN');

        $salesReport = $this->get(route('laporan', [
            'report' => 'sales',
            'from' => '2026-10-19',
            'to' => '2026-10-19',
        ]))->assertOk()->viewData('reportData');
        $this->assertSame(0, $salesReport['summary']['Jumlah transaksi']);
        $this->assertSame([], $salesReport['rows']);

        $this->get(route('keuangan', ['from' => '2026-10-19', 'to' => '2026-10-19']))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['revenue'] === 0
                && $stats['cash_in'] === 1000
                && $stats['cash_out'] === 1000
                && $stats['accounts'][CashEntry::ACCOUNT_DRAWER] === 0
                && $stats['gross_profit'] === 0
                && $stats['net_profit'] === 0);
        $cashReport = $this->get(route('laporan', [
            'report' => 'cash',
            'from' => '2026-10-19',
            'to' => '2026-10-19',
        ]))->assertOk()->viewData('reportData');
        $this->assertSame(1000, $cashReport['summary']['Kas masuk']);
        $this->assertSame(1000, $cashReport['summary']['Kas keluar']);
        $this->assertSame(0, $cashReport['summary']['Perubahan kas bersih']);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('todayRevenue', 0)
            ->assertViewHas('todayTransactions', 0);
    }

    public function test_qris_refund_reduces_the_noncash_account_instead_of_the_cash_drawer(): void
    {
        $this->travelTo('2026-10-19 12:00:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $product = $this->product('Buku', 10, 1000, 500);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'qris',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->post(route('kasir.cancel', $sale), [
            'reason' => 'Transaksi QRIS salah',
            'refund_confirmed' => '1',
        ])->assertRedirect(route('kasir.detail', $sale));

        $this->assertDatabaseHas('cash_entries', [
            'sale_id' => $sale->id,
            'type' => 'keluar',
            'account' => CashEntry::ACCOUNT_NON_CASH,
        ]);
        $this->get(route('keuangan'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['accounts'][CashEntry::ACCOUNT_DRAWER] === 0
                && $stats['accounts'][CashEntry::ACCOUNT_NON_CASH] === 0);
    }

    public function test_sale_cannot_be_cancelled_without_confirming_that_refund_was_given(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $product = $this->product('Buku', 10, 1000, 500);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 1000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));
        $sale = Sale::query()->firstOrFail();

        $this->from(route('kasir.detail', $sale))
            ->post(route('kasir.cancel', $sale), ['reason' => 'Transaksi tercatat ganda'])
            ->assertRedirect(route('kasir.detail', $sale))
            ->assertSessionHasErrors('refund_confirmed');

        $this->assertNull($sale->fresh()->canceled_at);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 9]);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_owner_can_record_refund_for_a_previously_cancelled_sale_only_once(): void
    {
        $this->travelTo('2026-10-19 12:00:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $product = $this->product('Buku', 10, 1000, 500);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 2000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));
        $sale = Sale::query()->firstOrFail();
        $sale->update([
            'canceled_by' => $owner->id,
            'canceled_at' => now(),
            'cancellation_reason' => 'Pembatalan lama sebelum pencatatan refund tersedia',
        ]);

        $this->post(route('kasir.refund', $sale), ['refund_confirmed' => '1'])
            ->assertRedirect(route('kasir.detail', $sale))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cash_entries', [
            'sale_id' => $sale->id,
            'type' => 'keluar',
            'amount' => 1000,
        ]);
        $this->get(route('kasir.detail', $sale))
            ->assertOk()
            ->assertSee('Refund Rp 1.000 sudah dicatat sebagai kas keluar');

        $this->from(route('kasir.detail', $sale))
            ->post(route('kasir.refund', $sale), ['refund_confirmed' => '1'])
            ->assertRedirect(route('kasir.detail', $sale))
            ->assertSessionHasErrors('sale');
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_cashier_cannot_cancel_sale(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $product = $this->product('Buku', 10, 10000, 5000);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 10000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'cashier']))
            ->post(route('kasir.cancel', $sale), ['reason' => 'Pembatalan tanpa izin'])
            ->assertForbidden();

        $this->assertNull($sale->fresh()->canceled_at);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 9]);
        $this->assertDatabaseCount('product_stock_movements', 1);
    }

    public function test_sale_uses_the_lowest_eligible_promo_or_wholesale_price(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Pulpen', 50, 3000, 1800);
        $product->update([
            'wholesale_price' => 2500,
            'wholesale_min_qty' => 12,
            'promo_price' => 2700,
        ]);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'transfer',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 12]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(30000, $sale->subtotal);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'unit_price' => 2500,
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 38]);
    }

    public function test_promo_price_applies_when_wholesale_minimum_is_not_met(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Map', 20, 3000, 1500);
        $product->update([
            'wholesale_price' => 2400,
            'wholesale_min_qty' => 10,
            'promo_price' => 2700,
        ]);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'qris',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(2700, $sale->total);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'unit_price' => 2700,
        ]);
    }

    public function test_pos_product_card_displays_regular_price_and_labels_wholesale_separately(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Pulpen', 50, 3000, 1800);
        $product->update([
            'wholesale_price' => 2500,
            'wholesale_min_qty' => 12,
        ]);

        $this->get(route('kasir'))
            ->assertOk()
            ->assertSee('Rp 3.000')
            ->assertSee('Grosir ≥ 12: Rp 2.500')
            ->assertSee('name="submission_token"', false);
    }

    public function test_percentage_discount_is_capped_and_cash_change_is_calculated(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Buku', 5, 10000, 5000);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 25000,
            'discount_type' => 'persen',
            'discount_value' => 10,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(20000, $sale->subtotal);
        $this->assertSame(2000, $sale->discount_amount);
        $this->assertSame(18000, $sale->total);
        $this->assertSame(7000, $sale->change_amount);
    }

    public function test_cash_tip_is_saved_separately_and_refunded_with_the_sale(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $product = $this->product('Buku', 5, 1000, 500);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 2000,
            'tip_amount' => 500,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(1000, $sale->total);
        $this->assertSame(500, $sale->tip_amount);
        $this->assertSame(500, $sale->change_amount);
        $this->get(route('kasir'))
            ->assertOk()
            ->assertSee('Tip dari uang lebih')
            ->assertSee('Jadikan sisa tip');
        $this->get(route('kasir.detail', $sale))
            ->assertOk()
            ->assertSee('Tip');
        $this->get(route('kasir.receipt', $sale))
            ->assertOk()
            ->assertSee('Tip')
            ->assertSee('Rp 500');

        $this->post(route('kasir.cancel', $sale), [
            'reason' => 'Tip diminta kembali',
            'refund_confirmed' => '1',
        ])->assertRedirect(route('kasir.detail', $sale));

        $this->assertDatabaseHas('cash_entries', [
            'sale_id' => $sale->id,
            'type' => 'keluar',
            'amount' => 1500,
        ]);
    }

    public function test_cash_tip_cannot_exceed_the_change_available(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Buku', 5, 1000, 500);

        $this->from(route('kasir'))->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 1500,
            'tip_amount' => 501,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHasErrors([
                'tip_amount' => 'Tip tidak boleh melebihi uang lebih yang diterima.',
            ]);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_non_cash_sale_cannot_include_a_tip(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Buku', 5, 1000, 500);

        $this->from(route('kasir'))->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'qris',
            'tip_amount' => 500,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHasErrors([
                'tip_amount' => 'Tip hanya dapat ditambahkan pada pembayaran tunai.',
            ]);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_sale_rejects_cash_underpayment_without_saving_or_decreasing_stock(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Buku', 5, 10000, 5000);

        $this->from(route('kasir'))->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 9999,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHasErrors('amount_received');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 5]);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }

    public function test_percentage_discount_above_one_hundred_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Buku', 5, 10000, 5000);

        $this->from(route('kasir'))->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'transfer',
            'discount_type' => 'persen',
            'discount_value' => 101,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHasErrors('discount_value');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 5]);
    }

    public function test_sale_rejects_quantity_above_stock_and_services_remain_untracked(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Pulpen', 2, 3000, 1800);
        $service = $this->product('Scan Dokumen', 0, 2000, 0, false);

        $this->from(route('kasir'))->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'qris',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
                ['product_id' => $service->id, 'quantity' => 1],
            ],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHasErrors('items.0.quantity');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 2]);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }

    public function test_sales_history_and_receipt_show_saved_transaction(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Cetak Foto 4R', 0, 3000, 1500, false);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'customer_name' => 'Siti Aminah',
            'payment_method' => 'qris',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->get(route('kasir', ['q' => 'Siti Aminah']))
            ->assertOk()
            ->assertSee($sale->transaction_number)
            ->assertSee('Siti Aminah')
            ->assertSee('Rp 6.000');
        $this->get(route('kasir.detail', [
            'sale' => $sale,
            'sales_q' => 'Siti Aminah',
            'sales_date' => today()->toDateString(),
            'sales_page' => 2,
        ]))
            ->assertOk()
            ->assertSee('Detail Transaksi')
            ->assertSee('Cetak Foto 4R')
            ->assertSee('2 lembar')
            ->assertSee('Rp 3.000')
            ->assertSee('Rp 6.000')
            ->assertSee('Siti Aminah')
            ->assertSee(route('kasir', [
                'sales_q' => 'Siti Aminah',
                'sales_date' => today()->toDateString(),
                'sales_page' => 2,
            ]));
        $this->get(route('kasir.receipt', $sale))
            ->assertOk()
            ->assertSee('Cetak Foto 4R')
            ->assertSee('Siti Aminah')
            ->assertSee($sale->transaction_number);
    }

    public function test_pos_pagination_shows_page_numbers_without_previous_or_next_controls(): void
    {
        $this->actingAs(User::factory()->create());

        for ($productNumber = 1; $productNumber <= 19; $productNumber++) {
            $this->product("Produk {$productNumber}", 1, 1000, 500);
        }

        $this->get(route('kasir'))
            ->assertOk()
            ->assertSee('aria-label="Go to page 2"', false)
            ->assertSee('Tambah')
            ->assertDontSee('rel="prev"', false)
            ->assertDontSee('rel="next"', false);

        $this->get(route('kasir', ['page' => 2]))
            ->assertOk()
            ->assertSee('aria-label="Go to page 1"', false)
            ->assertDontSee('rel="prev"', false)
            ->assertDontSee('rel="next"', false);
    }

    public function test_other_paginated_pages_also_omit_previous_and_next_controls(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $category = ProductCategory::create(['name' => 'ATK']);

        for ($productNumber = 1; $productNumber <= 13; $productNumber++) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Produk Etalase {$productNumber}",
                'unit' => 'pcs',
                'tracks_stock' => true,
                'sale_price' => 1000,
                'cost_price' => 500,
                'stock_qty' => 1,
                'minimum_stock' => 0,
                'is_active' => true,
            ]);
        }

        $this->get(route('etalase'))
            ->assertOk()
            ->assertSee('aria-label="Go to page 2"', false)
            ->assertDontSee('rel="prev"', false)
            ->assertDontSee('rel="next"', false);
    }

    public function test_sale_endpoints_require_authentication(): void
    {
        $this->get(route('kasir'))->assertRedirect(route('login'));
        $this->post(route('kasir.store'), [])->assertRedirect(route('login'));
    }

    public function test_sale_cannot_delete_products_that_have_transaction_history(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product('Map', 5, 2500, 1500);

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'transfer',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->delete(route('etalase.destroy', $product))
            ->assertRedirect(route('etalase'))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('sales', ['id' => Sale::query()->value('id')]);
    }

    private function product(
        string $name,
        int $stock,
        int $salePrice,
        int $costPrice,
        bool $tracksStock = true,
    ): Product {
        $category = ProductCategory::query()->firstOrCreate(['name' => 'Kasir']);

        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'unit' => $tracksStock ? 'pcs' : 'lembar',
            'tracks_stock' => $tracksStock,
            'sale_price' => $salePrice,
            'cost_price' => $costPrice,
            'stock_qty' => $stock,
            'minimum_stock' => 0,
            'is_active' => true,
        ]);
    }
}
