<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Tests\TestCase;

/**
 * Fija el parche de HandleInertiaRequests contra el bug del JSON crudo en
 * pantalla. Las dos mitades importan y la segunda es la que alguien va a querer
 * "simplificar" en seis meses.
 */
class CacheDeInertiaTest extends TestCase
{
    /** Sin esto Inertia contesta 409 y el test parece roto sin estarlo. */
    private function versionDeInertia(): string
    {
        return (string) app(HandleInertiaRequests::class)->version(request());
    }

    public function test_prohibe_guardar_la_respuesta_xhr_de_inertia(): void
    {
        $respuesta = $this->get('/login', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->versionDeInertia(),
        ]);

        $respuesta->assertOk();
        $this->assertStringContainsString('application/json', (string) $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
    }

    /**
     * `no-store` sobre el documento HTML mata el back/forward cache de Chrome y
     * convierte cada "atrás" en una ida completa a la red, sin ningún síntoma
     * visible. Por eso se prueba que NO esté.
     */
    public function test_deja_cacheable_el_html_para_no_perder_el_bfcache(): void
    {
        $respuesta = $this->get('/login');

        $respuesta->assertOk();
        $this->assertStringContainsString('text/html', (string) $respuesta->headers->get('Content-Type'));
        $this->assertStringNotContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
    }
}
