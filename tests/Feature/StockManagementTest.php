<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_increases_when_stock_is_received_and_movement_is_recorded(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->actingAs($user);

        $this->post(route('stok.movements.store'), [
            'product_id' => $product->id,
            'type' => 'masuk',
            'quantity' => 10,
            'notes' => 'Belanja awal',
        ])->assertRedirect(route('stok'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 22]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'type' => 'masuk',
            'quantity' => 10,
            'stock_before' => 12,
            'stock_after' => 22,
            'notes' => 'Belanja awal',
        ]);
    }

    public function test_stock_decreases_when_stock_is_removed(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->post(route('stok.movements.store'), [
            'product_id' => $product->id,
            'type' => 'keluar',
            'quantity' => 5,
            'notes' => 'Rusak',
        ])->assertRedirect(route('stok'));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 7]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id,
            'type' => 'keluar',
            'quantity' => 5,
            'stock_before' => 12,
            'stock_after' => 7,
        ]);
    }

    public function test_stock_opname_sets_physical_count_and_records_difference(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->post(route('stok.movements.store'), [
            'product_id' => $product->id,
            'type' => 'opname',
            'quantity' => 9,
            'notes' => 'Hitung fisik rak depan',
        ])->assertRedirect(route('stok'));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 9]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id,
            'type' => 'opname',
            'quantity' => 9,
            'stock_before' => 12,
            'stock_after' => 9,
        ]);
    }

    public function test_stock_opname_can_set_stock_to_zero(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->post(route('stok.movements.store'), [
            'product_id' => $product->id,
            'type' => 'opname',
            'quantity' => 0,
            'notes' => 'Rak sudah kosong',
        ])->assertRedirect(route('stok'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 0]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id,
            'type' => 'opname',
            'quantity' => 0,
            'stock_before' => 12,
            'stock_after' => 0,
        ]);
    }

    public function test_opname_rejects_the_current_quantity_without_recording_a_movement(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->from(route('stok'))->post(route('stok.movements.store'), [
            'product_id' => $product->id,
            'type' => 'opname',
            'quantity' => 12,
        ])->assertRedirect(route('stok'))
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 12]);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }

    public function test_stock_cannot_be_removed_beyond_available_quantity(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->from(route('stok'))->post(route('stok.movements.store'), [
            'product_id' => $product->id,
            'type' => 'keluar',
            'quantity' => 13,
        ])->assertRedirect(route('stok'))
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 12]);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }

    public function test_stock_can_only_be_adjusted_for_active_stock_tracked_products(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Product::create([
            'category_id' => ProductCategory::create(['name' => 'Jasa'])->id,
            'name' => 'Fotocopy A4',
            'unit' => 'lembar',
            'tracks_stock' => false,
            'sale_price' => 300,
            'cost_price' => 100,
            'is_active' => true,
        ]);

        $this->from(route('stok'))->post(route('stok.movements.store'), [
            'product_id' => $service->id,
            'type' => 'masuk',
            'quantity' => 3,
        ])->assertRedirect(route('stok'))
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseCount('product_stock_movements', 0);
    }

    public function test_stock_page_displays_live_stock_and_supports_search_and_status_filters(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();
        $product->update(['stock_qty' => 3]);
        $this->product('HVS A4', 0);

        $this->get(route('stok', ['q' => 'Pulpen']))
            ->assertOk()
            ->assertSee('<td><strong>Pulpen</strong>', false)
            ->assertDontSee('<td><strong>HVS A4</strong>', false)
            ->assertSee('3 pcs');

        $this->get(route('stok', ['status' => 'habis']))
            ->assertOk()
            ->assertSee('<td><strong>HVS A4</strong>', false)
            ->assertDontSee('<td><strong>Pulpen</strong>', false);
    }

    public function test_products_with_stock_movements_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();
        ProductStockMovement::create([
            'product_id' => $product->id,
            'user_id' => auth()->id(),
            'type' => 'masuk',
            'quantity' => 1,
            'stock_before' => 12,
            'stock_after' => 13,
        ]);

        $this->delete(route('etalase.destroy', $product))
            ->assertRedirect(route('etalase'))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_stock_movements', ['product_id' => $product->id]);
    }

    public function test_stock_management_requires_authentication(): void
    {
        $product = $this->product();

        $this->get(route('stok'))->assertRedirect(route('login'));
        $this->post(route('stok.movements.store'), [
            'product_id' => $product->id,
            'type' => 'masuk',
            'quantity' => 2,
        ])->assertRedirect(route('login'));
    }

    private function product(string $name = 'Pulpen', int $stock = 12): Product
    {
        $category = ProductCategory::query()->firstOrCreate(['name' => 'ATK']);

        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'unit' => 'pcs',
            'tracks_stock' => true,
            'sale_price' => 3000,
            'cost_price' => 1800,
            'stock_qty' => $stock,
            'minimum_stock' => 5,
            'is_active' => true,
        ]);
    }
}
