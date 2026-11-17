<?php

namespace Tests\Feature;

use App\Models\CashierShift;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserCashierTest extends TestCase
{
    use RefreshDatabase;

    private const SUBMISSION_TOKEN = '00000000-0000-4000-8000-000000000001';

    public function test_owner_can_create_and_update_staff_accounts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $this->post(route('pengguna.store'), [
            'name' => 'Dewi Kasir',
            'email' => 'dewi@example.test',
            'role' => 'cashier',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect(route('pengguna'))
            ->assertSessionHas('success');

        $cashier = User::query()->where('email', 'dewi@example.test')->firstOrFail();
        $this->assertSame('cashier', $cashier->role);
        $this->assertTrue(Hash::check('password-123', $cashier->password));

        $this->put(route('pengguna.update', $cashier), [
            'name' => 'Dewi Admin',
            'email' => 'dewi@example.test',
            'role' => 'admin',
            'is_active' => '1',
        ])->assertRedirect(route('pengguna'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $cashier->id,
            'name' => 'Dewi Admin',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.updated',
            'subject_id' => $cashier->id,
        ]);

        $this->get(route('pengguna'))
            ->assertOk()
            ->assertSee('Riwayat Shift Kasir')
            ->assertSee('Riwayat Aktivitas');
    }

    public function test_owner_cannot_deactivate_self_or_last_active_owner(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);

        $this->from(route('pengguna'))->put(route('pengguna.update', $owner), [
            'name' => $owner->name,
            'email' => $owner->email,
            'role' => 'owner',
        ])->assertRedirect(route('pengguna'))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'is_active' => true, 'role' => 'owner']);
    }

    public function test_admin_cannot_manage_accounts_and_cashier_only_reaches_pos_and_own_shift(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->get(route('pengguna'))->assertForbidden();

        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier);
        $this->get(route('kasir'))->assertOk()->assertDontSee('data-cost-price=');
        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('etalase'))->assertForbidden();
        $this->get(route('keuangan'))->assertForbidden();
        $this->get(route('laporan'))->assertForbidden();
        $this->get(route('pengguna'))->assertForbidden();
    }

    public function test_admin_can_access_operational_modules_but_not_owner_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('etalase'))->assertOk();
        $this->get(route('harga'))->assertOk();
        $this->get(route('stok'))->assertOk();
        $this->get(route('pembelian'))->assertOk();
        $this->get(route('keuangan'))->assertOk();
        $this->get(route('pelanggan'))->assertOk();
        $this->get(route('laporan'))->assertOk();
        $this->get(route('pengaturan'))->assertForbidden();
    }

    public function test_cashier_must_open_shift_before_recording_sale(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier);
        $product = $this->product();

        $this->from(route('kasir'))->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 10000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'))
            ->assertSessionHasErrors('shift');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_cashier_shift_reconciles_expected_cash_and_records_activity(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier);

        $this->post(route('kasir.shifts.store'), ['opening_cash' => 200000])
            ->assertRedirect(route('kasir'))
            ->assertSessionHas('success');

        $shift = CashierShift::query()->firstOrFail();
        $this->assertSame(200000, $shift->opening_cash);
        $this->assertTrue($shift->isOpen());

        $product = $this->product();
        $this->post(route('kasir.store'), [
            'submission_token' => self::SUBMISSION_TOKEN,
            'payment_method' => 'cash',
            'amount_received' => 45500,
            'tip_amount' => 500,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect(route('kasir'));
        $sale = Sale::query()->firstOrFail();
        $this->assertSame($shift->id, $sale->cashier_shift_id);

        $this->post(route('kasir.shifts.close'), ['closing_cash' => 240000])
            ->assertRedirect(route('kasir'))
            ->assertSessionHas('success');

        $shift->refresh();
        $this->assertFalse($shift->isOpen());
        $this->assertSame(245500, $shift->expected_cash);
        $this->assertSame(240000, $shift->closing_cash);
        $this->assertSame(-5500, $shift->cash_difference);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $cashier->id,
            'action' => 'shift.closed',
            'subject_id' => $shift->id,
        ]);
    }

    public function test_cashier_cannot_open_a_second_shift_or_close_another_users_shift(): void
    {
        $firstCashier = User::factory()->create(['role' => 'cashier']);
        $secondCashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($firstCashier);
        $this->post(route('kasir.shifts.store'), ['opening_cash' => 100000])
            ->assertRedirect(route('kasir'));

        $this->from(route('kasir'))->post(route('kasir.shifts.store'), ['opening_cash' => 50000])
            ->assertRedirect(route('kasir'))
            ->assertSessionHasErrors('shift');
        $this->assertDatabaseCount('cashier_shifts', 1);

        $this->actingAs($secondCashier);
        $this->post(route('kasir.shifts.close'), ['closing_cash' => 100000])
            ->assertRedirect(route('kasir'))
            ->assertSessionHasErrors('shift');

        $this->assertDatabaseHas('cashier_shifts', ['user_id' => $firstCashier->id, 'closed_at' => null]);
    }

    public function test_owner_cannot_disable_or_change_role_of_user_with_open_shift(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $cashier = User::factory()->create(['role' => 'cashier']);
        CashierShift::create([
            'user_id' => $cashier->id,
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);
        $this->actingAs($owner);

        $this->from(route('pengguna'))->put(route('pengguna.update', $cashier), [
            'name' => $cashier->name,
            'email' => $cashier->email,
            'role' => 'admin',
            'is_active' => '1',
        ])->assertRedirect(route('pengguna'))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', [
            'id' => $cashier->id,
            'role' => 'cashier',
            'is_active' => true,
        ]);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_cashier_login_redirects_to_pos_not_admin_dashboard(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->post(route('login.store'), [
            'email' => $cashier->email,
            'password' => 'password',
        ])->assertRedirect(route('kasir'));

        $this->assertAuthenticatedAs($cashier);
    }

    public function test_cashier_cannot_read_another_users_transaction_details_or_receipt(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $sale = Sale::create([
            'transaction_number' => 'TRX-PRIVATE-1',
            'user_id' => $owner->id,
            'subtotal' => 10000,
            'discount_type' => 'nominal',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total' => 10000,
            'payment_method' => 'cash',
            'amount_received' => 10000,
            'change_amount' => 0,
        ]);

        $this->actingAs($cashier)
            ->get(route('kasir.detail', $sale))
            ->assertNotFound();

        $this->actingAs($cashier)
            ->get(route('kasir.receipt', $sale))
            ->assertNotFound();
    }

    public function test_management_and_shift_endpoints_require_authentication(): void
    {
        $this->get(route('pengguna'))->assertRedirect(route('login'));
        $this->post(route('pengguna.store'), [])->assertRedirect(route('login'));
        $this->post(route('kasir.shifts.store'), [])->assertRedirect(route('login'));
        $this->post(route('kasir.shifts.close'), [])->assertRedirect(route('login'));
    }

    private function product(): Product
    {
        return Product::create([
            'category_id' => ProductCategory::create(['name' => 'ATK'])->id,
            'name' => 'Buku Tulis',
            'unit' => 'pcs',
            'tracks_stock' => false,
            'sale_price' => 45000,
            'cost_price' => 20000,
            'is_active' => true,
        ]);
    }
}
