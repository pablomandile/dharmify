<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Importacion\ExtraerTranscripcion;
use App\Importacion\Lectores\LectorDeFuente;
use App\Importacion\Lectores\LectorLocal;
use App\Importacion\TextoDeDocumento;
use App\Models\Fuente;
use App\Models\Invitacion;
use App\Models\Pista;
use App\Models\Transcripcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * El texto de cada enseñanza.
 *
 * La biblioteca ya venía con 709 documentos al lado de los audios y con el mismo
 * nombre base, así que casi todo esto se importa solo. Lo que estas pruebas
 * cuidan es lo que puede salir mal: que el cruce por nombre asocie el texto
 * equivocado, que un formato que no se puede leer rompa la importación entera, y
 * que una subida se dé por buena sin que el archivo haya llegado a la nube.
 */
class TranscripcionTest extends TestCase
{
    use RefreshDatabase;

    private const CARPETA = 'Retiro de purificación La Falda 2019';

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->raiz = sys_get_temp_dir().'/dharmify-transcripcion-'.uniqid();
        @mkdir($this->raiz.'/'.self::CARPETA, 0777, true);
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

    /** Un .docx de verdad: un ZIP con el texto en word/document.xml. */
    private function docx(string $ruta, string ...$parrafos): string
    {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        $cuerpo = '';

        foreach ($parrafos as $parrafo) {
            $cuerpo .= '<w:p><w:r><w:t>'.htmlspecialchars($parrafo).'</w:t></w:r></w:p>';
        }

        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="'.$w.'"><w:body>'.$cuerpo.'</w:body></w:document>',
        );
        $zip->close();

