<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use App\Models\Pista;
use App\Models\Serie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La pantalla de Descargas y lo que necesita del servidor.
 *
 * Casi nada, a propósito: la lista de lo bajado vive en la caché del navegador,
 * porque lo que guardaste en el teléfono no está en la computadora y el servidor
 * no tiene forma de saberlo. Del servidor sólo sale el repuesto para las pistas
 * que se bajaron antes de que guardáramos la ficha junto al audio.
 */
class DescargasTest extends TestCase
{
    use RefreshDatabase;

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->raiz = sys_get_temp_dir().'/dharmify-descargas-'.uniqid();
        @mkdir($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden', 0777, true);
        file_put_contents($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden/01 Primera charla.mp3', 'x');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->raiz)) {
            $archivos = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->raiz, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($archivos as $a) {
                $a->isDir() ? rmdir($a->getPathname()) : unlink($a->getPathname());
            }

            rmdir($this->raiz);
        }

        parent::tearDown();
    }

    private function sembrar(string $visibilidad = Fuente::VISIBILIDAD_PRIVADA): Pista
    {
        $fuente = Fuente::create([
            'nombre' => 'De prueba',
            'tipo' => Fuente::TIPO_LOCAL,
            'ruta' => $this->raiz,
            'visibilidad' => $visibilidad,
            'activa' => true,
        ]);

        app(EscanearFuente::class)($fuente);

        return Pista::firstOrFail();
    }

    public function test_la_pantalla_abre(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)->get('/descargas')->assertOk();
    }

    public function test_sin_sesion_no_se_entra(): void
    {
        $this->get('/descargas')->assertRedirect('/login');
    }

    public function test_devuelve_la_ficha_de_las_pistas_pedidas(): void
    {
        $pista = $this->sembrar();
        $pista->forceFill(['duracion_seg' => 3600])->save();

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)
            ->getJson("/api/pistas/metadatos?ids={$pista->id}")
            ->assertOk()
            ->assertJsonPath('pistas.0.id', $pista->id)
            ->assertJsonPath('pistas.0.serie', Serie::firstOrFail()->titulo)
            ->assertJsonPath('pistas.0.duracion_seg', 3600);
    }

    /**
     * La ficha lleva el título de la serie y su carátula, así que filtrar por
     * visibilidad no es una formalidad: sin esto, quien fue invitado podría
     * enumerar la biblioteca privada pidiendo ids al azar.
     */
    public function test_no_devuelve_fichas_de_una_fuente_que_no_se_puede_ver(): void
    {
        $pista = $this->sembrar(Fuente::VISIBILIDAD_PRIVADA);
        $invitado = User::factory()->create(['rol' => User::ROL_INVITADO]);

        $this->actingAs($invitado)
            ->getJson("/api/pistas/metadatos?ids={$pista->id}")
            ->assertOk()
            ->assertJsonCount(0, 'pistas');
    }

    public function test_aguanta_una_lista_de_ids_con_basura(): void
    {
        $pista = $this->sembrar();
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)
            ->getJson("/api/pistas/metadatos?ids=,,abc,{$pista->id},999999,")
            ->assertOk()
            ->assertJsonCount(1, 'pistas');
    }
}
