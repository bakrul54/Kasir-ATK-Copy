<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_store_receipt_payment_and_product_preferences(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $this->put(route('pengaturan.update'), [
            'store_name' => 'Spot Print Baru',
            'address' => 'Jl. Merdeka No. 10',
            'whatsapp' => '081234567890',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'printer_paper_size' => '58mm',
            'receipt_header' => 'Selamat datang',
            'receipt_footer' => 'Terima kasih!',
            'receipt_show_logo' => '1',
            'receipt_auto_print' => '1',
            'payment_methods' => ['cash', 'qris'],
            'payment_information' => 'QRIS Spot Print',
            'wholesale_enabled' => '1',
            'promo_enabled' => '1',
            'default_minimum_stock' => '7',
            'units' => ['pcs', 'lembar', 'set'],
        ])->assertRedirect(route('pengaturan'))
            ->assertSessionHas('success');

        $settings = StoreSetting::query()->findOrFail(1);
        $this->assertSame('Spot Print Baru', $settings->store_name);
        $this->assertSame(['cash', 'qris'], $settings->payment_methods);
        $this->assertSame(['pcs', 'lembar', 'set'], $settings->units);
        $this->assertSame(7, $settings->default_minimum_stock);
        $this->assertTrue($settings->receipt_auto_print);
        $this->assertTrue($settings->receipt_show_logo);
        Storage::disk('public')->assertExists($settings->logo_path);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => auth()->id(),
            'action' => 'store_settings.updated',
        ]);

        $logoPath = $settings->logo_path;
        $this->put(route('pengaturan.update'), [
            'store_name' => $settings->store_name,
            'printer_paper_size' => $settings->printer_paper_size,
            'payment_methods' => $settings->payment_methods,
            'default_minimum_stock' => $settings->default_minimum_stock,
            'units' => $settings->units,
            'remove_logo' => '1',
        ])->assertRedirect(route('pengaturan'));

        $this->assertNull($settings->fresh()->logo_path);
        Storage::disk('public')->assertMissing($logoPath);
    }

    public function test_store_settings_are_used_by_product_forms_pos_sales_receipts_and_login(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $settings = StoreSetting::query()->findOrFail(1);
        $settings->update([
            'store_name' => 'Spot Print Depok',
            'address' => 'Jl. Raya Depok',
            'receipt_header' => 'Bukti transaksi',
            'receipt_footer' => 'Sampai jumpa',
            'receipt_auto_print' => true,
            'payment_methods' => ['cash'],
            'wholesale_enabled' => true,
            'promo_enabled' => true,
            'default_minimum_stock' => 9,
            'units' => ['pcs', 'lembar'],
        ]);
        $category = ProductCategory::create(['name' => 'ATK']);

        $this->get(route('etalase.create'))
            ->assertOk()
            ->assertSee('value="9"', false)
            ->assertSee('option value="lembar"', false);

        $this->post(route('etalase.store'), [
            'name' => 'Pulpen Promo',
            'category_id' => $category->id,
            'unit' => 'pcs',
            'tracks_stock' => '1',
            'sale_price' => 3000,
            'cost_price' => 1500,
            'stock_qty' => 20,
            'promo_price' => 2000,
            'wholesale_price' => 1800,
            'wholesale_min_qty' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('etalase'));

        $product = Product::query()->firstOrFail();
        $this->assertSame(9, $product->minimum_stock);
        $settings->update(['wholesale_enabled' => false, 'promo_enabled' => false]);

        $this->get(route('kasir'))
            ->assertOk()
            ->assertSee('value="cash"', false)
            ->assertDontSee('value="transfer"', false)
            ->assertDontSee('value="qris"', false);

        $saleInput = [
            'submission_token' => '00000000-0000-4000-8000-000000000001',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 12]],
        ];
        $this->from(route('kasir'))->post(route('kasir.store'), [
            ...$saleInput,
            'payment_method' => 'transfer',
        ])->assertRedirect(route('kasir'))
            ->assertSessionHasErrors('payment_method');

        $this->post(route('kasir.store'), [
            ...$saleInput,
            'payment_method' => 'cash',
            'amount_received' => 36000,
        ])->assertRedirect(route('kasir'))
            ->assertSessionHas('success');

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(36000, $sale->total);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'unit_price' => 3000,
        ]);
        $this->get(route('kasir.receipt', $sale))
            ->assertOk()
            ->assertSee('Spot Print Depok')
            ->assertSee('Bukti transaksi')
            ->assertSee('Sampai jumpa')
            ->assertSee('@page{size:58mm;margin:0}', false)
            ->assertSee('receipt-items-compact', false)
            ->assertDontSee('<th class="r">Harga</th>', false)
            ->assertSee('window.print()');

        $settings->update(['printer_paper_size' => '80mm']);
        $this->get(route('kasir.receipt', $sale))
            ->assertOk()
            ->assertSee('<th class="r">Harga</th>', false)
            ->assertDontSee('receipt-items-compact', false);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Spot Print Depok');
    }

    public function test_admin_cannot_view_or_update_owner_store_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('pengaturan'))->assertForbidden();
        $this->put(route('pengaturan.update'), [])->assertForbidden();
    }

    public function test_unauthenticated_users_cannot_access_store_settings(): void
    {
        $this->get(route('pengaturan'))->assertRedirect(route('login'));
        $this->put(route('pengaturan.update'), [])->assertRedirect(route('login'));
    }

    public function test_product_unit_must_be_selected_from_store_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        StoreSetting::query()->findOrFail(1)->update(['units' => ['pcs']]);
        $category = ProductCategory::create(['name' => 'ATK']);

        $this->from(route('etalase.create'))->post(route('etalase.store'), [
            'name' => 'Kertas Khusus',
            'category_id' => $category->id,
            'unit' => 'rim',
            'tracks_stock' => '1',
            'sale_price' => 1000,
            'cost_price' => 500,
            'stock_qty' => 5,
            'is_active' => '1',
        ])->assertRedirect(route('etalase.create'))
            ->assertSessionHasErrors('unit');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_invalid_payment_methods_and_units_do_not_change_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $settings = StoreSetting::query()->findOrFail(1);

        $this->from(route('pengaturan'))->put(route('pengaturan.update'), [
            'store_name' => 'Nama Salah',
            'printer_paper_size' => 'A4',
            'payment_methods' => [],
            'default_minimum_stock' => 10,
            'units' => ['pcs', ''],
        ])->assertRedirect(route('pengaturan'))
            ->assertSessionHasErrors(['payment_methods', 'units.1', 'printer_paper_size']);

        $this->assertSame('Spot Print', $settings->fresh()->store_name);
        $this->assertSame(['cash', 'transfer', 'qris'], $settings->fresh()->payment_methods);
    }
}