        return $ruta;
    }

    private function enLaCarpeta(string $nombre, string $contenido = 'x'): string
    {
        $ruta = $this->raiz.'/'.self::CARPETA.'/'.$nombre;
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    private function fuente(): Fuente
    {
        return Fuente::create([
            'nombre' => 'De prueba',
            'tipo' => Fuente::TIPO_LOCAL,
            'ruta' => $this->raiz,
            'visibilidad' => Fuente::VISIBILIDAD_PUBLICA,
            'activa' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => User::ROL_ADMIN]);
    }

    /** Corre el barrido igual que el comando, pero sin la consola de por medio. */
    private function barrer(Fuente $fuente): void
    {
        $extraer = app(ExtraerTranscripcion::class);
        $lector = app(EscanearFuente::class)->lectorPara($fuente);
        $candidatos = $extraer->candidatosDe($fuente);

        foreach (Pista::all() as $pista) {
            $doc = $candidatos[ExtraerTranscripcion::claveDe($pista->ruta)] ?? null;

            if ($doc !== null) {
                $bytes = $lector->cabecera($fuente->ruta, $doc->ruta, ExtraerTranscripcion::TAMANIO);

                if ($bytes !== null) {
                    $extraer->guardar($pista, $doc->nombre, $bytes, Transcripcion::ORIGEN_NUBE);
                }
            }

            $extraer->marcarRevisada($pista);
        }
    }

    // ---------------------------------------------------------------- extraer

    public function test_le_saca_el_texto_a_un_docx_separando_los_parrafos(): void
    {
        $ruta = $this->docx($this->raiz.'/p.docx', 'Primer párrafo.', 'Segundo párrafo.');

        $extraido = app(TextoDeDocumento::class)((string) file_get_contents($ruta), 'clase.docx');

        $this->assertNotNull($extraido);
        $this->assertSame("Primer párrafo.\n\nSegundo párrafo.", $extraido->texto);
        $this->assertNull($extraido->marcas, 'un .docx no tiene marcas de tiempo');
    }

    /**
     * El formato que habilita, más adelante, resaltar el texto al ritmo del
     * audio. Las marcas se guardan desde ahora justamente para no tener que
     * volver a importar 709 archivos ese día.
     */
    public function test_un_srt_deja_texto_legible_y_marcas_con_sus_segundos(): void
    {
        $srt = "1\n00:00:12,500 --> 00:00:15,200\nBuenas tardes a todos,\n\n"
            ."2\n00:00:15,300 --> 00:00:18,000\nvamos a empezar.\n\n"
            ."3\n00:00:25,000 --> 00:00:28,400\nSentémonos con la espalda recta.\n";

        $extraido = app(TextoDeDocumento::class)($srt, 'clase.srt');

        $this->assertNotNull($extraido);
        $this->assertCount(3, (array) $extraido->marcas);
        $this->assertSame(12.5, $extraido->marcas[0]['inicio']);
        $this->assertSame('Buenas tardes a todos,', $extraido->marcas[0]['texto']);

        /*
         * Las dos primeras señales van seguidas y quedan en el mismo párrafo; la
         * tercera llega siete segundos después y abre uno nuevo. Sin ese
         * reagrupado, un subtítulo se lee como un muro de renglones sueltos.
         */
        $this->assertSame(
            "Buenas tardes a todos, vamos a empezar.\n\nSentémonos con la espalda recta.",
            $extraido->texto,
        );
    }

    /**
     * El hallazgo que no estaba en el plan: 584 de las 643 transcripciones de
     * la biblioteca ya traen sus tiempos adentro, en párrafos "(0:03 - 4:39)".
     *
     * O sea que resaltar el texto al ritmo del audio no depende de convertir
     * nada a .srt: los tiempos ya estaban. Se separan del texto —leerlos de
     * corrido es ruido— y quedan como marcas, así que para todo lo de arriba un
     * .docx marcado y un .srt son la misma cosa.
     */
    public function test_saca_las_marcas_de_tiempo_que_el_docx_trae_adentro(): void
    {
        $ruta = $this->docx(
            $this->raiz.'/marcado.docx',
            '02 Celebracion del Dharma Argentina 2015',
            '(0:03 - 4:39)', 'Buenas tardes, ahora empezamos.',
            '(4:40 - 8:12)', 'Y la función del comentario.',
            '(8:13 - 12:00)', 'Sentémonos con la espalda recta.',
            '(1:02:33 - 1:05:00)', 'Ya casi terminamos.',
        );

        $extraido = app(TextoDeDocumento::class)((string) file_get_contents($ruta), 'clase.docx');

        $this->assertNotNull($extraido);
        $this->assertCount(4, (array) $extraido->marcas);

        // "0:03" son 3 segundos y "1:02:33" son 3753: las dos formas se leen.
        $this->assertSame(3.0, $extraido->marcas[0]['inicio']);
        $this->assertSame(279.0, $extraido->marcas[0]['fin']);
        $this->assertSame(3753.0, $extraido->marcas[3]['inicio']);

        // Y las señales no quedan en el texto, que es para leer.
        $this->assertStringNotContainsString('(0:03', $extraido->texto);
        $this->assertStringContainsString('Buenas tardes, ahora empezamos.', $extraido->texto);
        $this->assertStringContainsString('Celebracion del Dharma', $extraido->texto);
    }

    /**
     * Lo que evita partir una transcripción en un lugar arbitrario: que alguien
     * mencione un horario de paso no la convierte en un documento marcado.
     */
    public function test_una_mencion_suelta_de_un_horario_no_es_una_marca(): void
    {
        $ruta = $this->docx(
            $this->raiz.'/suelto.docx',
            'Charla escrita a mano.',
            'Nos vemos a las (3:00 - 4:00) si podés.',
            'Otro párrafo cualquiera.',
        );

        $extraido = app(TextoDeDocumento::class)((string) file_get_contents($ruta), 'clase.docx');

        $this->assertNotNull($extraido);
        $this->assertNull($extraido->marcas);
        $this->assertStringContainsString('(3:00 - 4:00)', $extraido->texto);
    }

    public function test_un_txt_en_latin1_no_llega_con_los_acentos_rotos(): void
    {
        $bytes = (string) mb_convert_encoding('Meditación y compasión', 'ISO-8859-1', 'UTF-8');

        $extraido = app(TextoDeDocumento::class)($bytes, 'clase.txt');

        $this->assertNotNull($extraido);
        $this->assertSame('Meditación y compasión', $extraido->texto);
        $this->assertTrue(mb_check_encoding($extraido->texto, 'UTF-8'));
    }

    /** De los 12 .doc viejos no se puede sacar texto, y eso no puede romper nada. */
    public function test_un_doc_viejo_no_rompe_y_queda_para_descargar(): void
    {
        $this->assertNull(app(TextoDeDocumento::class)('cualquier binario', 'clase.doc'));

        $this->enLaCarpeta('Clase 1.mp3');
        $this->enLaCarpeta('Clase 1.doc', 'binario viejo de Word');

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $transcripcion = Transcripcion::firstOrFail();

        $this->assertSame('doc', $transcripcion->formato);
        $this->assertNull($transcripcion->texto);
        $this->assertFalse($transcripcion->tieneTexto());

        // Pero el original quedó guardado, que es lo único que se puede ofrecer.
        Storage::disk('local')->assertExists(
            'transcripciones/'.Pista::firstOrFail()->clave.'.doc',
        );
    }

    // ----------------------------------------------------------------- cruzar

    public function test_asocia_el_documento_que_tiene_el_mismo_nombre_que_el_audio(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        $this->enLaCarpeta('Clase 2.mp3');
        $this->docx($this->enLaCarpeta('Clase 1.docx'), 'El texto de la primera.');
        $this->docx($this->enLaCarpeta('Clase 2.docx'), 'El texto de la segunda.');

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $primera = Pista::where('archivo', 'Clase 1.mp3')->firstOrFail();
        $segunda = Pista::where('archivo', 'Clase 2.mp3')->firstOrFail();

        $this->assertSame('El texto de la primera.', $primera->transcripcion?->texto);
        $this->assertSame('El texto de la segunda.', $segunda->transcripcion?->texto);
    }

    /**
     * Lo que protege de la peor falla posible: que un audio sin documento se
     * quede con el texto de otro. Con 928 pistas, eso pasaría desapercibido.
     */
    public function test_un_audio_sin_documento_al_lado_no_se_queda_con_el_de_otro(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        $this->enLaCarpeta('Clase 2.mp3');
        $this->docx($this->enLaCarpeta('Clase 1.docx'), 'Sólo la primera tiene texto.');

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $this->assertNotNull(Pista::where('archivo', 'Clase 1.mp3')->firstOrFail()->transcripcion);
        $this->assertNull(Pista::where('archivo', 'Clase 2.mp3')->firstOrFail()->transcripcion);
    }

    /**
     * El guión bajo y el espacio son lo mismo.
     *
     * Salió de medir la biblioteca: los audios de varias carpetas usan "_"
     * donde sus documentos usan " " —"2020.01.10_19.27_01.MP3" contra
     * "2020.01.10 19.27 01.docx"—. Sin esto quedaban 197 transcripciones sin
     * asociar, el 28% de las que hay.
     */
    public function test_cruza_aunque_el_audio_use_guion_bajo_donde_el_documento_usa_espacio(): void
    {
        $this->enLaCarpeta('2020.01.10_19.27_01.mp3');
        $this->docx($this->enLaCarpeta('2020.01.10 19.27 01.docx'), 'El texto.');

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $this->assertSame('El texto.', Pista::firstOrFail()->transcripcion?->texto);
    }

    /**
     * La condición que hace segura a esa normalización.
     *
     * Si "clase_1" y "clase 1" fueran dos audios distintos, tratarlos como el
     * mismo les daría a los dos el texto de uno solo. Sobre la biblioteca real
     * está comprobado que no pasa —las 928 claves siguen siendo 928— y esto lo
     * deja fijado para el día que alguien toque la normalización.
     */
    public function test_dos_audios_distintos_nunca_comparten_clave(): void
    {
        $claves = [];

        foreach (['Clase 1.mp3', 'Clase_2.mp3', 'clase 3.MP3', 'Clase-1.mp3'] as $nombre) {
            $claves[] = ExtraerTranscripcion::claveDe(self::CARPETA.'/'.$nombre);
        }

        $this->assertCount(count($claves), array_unique($claves));
    }

    /** Sin esta marca, las pistas sin documento vuelven a la cola para siempre. */
    public function test_marca_como_revisadas_tambien_las_que_no_tienen_documento(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $this->assertNotNull(Pista::firstOrFail()->transcripcion_revisada_en);
    }

    /**
     * Cuando un audio termina con dos documentos al lado, gana el .srt: trae
     * texto Y tiempos, así que quedarse con él nunca es una pérdida.
     */
    public function test_entre_dos_documentos_del_mismo_audio_gana_el_srt(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        $this->docx($this->enLaCarpeta('Clase 1.docx'), 'La versión en Word.');
        $this->enLaCarpeta(
            'Clase 1.srt',
            "1\n00:00:01,000 --> 00:00:03,000\nLa versión con tiempos.\n",
        );

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $transcripcion = Transcripcion::firstOrFail();

        $this->assertSame('srt', $transcripcion->formato);
        $this->assertNotNull($transcripcion->marcas);
    }

    // ------------------------------------------------------------------ subir

    /**
     * La razón de que la subida vaya a la nube: el server es descartable. Lo
     * que se sube y viviera sólo acá abajo se perdería en la próxima purga.
     */
    public function test_subir_deja_el_documento_en_la_nube_junto_al_audio(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');

        app(EscanearFuente::class)($this->fuente());
        $pista = Pista::firstOrFail();

        $srt = $this->raiz.'/subida.srt';
        file_put_contents($srt, "1\n00:00:01,000 --> 00:00:03,000\nHola.\n");

        $this->actingAs($this->admin())
            ->post("/pistas/{$pista->id}/transcripcion", [
                'documento' => new UploadedFile($srt, 'lo-que-sea.srt', 'text/plain', test: true),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        /*
         * En la nube, con el nombre del AUDIO y no con el que traía el archivo
         * subido: es lo que hace que el barrido lo vuelva a encontrar solo.
         */
        $this->assertFileExists($this->raiz.'/'.self::CARPETA.'/Clase 1.srt');

        $transcripcion = $pista->fresh()?->transcripcion;

        $this->assertSame(Transcripcion::ORIGEN_MANUAL, $transcripcion?->origen);
        $this->assertSame('Hola.', $transcripcion?->texto);
    }

    /**
     * Una verificación que siempre dice que sí es peor que no tener ninguna: da
     * la tranquilidad sin dar la garantía. Acá se comprueba que de verdad falla.
     */
    public function test_si_el_archivo_no_llega_a_la_nube_no_se_guarda_nada(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');

        app(EscanearFuente::class)($this->fuente());
        $pista = Pista::firstOrFail();

        /*
         * Un lector que copia bien pero después dice que el archivo no está.
         * Es la única forma de probar la verificación de verdad: si se dejara
         * al lector real, subir y confirmar darían siempre lo mismo y la prueba
         * pasaría aunque no hubiera ninguna comprobación.
         */
        $this->app->bind(EscanearFuente::class, fn () => new class extends EscanearFuente
        {
            public function lectorPara(Fuente $fuente): LectorDeFuente
            {
                return new class extends LectorLocal
                {
                    public function existe(string $raiz, string $ruta): bool
                    {
                        return false;
                    }
                };
            }
        });

        $txt = $this->raiz.'/subida.txt';
        file_put_contents($txt, 'Texto cualquiera.');

        $this->actingAs($this->admin())
            ->post("/pistas/{$pista->id}/transcripcion", [
                'documento' => new UploadedFile($txt, 'clase.txt', 'text/plain', test: true),
            ])
            ->assertSessionHasErrors('documento');

        $this->assertSame(0, Transcripcion::count(), 'se guardó una transcripción que no llegó a la nube');
    }

    public function test_la_verificacion_encuentra_el_archivo_cuando_si_esta(): void
    {
        $fuente = $this->fuente();
        $lector = app(EscanearFuente::class)->lectorPara($fuente);

        $this->enLaCarpeta('Clase 1.txt', 'hola');

        $this->assertTrue($lector->existe($fuente->ruta, self::CARPETA.'/Clase 1.txt'));
        $this->assertFalse($lector->existe($fuente->ruta, self::CARPETA.'/No existe.txt'));
    }

    public function test_solo_el_administrador_sube_una_transcripcion(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        app(EscanearFuente::class)($this->fuente());

        $invitado = User::factory()->create(['rol' => User::ROL_INVITADO]);
        Invitacion::create(['email' => $invitado->email, 'aceptada_en' => now()]);

        $this->actingAs($invitado)
            ->post('/pistas/'.Pista::firstOrFail()->id.'/transcripcion', [])
            ->assertNotFound();
    }

    public function test_no_acepta_cualquier_archivo(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        app(EscanearFuente::class)($this->fuente());

        $exe = $this->raiz.'/algo.exe';
        file_put_contents($exe, 'MZ');

        $this->actingAs($this->admin())
            ->post('/pistas/'.Pista::firstOrFail()->id.'/transcripcion', [
                'documento' => new UploadedFile($exe, 'algo.exe', 'application/octet-stream', test: true),
            ])
            ->assertSessionHasErrors('documento');

        $this->assertSame(0, Transcripcion::count());
    }

    // ----------------------------------------------------------------- servir

    public function test_el_panel_recibe_el_texto_y_las_marcas(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        $this->enLaCarpeta('Clase 1.srt', "1\n00:00:01,000 --> 00:00:03,000\nHola.\n");

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $this->actingAs($this->admin())
            ->getJson('/pistas/'.Pista::firstOrFail()->id.'/transcripcion.json')
            ->assertOk()
            ->assertJsonPath('texto', 'Hola.')
            ->assertJsonPath('formato', 'srt')
            ->assertJsonPath('marcas.0.inicio', 1);
    }

    public function test_una_pista_sin_transcripcion_contesta_404(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        app(EscanearFuente::class)($this->fuente());

        $this->actingAs($this->admin())
            ->getJson('/pistas/'.Pista::firstOrFail()->id.'/transcripcion.json')
            ->assertNotFound();
    }

    public function test_la_ficha_de_la_pista_avisa_si_hay_transcripcion(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        $this->docx($this->enLaCarpeta('Clase 1.docx'), 'Algo.');

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);

        $pista = Pista::firstOrFail();
        $this->assertFalse($pista->ficha()['transcripcion']);

        $this->barrer($fuente);

        $this->assertTrue($pista->fresh()?->ficha()['transcripcion']);
    }

    /**
     * Lo que confirma que las transcripciones van por el mismo camino que todo
     * lo demás: a quien le dejaron de compartir la biblioteca no le aparecen,
     * sin que hiciera falta escribir una regla nueva para esto.
     */
    public function test_a_quien_le_revocaron_la_invitacion_no_le_aparece(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        $this->docx($this->enLaCarpeta('Clase 1.docx'), 'Algo.');

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $invitado = User::factory()->create(['rol' => User::ROL_INVITADO]);
        Invitacion::create(['email' => $invitado->email, 'aceptada_en' => now()]);

        $id = Pista::firstOrFail()->id;

        $this->actingAs($invitado)->getJson("/pistas/{$id}/transcripcion.json")->assertOk();

        Invitacion::query()->update(['revocada_en' => now()]);

        $this->actingAs(User::findOrFail($invitado->id))
            ->getJson("/pistas/{$id}/transcripcion.json")
            ->assertNotFound();
    }

    public function test_se_puede_bajar_el_documento_original(): void
    {
        $this->enLaCarpeta('Clase 1.mp3');
        $this->enLaCarpeta('Clase 1.txt', 'El texto original.');

        $fuente = $this->fuente();
        app(EscanearFuente::class)($fuente);
        $this->barrer($fuente);

        $this->actingAs($this->admin())
            ->get('/pistas/'.Pista::firstOrFail()->id.'/transcripcion/bajar')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }
}
