<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_open_the_print_pricing_page(): void
    {
        $this->withoutVite();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $category = ProductCategory::create(['name' => 'Jasa Print']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Print Hitam Putih',
            'unit' => 'lembar',
            'tracks_stock' => false,
            'sale_price' => 1350,
            'cost_price' => 300,
            'stock_qty' => 0,
            'minimum_stock' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($cashier)
            ->get(route('print-pricing'))
            ->assertOk()
            ->assertSee('Hitung Harga Print')
            ->assertSee(route('print-pricing'), false)
            ->assertSee('application/pdf')
            ->assertSee('Masukkan hasil ke keranjang kasir')
            ->assertSee('<dialog', false)
            ->assertSee('Berapa kali file ini akan dicetak?')
            ->assertSee('print-coverage-cancel')
            ->assertSee('Konfirmasi &amp; masukkan ke keranjang', false)
            ->assertSee('css/style.css?v=', false)
            ->assertSee('print-quote:v2')
            ->assertSee('Format: PDF, JPG, JPEG, PNG, BMP, atau WebP.')
            ->assertSee('Coverage warna 2% sampai 10%')
            ->assertSee('Coverage hitam di atas 15% sampai 50%')
            ->assertSee('Coverage hitam di atas 50%')
            ->assertViewHas('printCategories', fn (array $categories): bool => $categories['hitam-putih']['product']['id'] === (string) $product->id
                && $categories['hitam-putih']['product']['salePrice'] === 1350);
    }

    public function test_guest_is_redirected_to_login_from_the_print_pricing_page(): void
    {
        $this->get(route('print-pricing'))
            ->assertRedirect(route('login'));
    }
}
