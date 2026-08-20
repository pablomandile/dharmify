<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use App\Models\Lista;
use App\Models\Pista;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Favoritos y listas son de cada persona, no del catálogo.
 *
 * Eso es lo que estas pruebas cuidan: que nadie vea ni toque lo de otro, y que
 * el orden de una lista sea el que alguien le dio.
 */
class FavoritosYListasTest extends TestCase
{
    use RefreshDatabase;

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->raiz = sys_get_temp_dir().'/dharmify-listas-'.uniqid();
        @mkdir($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden', 0777, true);

        foreach (['01 Primera', '02 Segunda', '03 Tercera'] as $nombre) {
            file_put_contents($this->raiz."/Retiro de Vacuidad 2013 Guen Togden/{$nombre} charla.mp3", 'x');
        }
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

    private function sembrar(string $visibilidad = Fuente::VISIBILIDAD_PRIVADA): void
    {
        app(EscanearFuente::class)(Fuente::create([
            'nombre' => 'De prueba',
            'tipo' => Fuente::TIPO_LOCAL,
            'ruta' => $this->raiz,
            'visibilidad' => $visibilidad,
            'activa' => true,
        ]));
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => User::ROL_ADMIN]);
    }

    public function test_marcar_y_desmarcar_una_favorita(): void
    {
        $this->sembrar();
        $pista = Pista::firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/favoritos/{$pista->id}")->assertRedirect();
        $this->assertTrue($admin->favoritos()->where('pistas.id', $pista->id)->exists());

        // El mismo camino en los dos sentidos: el botón es uno solo.
        $this->actingAs($admin)->post("/favoritos/{$pista->id}")->assertRedirect();
        $this->assertFalse($admin->favoritos()->where('pistas.id', $pista->id)->exists());
    }

    public function test_no_se_puede_marcar_algo_de_una_fuente_que_no_se_ve(): void
    {
        $this->sembrar(Fuente::VISIBILIDAD_PRIVADA);
        $pista = Pista::firstOrFail();
        $invitado = User::factory()->create(['rol' => User::ROL_INVITADO]);

        $this->actingAs($invitado)->post("/favoritos/{$pista->id}")->assertNotFound();
        $this->assertSame(0, $invitado->favoritos()->count());
    }

    public function test_la_pantalla_de_favoritos_muestra_lo_marcado(): void
    {
        $this->sembrar();
        $admin = $this->admin();
        $admin->favoritos()->attach(Pista::firstOrFail()->id, ['created_at' => now()]);

        $this->actingAs($admin)
            ->get('/favoritos')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Favoritos')->has('pistas', 1));
    }

    public function test_crear_una_lista_y_agregarle_una_ensenanza_de_una(): void
    {
        $this->sembrar();
        $pista = Pista::firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/listas', ['nombre' => 'Para el colectivo', 'pista_id' => $pista->id])
            ->assertRedirect();

        $lista = Lista::firstOrFail();

        $this->assertSame('Para el colectivo', $lista->nombre);
        $this->assertSame($admin->id, $lista->user_id);
        $this->assertSame(1, $lista->pistas()->count());
    }

    public function test_agregar_dos_veces_la_misma_no_la_duplica(): void
    {
        $this->sembrar();
        $pista = Pista::firstOrFail();
        $admin = $this->admin();
        $lista = $admin->listas()->create(['nombre' => 'Una']);

        $this->actingAs($admin)->post("/listas/{$lista->id}/pistas", ['pista_id' => $pista->id]);
        $this->actingAs($admin)->post("/listas/{$lista->id}/pistas", ['pista_id' => $pista->id]);

        $this->assertSame(1, $lista->pistas()->count());
    }

    /**
     * El orden es lo que una lista viene a resolver: una serie de clases se
     * escucha en secuencia.
     */
    public function test_subir_una_pista_cambia_el_orden(): void
    {
        $this->sembrar();
        $admin = $this->admin();
        $lista = $admin->listas()->create(['nombre' => 'Una']);

        $pistas = Pista::orderBy('orden')->get();

        foreach ($pistas as $i => $pista) {
            $lista->pistas()->attach($pista->id, ['orden' => $i]);
        }

        $tercera = $pistas[2];

        $this->actingAs($admin)
            ->patch("/listas/{$lista->id}/pistas/{$tercera->id}", ['hacia' => 'arriba'])
            ->assertRedirect();

        $this->assertSame(
            [$pistas[0]->id, $tercera->id, $pistas[1]->id],
            $lista->pistas()->pluck('pistas.id')->all(),
        );
    }

    public function test_la_primera_no_puede_subir_mas(): void
    {
        $this->sembrar();
        $admin = $this->admin();
        $lista = $admin->listas()->create(['nombre' => 'Una']);

        $pistas = Pista::orderBy('orden')->get();

        foreach ($pistas as $i => $pista) {
            $lista->pistas()->attach($pista->id, ['orden' => $i]);
        }

        $this->actingAs($admin)
            ->patch("/listas/{$lista->id}/pistas/{$pistas[0]->id}", ['hacia' => 'arriba'])
            ->assertRedirect();

        $this->assertSame(
            $pistas->pluck('id')->all(),
            $lista->pistas()->pluck('pistas.id')->all(),
        );
    }

    /**
     * Una lista ajena da 404 y no 403: decir "existe pero no es tuya" ya es
     * contar algo de la biblioteca de otro.
     */
    public function test_una_lista_ajena_no_existe(): void
    {
        $this->sembrar();
        $ajena = User::factory()->create(['rol' => User::ROL_ADMIN])
            ->listas()->create(['nombre' => 'De otro']);

        $yo = $this->admin();

        $this->actingAs($yo)->get("/listas/{$ajena->id}")->assertNotFound();
        $this->actingAs($yo)->patch("/listas/{$ajena->id}", ['nombre' => 'Mía'])->assertNotFound();
        $this->actingAs($yo)->delete("/listas/{$ajena->id}")->assertNotFound();
        $this->actingAs($yo)
            ->post("/listas/{$ajena->id}/pistas", ['pista_id' => Pista::firstOrFail()->id])
            ->assertNotFound();

        $this->assertSame('De otro', $ajena->fresh()->nombre);
    }

    public function test_borrar_una_lista_no_toca_las_ensenanzas(): void
    {
        $this->sembrar();
        $admin = $this->admin();
        $lista = $admin->listas()->create(['nombre' => 'Una']);
        $lista->pistas()->attach(Pista::firstOrFail()->id, ['orden' => 0]);

        $this->actingAs($admin)->delete("/listas/{$lista->id}")->assertRedirect('/listas');

        $this->assertSame(0, Lista::count());
        $this->assertSame(3, Pista::count());
    }

    public function test_las_listas_y_las_carpetas_viajan_en_las_props_compartidas(): void
    {
        $this->sembrar();
        $admin = $this->admin();
        $admin->listas()->create(['nombre' => 'Para el colectivo']);

        $this->actingAs($admin)
            ->get('/biblioteca')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('listas', 1)
                ->where('listas.0.nombre', 'Para el colectivo')
                ->has('carpetas', 1),
            );
    }
}
