<?php

namespace Tests\Feature;

use App\Importacion\ArchivoEnLaFuente;
use App\Importacion\EscanearFuente;
use App\Importacion\ExtraerPortada;
use App\Importacion\GenerarPortada;
use App\Models\Fuente;
use App\Models\Serie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * De dónde sale la carátula de una serie.
 *
 * Medido sobre la biblioteca real: la mayoría de las grabaciones no trae imagen
 * adentro, pero 110 carpetas guardan el flyer del evento al lado de los mp3. Ése
 * es el camino que estas pruebas cuidan.
 */
class PortadaTest extends TestCase
{
    use RefreshDatabase;

    private const CARPETA = 'Retiro de Vacuidad 2013 Guen Togden';

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->raiz = sys_get_temp_dir().'/dharmify-portada-'.uniqid();
        @mkdir($this->raiz.'/'.self::CARPETA, 0777, true);

        // Un mp3 sin imagen adentro, que es el caso habitual de la biblioteca.
        file_put_contents(
            $this->raiz.'/'.self::CARPETA.'/01 Primera charla.mp3',
            str_repeat("\xFF\xFB\x90\xC0".str_repeat("\x00", 413), 200),
        );
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

    /** Una imagen de verdad, para que GD tenga algo que abrir. */
    private function jpg(string $nombre, int $lado = 600): int
    {
        $imagen = imagecreatetruecolor($lado, $lado);

        // Franjas de colores: comprimen a varios KB, que es lo que hace falta
        // para pasar el piso de "esto no es una carátula, es basura".
        for ($i = 0; $i < 40; $i++) {
            $color = imagecolorallocate($imagen, ($i * 37) % 256, ($i * 91) % 256, ($i * 13) % 256);
            imagefilledrectangle($imagen, 0, (int) ($i * $lado / 40), $lado, (int) (($i + 1) * $lado / 40), $color);
        }

        $ruta = $this->raiz.'/'.self::CARPETA.'/'.$nombre;
        imagejpeg($imagen, $ruta, 90);
        imagedestroy($imagen);

        return (int) filesize($ruta);
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
     * @return list<ArchivoEnLaFuente>
     */
    private function imagenesDeLaCarpeta(): array
    {
        $fuente = Fuente::firstOrFail();
        $lector = app(EscanearFuente::class)->lectorPara($fuente);

        $encontradas = [];

        foreach ($lector->listar($fuente->ruta, ['jpg', 'jpeg', 'png', 'webp']) as $imagen) {
            $encontradas[] = $imagen;
        }

        return $encontradas;
    }

    public function test_usa_el_flyer_de_la_carpeta_cuando_el_audio_no_trae_imagen(): void
    {
        $this->jpg('CDA 2013.jpg');

        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();

        $this->assertTrue(app(ExtraerPortada::class)($serie, $this->imagenesDeLaCarpeta()));

        $serie->refresh();

        $this->assertSame('portadas/serie-'.$serie->id.'.jpg', $serie->portada);
        Storage::disk('local')->assertExists($serie->portada);

        // Guardada como jpg de verdad, no como los bytes originales con otro
        // nombre: se sirve siempre como image/jpeg.
        $guardada = (string) Storage::disk('local')->get($serie->portada);
        $this->assertSame("\xFF\xD8\xFF", substr($guardada, 0, 3));
    }

    /**
     * Hay carpetas con quince fotos del curso. Cuando una se llama como una
     * carátula, ésa gana aunque sea la más chica; el tamaño es el desempate,
     * no la regla.
     */
    public function test_prefiere_la_que_se_llama_portada_antes_que_la_mas_pesada(): void
    {
        $grande = $this->jpg('foto del curso.jpg', lado: 900);
        $chica = $this->jpg('portada.jpg', lado: 300);

        $this->assertLessThan($grande, $chica, 'la de nombre bueno tiene que ser la más chica para que la prueba valga');

        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();

        app(ExtraerPortada::class)($serie, $this->imagenesDeLaCarpeta());

        $guardada = (string) Storage::disk('local')->get((string) $serie->refresh()->portada);
        $medidas = getimagesizefromstring($guardada);

        $this->assertNotFalse($medidas);
        $this->assertSame(300, $medidas[0], 'eligió la foto grande en vez de la que se llama portada');
    }

    public function test_dibuja_una_caratula_cuando_no_hay_ninguna_imagen(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();

        app(GenerarPortada::class)($serie);

        $serie->refresh();

        $this->assertSame(Serie::PORTADA_GENERADA, $serie->portada_origen);
        Storage::disk('local')->assertExists((string) $serie->portada);

        $medidas = getimagesizefromstring((string) Storage::disk('local')->get((string) $serie->portada));

        $this->assertNotFalse($medidas);
        $this->assertSame([1200, 1200], [$medidas[0], $medidas[1]]);
        $this->assertSame('image/jpeg', $medidas['mime']);
    }

    /** El mismo título tiene que dar siempre el mismo color, o la grilla bailaría. */
    public function test_el_color_de_la_generica_sale_del_titulo(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();

        app(GenerarPortada::class)($serie);
        $primera = (string) Storage::disk('local')->get((string) $serie->refresh()->portada);

        app(GenerarPortada::class)($serie);
        $segunda = (string) Storage::disk('local')->get((string) $serie->refresh()->portada);

        $this->assertSame($primera, $segunda);
    }

    /**
     * La regla que protege el trabajo de una persona: lo que se sube a mano no
     * lo pisa ningún barrido, ni siquiera cuando encuentra una imagen "mejor".
     */
    public function test_la_caratula_subida_a_mano_gana_y_no_la_pisa_el_barrido(): void
    {
        $this->jpg('CDA 2013.jpg');

        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $subida = $this->raiz.'/subida.png';
        $imagen = imagecreatetruecolor(500, 500);
        imagefilledrectangle($imagen, 0, 0, 500, 500, (int) imagecolorallocate($imagen, 12, 180, 240));
        imagepng($imagen, $subida);
        imagedestroy($imagen);

        $this->actingAs($admin)
            ->post("/series/{$serie->id}/portada", [
                'imagen' => new UploadedFile($subida, 'subida.png', 'image/png', test: true),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $serie->refresh();

        $this->assertSame(Serie::PORTADA_MANUAL, $serie->portada_origen);

        // Un png subido se guarda como jpg: la carátula se sirve siempre como
        // image/jpeg y el nombre no puede mentir sobre el contenido.
        $guardada = (string) Storage::disk('local')->get((string) $serie->portada);
        $this->assertSame("\xFF\xD8\xFF", substr($guardada, 0, 3));

        $this->artisan('dharma:portadas', ['--todas' => true])->assertSuccessful();

        $this->assertSame(
            $guardada,
            (string) Storage::disk('local')->get((string) $serie->refresh()->portada),
            'el barrido pisó una carátula que alguien puso a propósito',
        );
    }

    public function test_solo_el_administrador_puede_cambiar_la_caratula(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();
        $invitado = User::factory()->create(['rol' => User::ROL_INVITADO]);

        $this->actingAs($invitado)
            ->post("/series/{$serie->id}/portada", [])
            ->assertNotFound();
    }

    public function test_marca_la_serie_aunque_no_haya_imagen_en_ningun_lado(): void
    {
        app(EscanearFuente::class)($this->fuente());

        $serie = Serie::firstOrFail();

        $this->assertFalse(app(ExtraerPortada::class)($serie, []));

        $serie->refresh();

        $this->assertNull($serie->portada);
        $this->assertNotNull($serie->portada_revisada_en, 'sin la marca, el barrido giraría sobre las mismas series');
    }
}
