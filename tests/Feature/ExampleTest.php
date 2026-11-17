<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_uses_jakarta_as_its_timezone(): void
    {
        $this->assertSame('Asia/Jakarta', now()->timezoneName);
    }

    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')
            ->assertRedirect(route('setup'))
            ->assertSessionHasNoErrors();

        $this->get(route('setup'))->assertOk();
    }
}
