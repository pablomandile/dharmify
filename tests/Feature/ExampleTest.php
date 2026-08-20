<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La raíz del sitio.
 *
 * Dharmify no tiene landing: es una biblioteca privada y no hay nada que
 * mostrarle a quien todavía no entró.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_raiz_manda_al_login_a_quien_no_tiene_sesion(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_la_raiz_manda_a_la_app_a_quien_ya_entro(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->get(route('home'))
            ->assertRedirect(route('biblioteca'));
    }
}
