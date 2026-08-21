<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Importacion\ExtraerTitulo;
use App\Models\Fuente;
use App\Models\Serie;
use App\Models\User;
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

    /**
     * El error que costó una corrida entera contra la nube: el álbum no siempre
     * está donde uno lo busca primero.
     *
     * getID3 arma una vista unificada en `comments`, pero no siempre la llena.
     * Sobre esta biblioteca hay archivos donde `comments` trae únicamente la
     * carátula y el álbum sólo aparece en `tags`. Mirar sólo `comments` devolvía
     * CERO álbumes sobre 145 series, sin un error que lo delatara.
     */
    public function test_lee_el_album_aunque_solo_este_en_la_etiqueta_id3v2(): void
    {
        $extraer = app(ExtraerTitulo::class);

        $metodo = new \ReflectionMethod($extraer, 'albumDe');

        // Un mp3 mínimo con una etiqueta ID3v2.3 que sólo declara el álbum.
        $this->assertSame(
            'Talk at the University of Cambridge',
            $metodo->invoke($extraer, $this->mp3ConAlbum('Talk at the University of Cambridge')),
        );
    }

    /** Un mp3 de verdad con su etiqueta ID3v2.3 y un par de cuadros de audio. */
    private function mp3ConAlbum(string $album): string
    {
        // El primer byte del texto declara la codificación: 0 = ISO-8859-1.
        $texto = "\x00".$album;
        $cuadro = 'TALB'.pack('N', strlen($texto))."\x00\x00".$texto;

        /*
         * El largo de la etiqueta va en cuatro bytes "syncsafe": siete bits
         * útiles por byte, con el octavo en cero para que la etiqueta nunca se
         * parezca al comienzo de un cuadro de audio.
         */
        $largo = strlen($cuadro);
        $syncsafe = chr(($largo >> 21) & 0x7F).chr(($largo >> 14) & 0x7F)
            .chr(($largo >> 7) & 0x7F).chr($largo & 0x7F);

        $etiqueta = 'ID3'."\x03\x00"."\x00".$syncsafe.$cuadro;

        // MPEG-1 Layer III · 128 kbps · 44100 Hz · mono
        $audio = str_repeat("\xFF\xFB\x90\xC0".str_repeat("\x00", 413), 40);

        return $etiqueta.$audio;
    }

    public function test_el_administrador_puede_corregir_el_titulo(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)
            ->patch("/series/{$serie->id}", ['titulo' => 'Talk at the University of Cambridge'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $serie = $serie->fresh();

        $this->assertSame('Talk at the University of Cambridge', $serie->titulo);
        $this->assertSame(Serie::TITULO_MANUAL, $serie->titulo_origen);
    }

    /** Lo que hace que corregir a mano valga la pena: que no lo pise nada. */
    public function test_lo_corregido_a_mano_sobrevive_al_refresco(): void
    {
        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);

        Serie::firstOrFail()->forceFill([
            'titulo' => 'Como lo puse yo',
            'titulo_origen' => Serie::TITULO_MANUAL,
        ])->save();

        app(EscanearFuente::class)($fuente->fresh());

        $this->assertSame('Como lo puse yo', Serie::firstOrFail()->titulo);
    }

    public function test_un_invitado_no_puede_renombrar(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();
        $invitado = User::factory()->create(['rol' => User::ROL_INVITADO]);

        $this->actingAs($invitado)
            ->patch("/series/{$serie->id}", ['titulo' => 'Mio'])
            ->assertNotFound();

        $this->assertNotSame('Mio', Serie::firstOrFail()->titulo);
    }

    /** Las series nuevas arrancan con el título de la carpeta. */
    public function test_una_serie_nueva_nace_con_el_titulo_de_la_carpeta(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $this->assertSame(Serie::TITULO_CARPETA, Serie::firstOrFail()->titulo_origen);
    }
}
