<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPriceHistory;
use App\Models\ProductWholesaleGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_changes_update_the_product_and_are_reflected_in_the_etalase(): void
    {
        $user = User::factory()->create(['name' => 'Pemilik']);
        $product = $this->product();
        $this->actingAs($user);

        $this->patch(route('harga.update', $product), [
            'prices' => [
                $product->id => [
                    'sale_price' => 3500,
                    'cost_price' => 1900,
                    'wholesale_price' => 3000,
                    'wholesale_min_qty' => 10,
                    'promo_price' => 3200,
                ],
            ],
        ])->assertRedirect(route('harga'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'sale_price' => 3500,
            'cost_price' => 1900,
            'wholesale_price' => 3000,
            'wholesale_min_qty' => 10,
            'promo_price' => 3200,
        ]);
        $this->assertDatabaseCount('product_price_histories', 1);

        $history = ProductPriceHistory::query()->firstOrFail();
        $this->assertSame([
            'sale_price' => 3000,
            'cost_price' => 1800,
            'wholesale_price' => 2500,
            'wholesale_min_qty' => 12,
            'promo_price' => null,
        ], $history->old_prices);
        $this->assertSame([
            'sale_price' => 3500,
            'cost_price' => 1900,
            'wholesale_price' => 3000,
            'wholesale_min_qty' => 10,
            'promo_price' => 3200,
        ], $history->new_prices);
        $this->assertSame($user->id, $history->changed_by);

        $this->get(route('etalase'))
            ->assertOk()
            ->assertSee('Rp 3.200');
    }

    public function test_price_history_page_shows_changes_for_the_selected_product(): void
    {
        $user = User::factory()->create(['name' => 'Pemilik']);
        $product = $this->product();
        $this->actingAs($user);
        $this->patch(route('harga.update', $product), [
            'prices' => [
                $product->id => [
                    'sale_price' => 3500,
                    'cost_price' => 1800,
                    'wholesale_price' => 2500,
                    'wholesale_min_qty' => 12,
                    'promo_price' => null,
                ],
            ],
        ]);

        $history = ProductPriceHistory::query()->firstOrFail();
        $this->assertSame(['sale_price' => 3000], $history->old_prices);
        $this->assertSame(['sale_price' => 3500], $history->new_prices);

        $this->get(route('harga.history', $product))
            ->assertOk()
            ->assertSee('Riwayat Harga Pulpen')
            ->assertSee('Rp 3.000')
            ->assertSee('Rp 3.500')
            ->assertSee('Pemilik');
    }

    public function test_unchanged_prices_do_not_create_price_history(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->patch(route('harga.update', $product), [
            'prices' => [
                $product->id => [
                    'sale_price' => 3000,
                    'cost_price' => 1800,
                    'wholesale_price' => 2500,
                    'wholesale_min_qty' => 12,
                    'promo_price' => null,
                ],
            ],
        ])->assertRedirect(route('harga'));

        $this->assertDatabaseCount('product_price_histories', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => 3000]);
    }

    public function test_invalid_promo_price_is_rejected_without_changing_prices(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->from(route('harga'))->patch(route('harga.update', $product), [
            'prices' => [
                $product->id => [
                    'sale_price' => 3000,
                    'cost_price' => 1800,
                    'wholesale_price' => 2500,
                    'wholesale_min_qty' => 12,
                    'promo_price' => 3000,
                ],
            ],
        ])->assertRedirect(route('harga'))
            ->assertSessionHasErrors("prices.{$product->id}.promo_price");

        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => 3000, 'promo_price' => null]);
        $this->assertDatabaseCount('product_price_histories', 0);
    }

    public function test_wholesale_price_can_be_cleared_and_clears_its_minimum_quantity(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->patch(route('harga.update', $product), [
            'prices' => [
                $product->id => [
                    'sale_price' => 3000,
                    'cost_price' => 1800,
                    'wholesale_price' => null,
                    'wholesale_min_qty' => 10,
                    'promo_price' => null,
                ],
            ],
        ])->assertRedirect(route('harga'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'wholesale_price' => null,
            'wholesale_min_qty' => null,
        ]);
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id,
            'old_prices->wholesale_price' => 2500,
            'new_prices->wholesale_price' => null,
            'new_prices->wholesale_min_qty' => null,
        ]);
    }

    public function test_wholesale_group_can_be_created_updated_assigned_and_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->post(route('harga.wholesale-groups.store'), [
            'name' => 'Print Gabungan',
            'minimum_quantity' => 20,
        ])->assertRedirect(route('harga'))
            ->assertSessionHas('success');

        $group = ProductWholesaleGroup::query()->firstOrFail();
        $this->patch(route('harga.update', $product), [
            'prices' => [
                $product->id => [
                    'sale_price' => 3000,
                    'cost_price' => 1800,
                    'wholesale_price' => 2500,
                    'wholesale_min_qty' => 12,
                    'wholesale_group_id' => $group->id,
                    'promo_price' => null,
                ],
            ],
        ])->assertRedirect(route('harga'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'wholesale_group_id' => $group->id,
        ]);
        $this->assertDatabaseCount('product_price_histories', 0);

        $this->patch(route('harga.wholesale-groups.update', $group), [
            'name' => 'Print A4',
            'minimum_quantity' => 25,
        ])->assertRedirect(route('harga'))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('product_wholesale_groups', [
            'id' => $group->id,
            'name' => 'Print A4',
            'minimum_quantity' => 25,
        ]);

        $this->delete(route('harga.wholesale-groups.destroy', $group))
            ->assertRedirect(route('harga'))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'wholesale_group_id' => null,
        ]);
    }

    public function test_product_cannot_join_a_wholesale_group_with_a_different_unit(): void
    {
        $this->actingAs(User::factory()->create());
        $group = ProductWholesaleGroup::create([
            'name' => 'Print',
            'minimum_quantity' => 20,
        ]);
        $existingProduct = $this->product();
        $existingProduct->update(['wholesale_group_id' => $group->id]);
        $differentUnitProduct = Product::create([
            'category_id' => $existingProduct->category_id,
            'name' => 'Jasa Print',
            'unit' => 'lembar',
            'tracks_stock' => false,
            'sale_price' => 3000,
            'cost_price' => 1000,
            'is_active' => true,
        ]);

        $this->from(route('harga'))->patch(route('harga.update', $differentUnitProduct), [
            'prices' => [
                $differentUnitProduct->id => [
                    'sale_price' => 3000,
                    'cost_price' => 1000,
                    'wholesale_price' => 2500,
                    'wholesale_min_qty' => 10,
                    'wholesale_group_id' => $group->id,
                    'promo_price' => null,
                ],
            ],
        ])->assertRedirect(route('harga'))
            ->assertSessionHasErrors('wholesale_group_id');

        $this->assertDatabaseHas('products', [
            'id' => $differentUnitProduct->id,
            'wholesale_group_id' => null,
        ]);
    }

    public function test_price_list_can_be_searched_and_filtered_by_category(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();
        Product::create([
            'category_id' => ProductCategory::create(['name' => 'Fotocopy'])->id,
            'name' => 'Fotocopy A4',
            'unit' => 'lembar',
            'tracks_stock' => false,
            'sale_price' => 300,
            'cost_price' => 100,
            'is_active' => true,
        ]);

        $this->get(route('harga', ['q' => 'Pulpen', 'category' => $product->category_id]))
            ->assertOk()
            ->assertSee('Pulpen')
            ->assertDontSee('Fotocopy A4');
    }

    public function test_price_management_requires_authentication(): void
    {
        $product = $this->product();

        $this->get(route('harga'))->assertRedirect(route('login'));
        $this->patch(route('harga.update', $product), [
            'prices' => [
                $product->id => [
                    'sale_price' => 3500,
                    'cost_price' => 1800,
                ],
            ],
        ])->assertRedirect(route('login'));
    }

    private function product(): Product
    {
        $category = ProductCategory::create(['name' => 'ATK']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Pulpen',
            'sku' => 'ATK-001',
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
    }
}
