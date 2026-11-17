<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private const SUBMISSION_TOKEN = '00000000-0000-4000-8000-000000000001';

    public function test_customer_can_be_created_and_updated(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('pelanggan.store'), [
            'name' => 'Budi Santoso',
            'phone' => '0812-3456-7890',
        ])->assertRedirect(route('pelanggan.show', Customer::query()->firstOrFail()))
            ->assertSessionHas('success');

        $customer = Customer::query()->firstOrFail();
        $this->assertSame('Budi Santoso', $customer->name);
        $this->assertSame('0812-3456-7890', $customer->phone);

        $this->put(route('pelanggan.update', $customer), [
            'name' => 'Budi Santoso',
            'phone' => '0812-0000-0000',
        ])->assertRedirect(route('pelanggan.show', $customer))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Budi Santoso',
            'phone' => '0812-0000-0000',
        ]);
    }

    public function test_customer_rejects_invalid_whatsapp_characters(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('pelanggan'))->post(route('pelanggan.store'), [
            'name' => 'Pelanggan Baru',
            'phone' => '0812abc456',
        ])->assertRedirect(route('pelanggan'))
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_list_shows_transaction_count_spend_and_automatic_loyal_status(): void
    {
        $this->actingAs(User::factory()->create());
        $regular = Customer::create(['name' => 'Ayu Reguler']);
        $loyal = Customer::create(['name' => 'Budi Tetap']);
        $this->createSales($regular, 9, 10000);
        $this->createSales($loyal, 10, 25000);

        $this->get(route('pelanggan'))
            ->assertOk()
            ->assertSee('Ayu Reguler')
            ->assertSee('9 transaksi')
            ->assertSee('Budi Tetap')
            ->assertSee('10 transaksi')
            ->assertSee('Pelanggan Tetap')
            ->assertSee('Rp 250.000')
            ->assertViewHas('customers', fn ($customers): bool => $customers->contains(
                fn (Customer $customer): bool => $customer->id === $loyal->id
                    && $customer->sales_count === 10
                    && $customer->sales_sum_total === 250000,
            ));
    }

    public function test_customer_history_shows_linked_sales_and_whatsapp_contact(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::create([
            'name' => 'Siti Aminah',
            'phone' => '0813-1111-2222',
        ]);
        $this->createSales($customer, 1, 57000);

        $this->get(route('pelanggan.show', $customer))
            ->assertOk()
            ->assertSee('Siti Aminah')
            ->assertSee('0813-1111-2222')
            ->assertSee(sprintf('TRX-TEST-%06d', $customer->id * 100 + 1))
            ->assertSee('Rp 57.000')
            ->assertSee('https://wa.me/6281311112222', false);
    }

    public function test_customer_search_and_loyal_filter_only_show_matching_customers(): void
    {
        $this->actingAs(User::factory()->create());
        $loyal = Customer::create(['name' => 'Mitra Tetap', 'phone' => '08123456789']);
        $regular = Customer::create(['name' => 'Pelanggan Baru', 'phone' => '08987654321']);
        $this->createSales($loyal, 10, 1000);
        $this->createSales($regular, 1, 1000);

        $this->get(route('pelanggan', ['q' => '081234', 'status' => 'tetap']))
            ->assertOk()
            ->assertSee('Mitra Tetap')
            ->assertDontSee('Pelanggan Baru');
    }

    public function test_cashier_links_sale_to_selected_customer_and_keeps_name_snapshot(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::create(['name' => 'Nama Saat Ini']);
        $product = $this->product();

        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'customer_id' => $customer->id,
            'customer_name' => 'Nama transaksi',
            'payment_method' => 'qris',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));

        $sale = Sale::query()->firstOrFail();
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame('Nama Saat Ini', $sale->customer_name);

        $customer->update(['name' => 'Nama Diperbarui']);
        $this->assertSame('Nama Saat Ini', $sale->fresh()->customer_name);
    }

    public function test_cashier_rejects_an_unknown_customer_without_recording_sale(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->from(route('kasir'))->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'customer_id' => 9999,
            'payment_method' => 'qris',
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_customer_endpoints_require_authentication(): void
    {
        $this->get(route('pelanggan'))->assertRedirect(route('login'));
        $this->post(route('pelanggan.store'), [])->assertRedirect(route('login'));
    }

    private function createSales(Customer $customer, int $count, int $total): void
    {
        for ($index = 1; $index <= $count; $index++) {
            Sale::create([
                'transaction_number' => sprintf('TRX-TEST-%06d', $customer->id * 100 + $index),
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'subtotal' => $total,
                'discount_type' => 'nominal',
                'discount_value' => 0,
                'discount_amount' => 0,
                'total' => $total,
                'payment_method' => 'cash',
                'amount_received' => $total,
                'change_amount' => 0,
            ]);
        }
    }

    private function product(): Product
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
        ]);
    }
}
