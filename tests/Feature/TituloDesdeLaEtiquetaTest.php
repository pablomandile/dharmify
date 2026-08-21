<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use App\Models\Serie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El título de la serie tomado de la etiqueta de álbum del primer audio.
 *
 * Lo que estas pruebas cuidan es lo que puede salir mal: que un refresco lo
 * pise, y que un álbum genérico se lleve puesto un título bueno.
 */
class TituloDesdeLaEtiquetaTest extends TestCase
{
    use RefreshDatabase;

    private const CARPETA = 'Charla Talk at Cambrigde University 2013 Guen Togden';

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->raiz = sys_get_temp_dir().'/dharmify-titulos-'.uniqid();
        @mkdir($this->raiz.'/'.self::CARPETA, 0777, true);
        file_put_contents($this->raiz.'/'.self::CARPETA.'/01 Charla.mp3', 'x');
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

    /**
     * La razón de ser de la columna `titulo_origen`.
     *
     * Sin ella el escaneo recalcula el título desde el nombre de la carpeta en
     * cada refresco, y un título tomado de la etiqueta duraría una sola vuelta.
     */
    public function test_un_refresco_no_pisa_el_titulo_que_vino_de_la_etiqueta(): void
    {
        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);

        $serie = Serie::firstOrFail();

        // El parser saca el título del nombre de la carpeta, con su typo incluido.
        $this->assertSame('Talk at Cambrigde University', $serie->titulo);

        $serie->forceFill([
            'titulo' => 'Talk at the University of Cambridge',
            'titulo_origen' => Serie::TITULO_ETIQUETA,
        ])->save();

        app(EscanearFuente::class)($fuente->fresh());

        $this->assertSame('Talk at the University of Cambridge', $serie->fresh()->titulo);
    }

    /** Lo que sí se sigue recalculando, porque no está en las etiquetas. */
    public function test_el_refresco_sigue_actualizando_el_tipo_y_el_ano(): void
    {
        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);

        $serie = Serie::firstOrFail();
        $serie->forceFill([
            'titulo' => 'Otro',
            'titulo_origen' => Serie::TITULO_ETIQUETA,
            'tipo' => null,
            'anio' => null,
        ])->save();

        app(EscanearFuente::class)($fuente->fresh());

        $serie = $serie->fresh();

        $this->assertSame('Otro', $serie->titulo);
        $this->assertSame('charla', $serie->tipo);
        $this->assertSame(2013, $serie->anio);
    }

    public function test_lo_editado_a_mano_sigue_intocable(): void
    {
        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);

        Serie::firstOrFail()->forceFill([
            'titulo' => 'Como lo puse yo',
            'editada_a_mano' => true,
        ])->save();

        app(EscanearFuente::class)($fuente->fresh());

        $this->assertSame('Como lo puse yo', Serie::firstOrFail()->titulo);
    }

    /** Las series nuevas arrancan con el título de la carpeta. */
    public function test_una_serie_nueva_nace_con_el_titulo_de_la_carpeta(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $this->assertSame(Serie::TITULO_CARPETA, Serie::firstOrFail()->titulo_origen);
    }
}
