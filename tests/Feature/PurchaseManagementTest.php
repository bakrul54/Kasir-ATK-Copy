<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_records_receipt_and_adds_to_stock_atomically(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::create(['name' => 'CV Sinar Kertas']);
        $product = $this->product('HVS A4', 10);
        $secondProduct = $this->product('Pulpen', 4);
        $this->actingAs($user);

        $this->post(route('pembelian.store'), [
            'supplier_id' => $supplier->id,
            'invoice_number' => 'NB-8812',
            'purchase_date' => '2026-10-13',
            'payment_status' => 'hutang',
            'payment_account' => 'non_tunai',
            'notes' => 'Pembelian stok mingguan',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 46000],
                ['product_id' => $secondProduct->id, 'quantity' => 2, 'unit_cost' => 45500],
            ],
            'total' => 1,
        ])->assertRedirect(route('pembelian'));

        $purchase = Purchase::query()->firstOrFail();
        $this->assertSame('PB-20261013-000001', $purchase->purchase_number);
        $this->assertSame(321000, $purchase->total);
        $this->assertSame('hutang', $purchase->payment_status);
        $this->assertSame('non_tunai', $purchase->payment_account);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 15]);
        $this->assertDatabaseCount('purchase_items', 2);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'type' => 'masuk',
            'quantity' => 5,
            'stock_before' => 10,
            'stock_after' => 15,
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'cost_price' => 46000]);
        $this->assertDatabaseHas('products', ['id' => $secondProduct->id, 'cost_price' => 45500]);
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $secondProduct->id,
            'old_prices->cost_price' => 1800,
            'new_prices->cost_price' => 45500,
        ]);
    }

    public function test_purchase_rejects_an_unknown_payment_account(): void
    {
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::create(['name' => 'CV Sinar Kertas']);
        $product = $this->product('HVS A4', 10);

        $this->from(route('pembelian'))->post(route('pembelian.store'), [
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'payment_account' => 'rekening-tidak-dikenal',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ])->assertRedirect(route('pembelian'))
            ->assertSessionHasErrors(['payment_account']);

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_purchase_history_lists_saved_purchase_and_details(): void
    {
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::create(['name' => 'Toko ATK Makmur']);
        $product = $this->product('Pulpen', 4);

        $this->post(route('pembelian.store'), [
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-123',
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 18000],
            ],
        ])->assertRedirect(route('pembelian'));

        $purchase = Purchase::query()->firstOrFail();
        $this->get(route('pembelian'))
            ->assertOk()
            ->assertSee($purchase->purchase_number)
            ->assertSee('Toko ATK Makmur')
            ->assertSee('Rp 36.000');
        $this->get(route('pembelian.show', $purchase))
            ->assertOk()
            ->assertSee('Pulpen')
            ->assertSee('INV-123');
    }

    public function test_duplicate_invoice_for_the_same_supplier_is_rejected_without_receiving_stock(): void
    {
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::create(['name' => 'CV Sinar Kertas']);
        $product = $this->product('HVS A4', 10);

        $payload = [
            'supplier_id' => $supplier->id,
            'invoice_number' => 'NB-8812',
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 46000],
            ],
        ];

        $this->post(route('pembelian.store'), $payload)->assertRedirect(route('pembelian'));
        $this->from(route('pembelian'))->post(route('pembelian.store'), $payload)
            ->assertRedirect(route('pembelian'))
            ->assertSessionHasErrors('invoice_number');

        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 15]);
        $this->assertDatabaseCount('product_stock_movements', 1);
    }

    public function test_purchase_rejects_services_and_duplicate_product_lines(): void
    {
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::create(['name' => 'Supplier']);
        $product = $this->product('Pulpen', 10);
        $service = Product::create([
            'category_id' => ProductCategory::create(['name' => 'Jasa'])->id,
            'name' => 'Fotocopy',
            'unit' => 'lembar',
            'tracks_stock' => false,
            'sale_price' => 300,
            'cost_price' => 100,
            'is_active' => true,
        ]);

        $this->from(route('pembelian'))->post(route('pembelian.store'), [
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100],
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100],
            ],
        ])->assertRedirect(route('pembelian'))
            ->assertSessionHasErrors(['items.0.product_id', 'items.1.product_id']);

        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 10]);
        $this->assertDatabaseCount('product_stock_movements', 0);

        $this->from(route('pembelian'))->post(route('pembelian.store'), [
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'items' => [
                ['product_id' => $service->id, 'quantity' => 2, 'unit_cost' => 100],
            ],
        ])->assertRedirect(route('pembelian'))
            ->assertSessionHasErrors('items.0.product_id');

        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }

    public function test_supplier_can_be_added_from_the_purchase_module(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('pembelian.suppliers.store'), [
            'name' => 'CV Baru',
            'phone' => '08123456789',
            'address' => 'Jakarta',
        ])->assertRedirect(route('pembelian'));

        $this->assertDatabaseHas('suppliers', [
            'name' => 'CV Baru',
            'phone' => '08123456789',
            'address' => 'Jakarta',
            'is_active' => true,
        ]);
    }

    public function test_purchase_endpoints_require_authentication(): void
    {
        $this->get(route('pembelian'))->assertRedirect(route('login'));
        $this->post(route('pembelian.store'), [])->assertRedirect(route('login'));
    }

    public function test_purchase_receipt_photo_is_saved_to_public_storage(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::create(['name' => 'Supplier']);
        $product = $this->product('Pulpen', 10);

        $this->post(route('pembelian.store'), [
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
            'receipt_photo' => UploadedFile::fake()->image('nota.png'),
        ])->assertRedirect(route('pembelian'));

        $purchase = Purchase::query()->firstOrFail();
        $this->assertNotNull($purchase->receipt_path);
        Storage::disk('public')->assertExists($purchase->receipt_path);
    }

    public function test_purchase_cannot_exceed_available_stock_capacity(): void
    {
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::create(['name' => 'Supplier']);
        $product = $this->product('Pulpen', 4294967295);

        $this->from(route('pembelian'))->post(route('pembelian.store'), [
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-10-13',
            'payment_status' => 'lunas',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100],
            ],
        ])->assertRedirect(route('pembelian'))
            ->assertSessionHasErrors('items.0.quantity');

        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_qty' => 4294967295]);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }

    private function product(string $name, int $stock): Product
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
