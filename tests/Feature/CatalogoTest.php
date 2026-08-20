<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use App\Models\Pista;
use App\Models\Serie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogoTest extends TestCase
{
    use RefreshDatabase;

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        // Una biblioteca de mentira con la misma forma que la real: carpetas de
        // primer nivel como series, y una con subcarpeta.
        $this->raiz = sys_get_temp_dir().'/dharmify-'.uniqid();

        $this->crear('Retiro de Vacuidad 2013 Guen Togden/01 Primera charla.mp3');
        $this->crear('Retiro de Vacuidad 2013 Guen Togden/02 Segunda charla.mp3');
        $this->crear('Curso Cómo morir bien 2014 Guen Rinchung/MP3/01 Clase.mp3');
        $this->crear('Curso Cómo morir bien 2014 Guen Rinchung/leeme.txt');
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

    private function crear(string $relativa): void
    {
        $ruta = $this->raiz.'/'.$relativa;
        @mkdir(dirname($ruta), 0777, true);
        file_put_contents($ruta, 'x');
    }

    private function fuente(): Fuente
    {
        return Fuente::create([
            'nombre' => 'De prueba',
            'tipo' => Fuente::TIPO_LOCAL,
            'ruta' => $this->raiz,
            'visibilidad' => Fuente::VISIBILIDAD_PRIVADA,
            'activa' => true,
        ]);
    }

    public function test_cada_carpeta_de_primer_nivel_es_una_serie(): void
    {
        $resumen = app(EscanearFuente::class)($this->fuente());

        $this->assertSame(2, $resumen['series_nuevas']);
        $this->assertSame(3, $resumen['pistas_nuevas']);

        // El .txt no entra: sólo audio.
        $this->assertSame(3, Pista::count());

        $serie = Serie::where('carpeta', 'Curso Cómo morir bien 2014 Guen Rinchung')->firstOrFail();

        // La subcarpeta MP3 no crea una serie aparte: pertenece a la de arriba.
        $this->assertSame(1, $serie->pistas()->count());
    }

    public function test_saca_la_taxonomia_del_nombre_de_la_carpeta(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::where('anio', 2013)->firstOrFail();

        $this->assertSame('retiro', $serie->tipo);
        $this->assertSame('Vacuidad', $serie->titulo);
        $this->assertSame(['Guen Togden'], $serie->maestros->pluck('nombre')->all());
    }

    /**
     * "Refrescar biblioteca" se va a apretar muchas veces, casi siempre para
     * sumar unos pocos audios. Correr de nuevo no puede duplicar nada.
     */
    public function test_refrescar_dos_veces_no_duplica(): void
    {
        $fuente = $this->fuente();

        app(EscanearFuente::class)($fuente);
        $segundo = app(EscanearFuente::class)($fuente->fresh());

        $this->assertSame(0, $segundo['series_nuevas']);
        $this->assertSame(0, $segundo['pistas_nuevas']);
        $this->assertSame(3, Pista::count());
        $this->assertSame(2, Serie::count());
    }

    public function test_suma_lo_nuevo_sin_tocar_lo_que_ya_estaba(): void
    {
        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);

        $this->crear('Retiro de Vacuidad 2013 Guen Togden/03 Tercera charla.mp3');

        $resumen = app(EscanearFuente::class)($fuente->fresh());

        $this->assertSame(1, $resumen['pistas_nuevas']);
        $this->assertSame(4, Pista::count());
    }

    /**
     * Un archivo que ya no está se marca, no se borra: si desapareció por un
     * movimiento de carpetas, borrar la ficha se lleva puesto lo escuchado.
     */
    public function test_lo_que_desaparece_se_marca_pero_no_se_borra(): void
    {
        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);

        unlink($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden/02 Segunda charla.mp3');

        $resumen = app(EscanearFuente::class)($fuente->fresh());

        $this->assertSame(1, $resumen['desaparecidas']);
        $this->assertSame(3, Pista::count());
        $this->assertSame(1, Pista::where('en_nube', false)->count());
    }

    public function test_no_pisa_una_serie_corregida_a_mano(): void
    {
        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);

        Serie::where('anio', 2013)->update([
            'titulo' => 'Mi título propio',
            'editada_a_mano' => true,
        ]);

        app(EscanearFuente::class)($fuente->fresh());

        $this->assertSame('Mi título propio', Serie::where('anio', 2013)->value('titulo'));
    }

    public function test_la_pantalla_de_fuentes_es_solo_del_administrador(): void
    {
        $invitado = User::factory()->create(['rol' => User::ROL_INVITADO]);

        $this->actingAs($invitado)->get('/settings/fuentes')->assertNotFound();
        $this->actingAs($invitado)->post('/biblioteca/refrescar')->assertNotFound();
    }

    public function test_el_administrador_puede_agregar_una_fuente(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)
            ->post('/settings/fuentes', [
                'nombre' => 'Enseñanzas públicas',
                'tipo' => Fuente::TIPO_RCLONE,
                'ruta' => 'onedrive:Dharma/Publicas',
                'visibilidad' => Fuente::VISIBILIDAD_PUBLICA,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fuentes', ['nombre' => 'Enseñanzas públicas']);
    }
}
