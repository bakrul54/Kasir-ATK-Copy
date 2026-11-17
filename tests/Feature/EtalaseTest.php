<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EtalaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_products_can_be_created_listed_and_filtered(): void
    {
        $category = ProductCategory::create(['name' => 'ATK']);

        $this->post(route('etalase.store'), [
            'name' => 'Pulpen',
            'category_id' => $category->id,
            'sku' => 'ATK-001',
            'unit' => 'pcs',
            'tracks_stock' => '1',
            'sale_price' => 3000,
            'cost_price' => 1800,
            'stock_qty' => 12,
            'minimum_stock' => 5,
            'wholesale_price' => 2500,
            'wholesale_min_qty' => 12,
            'promo_price' => 2700,
            'is_active' => '1',
        ])->assertRedirect(route('etalase'));

        $product = Product::query()->firstOrFail();
        $this->assertDatabaseHas('products', [
            'name' => 'Pulpen',
            'category_id' => $category->id,
            'stock_qty' => 12,
            'promo_price' => 2700,
        ]);
        $this->assertDatabaseHas('product_stock_movements', [
            'type' => 'opname',
            'quantity' => 12,
            'stock_before' => 0,
            'stock_after' => 12,
            'notes' => 'Stok awal saat produk dibuat.',
        ]);

        $this->get(route('etalase', ['q' => 'ATK-001', 'status' => 'promo']))
            ->assertOk()
            ->assertSee('Pulpen')
            ->assertSee('Rp 2.700');

        $this->get(route('etalase', ['status' => 'menipis']))
            ->assertOk()
            ->assertDontSee('Pulpen');

        $this->assertSame('tersedia', $product->availability());
    }

    public function test_products_can_be_updated_and_deleted(): void
    {
        $category = ProductCategory::create(['name' => 'ATK']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Pensil',
            'sku' => 'ATK-002',
            'unit' => 'pcs',
            'tracks_stock' => true,
            'sale_price' => 2000,
            'cost_price' => 1000,
            'stock_qty' => 20,
            'minimum_stock' => 5,
            'is_active' => true,
        ]);

        $this->put(route('etalase.update', $product), [
            'name' => 'Pensil 2B',
            'category_id' => $category->id,
            'sku' => 'ATK-002',
            'unit' => 'pcs',
            'tracks_stock' => '1',
            'sale_price' => 2500,
            'cost_price' => 1200,
            'minimum_stock' => 5,
            'is_active' => '1',
        ])->assertRedirect(route('etalase'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Pensil 2B',
            'stock_qty' => 20,
        ]);
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id,
            'old_prices->sale_price' => 2000,
            'new_prices->sale_price' => 2500,
        ]);

        $this->assertSame('tersedia', $product->fresh()->availability());

        $this->delete(route('etalase.destroy', $product))
            ->assertRedirect(route('etalase'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_price_histories', ['product_id' => $product->id]);
    }

    public function test_updating_product_without_a_wholesale_price_clears_its_minimum_quantity(): void
    {
        $category = ProductCategory::create(['name' => 'ATK']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Pulpen',
            'sku' => 'ATK-003',
            'unit' => 'pcs',
            'tracks_stock' => true,
            'sale_price' => 3000,
            'cost_price' => 1800,
            'stock_qty' => 12,
            'minimum_stock' => 5,
            'wholesale_price' => 2500,
            'wholesale_min_qty' => 12,
            'is_active' => true,
        ]);

        $this->put(route('etalase.update', $product), [
            'name' => 'Pulpen',
            'category_id' => $category->id,
            'sku' => 'ATK-003',
            'unit' => 'pcs',
            'tracks_stock' => '1',
            'sale_price' => 3000,
            'cost_price' => 1800,
            'minimum_stock' => 5,
            'wholesale_price' => '',
            'wholesale_min_qty' => 12,
            'is_active' => '1',
        ])->assertRedirect(route('etalase'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'wholesale_price' => null,
            'wholesale_min_qty' => null,
        ]);
    }

    public function test_service_products_do_not_require_stock_and_categories_in_use_cannot_be_deleted(): void
    {
        $category = ProductCategory::create(['name' => 'Fotocopy']);

        $this->post(route('etalase.store'), [
            'name' => 'Fotocopy A4 BW',
            'category_id' => $category->id,
            'unit' => 'lembar',
            'tracks_stock' => '0',
            'sale_price' => 300,
            'cost_price' => 100,
            'is_active' => '1',
        ])->assertRedirect(route('etalase'));

        $this->assertDatabaseHas('products', [
            'name' => 'Fotocopy A4 BW',
            'tracks_stock' => false,
            'stock_qty' => 0,
        ]);

        $this->delete(route('etalase.categories.destroy', $category))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('product_categories', ['id' => $category->id]);
    }

    public function test_product_photo_is_stored_on_public_disk(): void
    {
        Storage::fake('public');
        $category = ProductCategory::create(['name' => 'Cetak Foto']);

        $this->post(route('etalase.store'), [
            'name' => 'Cetak Foto 4R',
            'category_id' => $category->id,
            'unit' => 'lembar',
            'tracks_stock' => '0',
            'sale_price' => 3000,
            'cost_price' => 1500,
            'is_active' => '1',
            'photo' => UploadedFile::fake()->image('foto.png'),
        ])->assertRedirect(route('etalase'));

        $product = Product::query()->firstOrFail();
        $this->assertNotNull($product->photo_path);
        Storage::disk('public')->assertExists($product->photo_path);
    }

    public function test_existing_stock_cannot_be_changed_from_the_product_form(): void
    {
        $category = ProductCategory::create(['name' => 'ATK']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Pulpen',
            'unit' => 'pcs',
            'tracks_stock' => true,
            'sale_price' => 3000,
            'cost_price' => 1800,
            'stock_qty' => 20,
            'minimum_stock' => 5,
            'is_active' => true,
        ]);

        $this->put(route('etalase.update', $product), [
            'name' => 'Pulpen',
            'category_id' => $category->id,
            'unit' => 'pcs',
            'tracks_stock' => '1',
            'sale_price' => 3000,
            'cost_price' => 1800,
            'stock_qty' => 2,
            'minimum_stock' => 5,
            'is_active' => '1',
        ])->assertSessionHasErrors('stock_qty');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 20]);
    }

    public function test_stock_tracking_cannot_be_disabled_while_product_has_stock(): void
    {
        $category = ProductCategory::create(['name' => 'ATK']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Pulpen',
            'unit' => 'pcs',
            'tracks_stock' => true,
            'sale_price' => 3000,
            'cost_price' => 1800,
            'stock_qty' => 20,
            'minimum_stock' => 5,
            'is_active' => true,
        ]);

        $this->put(route('etalase.update', $product), [
            'name' => 'Pulpen',
            'category_id' => $category->id,
            'unit' => 'pcs',
            'tracks_stock' => '0',
            'sale_price' => 3000,
            'cost_price' => 1800,
            'minimum_stock' => 0,
            'is_active' => '1',
        ])->assertSessionHasErrors('tracks_stock');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'tracks_stock' => true,
            'stock_qty' => 20,
        ]);
    }
}
