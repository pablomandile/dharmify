<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Importacion\ExtraerDuracion;
use App\Models\Fuente;
use App\Models\Pista;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La duración sale del encabezado, sin bajar el audio.
 *
 * Los mp3 de prueba se arman acá y no se guardan en el repositorio: son cuadros
 * MPEG-1 Layer III de 128 kbps repetidos, que es todo lo que getID3 necesita
 * para contar. Un archivo de verdad pesaría megas y no probaría nada distinto.
 */
class DuracionTest extends TestCase
{
    use RefreshDatabase;

    /** MPEG-1 Layer III · 128 kbps · 44100 Hz · mono · sin padding. */
    private const CUADRO = "\xFF\xFB\x90\xC0";

    private const BYTES_POR_CUADRO = 417;

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->raiz = sys_get_temp_dir().'/dharmify-duracion-'.uniqid();
        @mkdir($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden', 0777, true);
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

    private function mp3(string $nombre, int $cuadros): void
    {
        file_put_contents(
            $this->raiz.'/Retiro de Vacuidad 2013 Guen Togden/'.$nombre,
            str_repeat(self::CUADRO.str_repeat("\x00", self::BYTES_POR_CUADRO - 4), $cuadros),
        );
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
     * La prueba que importa: el archivo pesa 2 MB y sólo se leen los primeros
     * 400 KB, así que la duración correcta únicamente puede salir de combinar
     * el bitrate del encabezado con el tamaño verdadero del archivo.
     *
     * Si alguna vez se dejara de pasarle ese tamaño a getID3, esto daría unos
     * 25 segundos en lugar de 130 y la prueba se pondría roja.
     */
    public function test_la_duracion_sale_del_encabezado_aunque_el_archivo_este_cortado(): void
    {
        $this->mp3('01 Primera charla.mp3', cuadros: 5000);

        app(EscanearFuente::class)($this->fuente());

        app(ExtraerDuracion::class)->deLote(Pista::with('serie.fuente')->get());

        $pista = Pista::firstOrFail();

        $this->assertGreaterThan(400000, $pista->bytes, 'el archivo tiene que superar la cabecera que se lee');
        // 5000 cuadros × 1152 muestras ÷ 44100 Hz = 130,6 s
        $this->assertEqualsWithDelta(130, $pista->duracion_seg, 2);
    }

    /**
     * La lección que dejaron las carátulas: sin marcar lo que ya se revisó, las
     * pistas que no dan duración vuelven a la cola en cada tanda y el barrido
     * gira sobre las mismas para siempre.
     */
    public function test_marca_lo_revisado_aunque_no_haya_podido_calcular_nada(): void
    {
        $this->mp3('01 Primera charla.mp3', cuadros: 5000);
        file_put_contents($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden/02 Rota.mp3', str_repeat('x', 5000));

        app(EscanearFuente::class)($this->fuente());

        $resultado = app(ExtraerDuracion::class)->deLote(Pista::with('serie.fuente')->get());

        $rota = Pista::where('archivo', '02 Rota.mp3')->firstOrFail();

        $this->assertSame(1, $resultado['con']);
        $this->assertSame(1, $resultado['sin']);
        $this->assertNull($rota->duracion_seg);
        $this->assertNotNull($rota->duracion_revisada_en, 'sin la marca, el barrido nunca avanzaría');
        $this->assertSame(0, Pista::whereNull('duracion_revisada_en')->count());
    }

    public function test_el_reproductor_puede_corregir_la_duracion_que_midio(): void
    {
        $this->mp3('01 Primera charla.mp3', cuadros: 5000);

        app(EscanearFuente::class)($this->fuente());

        $pista = Pista::firstOrFail();
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)
            ->postJson("/pistas/{$pista->id}/duracion", ['segundos' => 3827])
            ->assertOk();

        $this->assertSame(3827, $pista->fresh()->duracion_seg);
        $this->assertNotNull($pista->fresh()->duracion_revisada_en);
    }

    public function test_no_acepta_una_duracion_disparatada(): void
    {
        $this->mp3('01 Primera charla.mp3', cuadros: 5000);

        app(EscanearFuente::class)($this->fuente());

        $pista = Pista::firstOrFail();
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)
            ->postJson("/pistas/{$pista->id}/duracion", ['segundos' => 999999])
            ->assertStatus(422);

        $this->assertNull($pista->fresh()->duracion_seg);
    }

    public function test_quien_no_ve_la_fuente_tampoco_puede_tocarle_la_duracion(): void
    {
        $this->mp3('01 Primera charla.mp3', cuadros: 5000);

        // La fuente es privada, así que un invitado no debería ni saber que existe.
        app(EscanearFuente::class)($this->fuente());

        $pista = Pista::firstOrFail();
        $invitado = User::factory()->create(['rol' => User::ROL_INVITADO]);

        $this->actingAs($invitado)
            ->postJson("/pistas/{$pista->id}/duracion", ['segundos' => 3827])
            ->assertNotFound();

        $this->assertNull($pista->fresh()->duracion_seg);
    }
}
