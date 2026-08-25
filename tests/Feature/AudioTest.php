<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use App\Models\Pista;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Servir el audio: el 206, el 202 y con qué nombre se anuncia cada formato.
 *
 * Las tres cosas se rompieron en producción y ninguna estaba cubierta. El 202 en
 * particular es la respuesta que el cliente guardó una vez COMO SI FUERA audio,
 * y ahí empezó todo: aparecía el tilde de "bajado" y el play fallaba.
 */
class AudioTest extends TestCase
{
    use RefreshDatabase;

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->raiz = sys_get_temp_dir().'/dharmify-audio-'.uniqid();
        @mkdir($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (Pista::all() as $pista) {
            @unlink($pista->rutaEnElServer());
        }

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

    /** Siembra una pista a partir de un archivo con el nombre que se le pase. */
    private function sembrar(string $archivo): Pista
    {
        file_put_contents($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden/'.$archivo, 'x');

        $fuente = Fuente::create([
            'nombre' => 'De prueba',
            'tipo' => Fuente::TIPO_LOCAL,
            'ruta' => $this->raiz,
            'visibilidad' => Fuente::VISIBILIDAD_PRIVADA,
            'activa' => true,
        ]);

        app(EscanearFuente::class)($fuente);

        return Pista::firstOrFail();
    }

    /** Pone el archivo en el server, que es lo que separa un 200 de un 202. */
    private function ponerEnElServer(Pista $pista, string $contenido): void
    {
        $ruta = $pista->rutaEnElServer();
        @mkdir(dirname($ruta), 0777, true);
        file_put_contents($ruta, $contenido);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => User::ROL_ADMIN]);
    }

    /**
     * La respuesta que hay que saber NO guardar.
     *
     * El cliente la trataba como una descarga buena porque `Response.ok` de
     * fetch da true para cualquier 2xx, y el 202 entra ahí. Si algún día esto
     * dejara de ser 202, la guarda del cliente quedaría mirando el número
     * equivocado.
     */
    public function test_lo_que_esta_solo_en_la_nube_contesta_202_y_no_audio(): void
    {
        $pista = $this->sembrar('01 Primera charla.mp3');

        $this->actingAs($this->admin())
            ->get("/pistas/{$pista->id}/audio")
            ->assertStatus(202)
            ->assertJsonPath('estado', 'en_nube');
    }

    public function test_un_mp3_se_anuncia_como_mpeg(): void
    {
        $pista = $this->sembrar('01 Primera charla.mp3');
        $this->ponerEnElServer($pista, 'contenido de prueba');

        $this->actingAs($this->admin())
            ->get("/pistas/{$pista->id}/audio")
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');
    }

    /**
     * 27 de las 928 pistas de la biblioteca son m4a, y todas se anunciaban como
     * mp3. No es cosmético: al lado va `X-Content-Type-Options: nosniff`, que le
     * prohíbe al navegador corregir el tipo mirando el archivo. Le mentimos y
     * encima le prohibimos verificarlo.
     *
     * El archivo en el server se llama .mp3 igual —`rutaEnElServer()` guarda
     * todo con esa extensión—, así que el tipo tiene que salir del nombre
     * original y no del que está en disco.
     */
    public function test_un_m4a_se_anuncia_como_mp4_y_no_como_mpeg(): void
    {
        $pista = $this->sembrar('01 Primera charla.m4a');
        $this->ponerEnElServer($pista, 'contenido de prueba');

        $this->assertStringEndsWith('.mp3', $pista->rutaEnElServer());

        $this->actingAs($this->admin())
            ->get("/pistas/{$pista->id}/audio")
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4');
    }

    /**
     * Sin el 206 el audio suena pero no se puede adelantar.
     *
     * Se comprueba por los encabezados y no por el cuerpo, y no es por comodidad:
     * antes de emitir, el controlador tira ABAJO todos los buffers de salida
     * —hace falta, o el audio tarda muchísimo en arrancar— incluido el que usa
     * PHPUnit para capturar la respuesta. `streamedContent()` revienta con
     * "Failed to delete buffer".
     *
     * No se pierde gran cosa: el `Content-Range` y el `Content-Length` SON la
     * cuenta de bytes, calculada por el mismo código que después hace el fseek.
     */
    public function test_un_rango_devuelve_206_con_su_content_range(): void
    {
        $pista = $this->sembrar('01 Primera charla.mp3');
        $this->ponerEnElServer($pista, '0123456789');

        $this->actingAs($this->admin())
            ->get("/pistas/{$pista->id}/audio", ['Range' => 'bytes=2-5'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');
    }

    /** "bytes=-3": los últimos tres. Lo pide algún reproductor al arrancar. */
    public function test_un_rango_desde_el_final(): void
    {
        $this->actingAs($this->admin());

        $pista = $this->sembrar('01 Primera charla.mp3');
        $this->ponerEnElServer($pista, '0123456789');

        $this->get("/pistas/{$pista->id}/audio", ['Range' => 'bytes=-3'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 7-9/10')
            ->assertHeader('Content-Length', '3');
    }

    /** Un rango que empieza más allá del final es un 416, no un 200 mudo. */
    public function test_un_rango_fuera_del_archivo_es_416(): void
    {
        $pista = $this->sembrar('01 Primera charla.mp3');
        $this->ponerEnElServer($pista, '0123456789');

        $this->actingAs($this->admin())
            ->get("/pistas/{$pista->id}/audio", ['Range' => 'bytes=50-60'])
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_sin_sesion_no_se_sirve_audio(): void
    {
        $pista = $this->sembrar('01 Primera charla.mp3');
        $this->ponerEnElServer($pista, 'contenido de prueba');

        $this->get("/pistas/{$pista->id}/audio")->assertRedirect('/login');
    }
}
