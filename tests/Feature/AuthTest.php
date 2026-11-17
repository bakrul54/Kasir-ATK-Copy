<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_owner_can_be_created_once_and_is_logged_in(): void
    {
        $this->get(route('login'))
            ->assertRedirect(route('setup'));

        $this->get(route('setup'))->assertOk();

        $this->post(route('setup.store'), [
            'name' => 'Pemilik Spot Print',
            'email' => 'owner@spotprint.test',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'owner@spotprint.test']);
        $this->assertTrue(Hash::check('secure-password', User::query()->firstOrFail()->password));

        $this->get(route('setup'))->assertRedirect(route('login'));
    }

    public function test_owner_can_log_in_and_log_out(): void
    {
        User::factory()->create([
            'email' => 'owner@spotprint.test',
            'password' => 'secure-password',
        ]);

        $this->post(route('login.store'), [
            'email' => 'owner@spotprint.test',
            'password' => 'secure-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->get(route('etalase'))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_failed_login_does_not_authenticate_and_admin_pages_require_login(): void
    {
        User::factory()->create([
            'email' => 'owner@spotprint.test',
            'password' => 'secure-password',
        ]);

        $this->post(route('login.store'), [
            'email' => 'owner@spotprint.test',
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->get(route('etalase'))->assertRedirect(route('login'));
    }
}
