<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcercaDeTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_abre(): void
    {
        $this->actingAs(User::factory()->create(['rol' => User::ROL_INVITADO]))
            ->get('/acerca-de')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('AcercaDe'));
    }

    /**
     * A diferencia de movieboxd, donde la misma pantalla es pública: acá no hay
     * ninguna pantalla anónima, y ésta no va a ser la primera.
     */
    public function test_sin_sesion_manda_al_login(): void
    {
        $this->get('/acerca-de')->assertRedirect('/login');
    }
}
