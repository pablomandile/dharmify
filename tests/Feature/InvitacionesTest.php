<?php

namespace Tests\Feature;

use App\Importacion\EscanearFuente;
use App\Models\Fuente;
use App\Models\Invitacion;
use App\Models\Pista;
use App\Models\Serie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Con quién se comparte la biblioteca.
 *
 * Lo que estas pruebas fijan es el modelo mental: la invitación NO es un ticket
 * de entrada que se usa una vez y se tira, es el permiso vigente de ver la
 * biblioteca. Antes se consultaba sólo al darse de alta, y la consecuencia era
 * que borrar una invitación no le sacaba el acceso absolutamente a nadie.
 */
class InvitacionesTest extends TestCase
{
    use RefreshDatabase;

    private string $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->raiz = sys_get_temp_dir().'/dharmify-invitaciones-'.uniqid();
        @mkdir($this->raiz.'/Retiro de Vacuidad 2013 Guen Togden', 0777, true);
        file_put_contents(
            $this->raiz.'/Retiro de Vacuidad 2013 Guen Togden/01 Primera charla.mp3',
            'x',
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

    /** Una biblioteca pública: es la que ven los invitados. */
    private function sembrarBiblioteca(): void
    {
        app(EscanearFuente::class)(Fuente::create([
            'nombre' => 'De prueba',
            'tipo' => Fuente::TIPO_LOCAL,
            'ruta' => $this->raiz,
            'visibilidad' => Fuente::VISIBILIDAD_PUBLICA,
            'activa' => true,
        ]));
    }

    /**
     * El siguiente pedido, con el usuario recién leído de la base.
     *
     * `puedeVerLaBiblioteca()` memoiza la respuesta en la instancia para no
     * repetir la consulta las siete veces que un pedido pregunta por las
     * fuentes visibles. En un pedido de verdad eso no se nota —cada uno hidrata
     * su propio usuario— pero acá `actingAs()` conserva el MISMO objeto, y sin
     * releerlo el permiso viejo le sobreviviría al cambio. Esto es lo que hace
     * el navegador.
     */
    private function comoRecienLlegada(User $usuario): self
    {
        return $this->actingAs(User::findOrFail($usuario->id));
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => User::ROL_ADMIN]);
    }

    /** Alguien con cuenta creada y su invitación al día. */
    private function invitada(string $email = 'ana@gmail.com'): User
    {
        $usuario = User::factory()->create(['rol' => User::ROL_INVITADO, 'email' => $email]);

        Invitacion::create(['email' => $email, 'aceptada_en' => now()]);

        return $usuario;
    }

    /**
     * La prueba central.
     *
     * Dejar de compartir tiene que sacarle el CONTENIDO, no la cuenta: sus
     * favoritos y sus listas siguen ahí para cuando se la vuelvan a compartir.
     */
    public function test_dejar_de_compartir_deja_sin_biblioteca_pero_no_borra_nada(): void
    {
        $this->sembrarBiblioteca();

        $usuario = $this->invitada();
        $pista = Pista::firstOrFail();
        $usuario->favoritos()->attach($pista->id);

        // Antes: ve la biblioteca y el audio le contesta (202, porque el
        // archivo está en la nube y no en el server, que no es un error).
        $this->actingAs($usuario)->get('/biblioteca')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', false)->has('series.data', 1));

        $this->actingAs($usuario)->get("/pistas/{$pista->id}/audio")->assertStatus(202);

        Invitacion::query()->where('email', $usuario->email)->update(['revocada_en' => now()]);

        // Después: la biblioteca queda vacía y el audio ya no existe para ella,
        // ni siquiera pidiéndolo por su URL.
        $this->comoRecienLlegada($usuario)->get('/biblioteca')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', true)->has('series.data', 0));

        $this->comoRecienLlegada($usuario)->get("/pistas/{$pista->id}/audio")->assertNotFound();

        // Y lo suyo sigue intacto.
        $this->assertDatabaseHas('users', ['id' => $usuario->id]);
        $this->assertDatabaseHas('favoritos', [
            'user_id' => $usuario->id,
            'pista_id' => $pista->id,
        ]);
    }

    /** El desplegable de carpetas del menú viaja en las props compartidas. */
    public function test_el_menu_de_carpetas_tambien_queda_vacio(): void
    {
        $this->sembrarBiblioteca();
        $usuario = $this->invitada();

        $this->actingAs($usuario)->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->has('carpetas', 1)->etc());

        Invitacion::query()->where('email', $usuario->email)->update(['revocada_en' => now()]);

        $this->comoRecienLlegada($usuario)->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->has('carpetas', 0)->etc());
    }

    public function test_volver_a_compartir_devuelve_el_acceso_con_todo_como_estaba(): void
    {
        $this->sembrarBiblioteca();

        $usuario = $this->invitada();
        $pista = Pista::firstOrFail();
        $usuario->favoritos()->attach($pista->id);

        $invitacion = Invitacion::firstOrFail();
        $invitacion->forceFill(['revocada_en' => now()])->save();

        $this->actingAs($this->admin())
            ->post("/settings/invitaciones/{$invitacion->id}/restaurar")
            ->assertRedirect();

        $this->comoRecienLlegada($usuario)->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', false)->has('series.data', 1));

        $this->comoRecienLlegada($usuario)->get('/favoritos')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('pistas', 1)->etc());
    }

    public function test_una_invitacion_vencida_no_da_acceso(): void
    {
        $this->sembrarBiblioteca();

        $usuario = $this->invitada();
        Invitacion::query()->update(['expira_en' => now()->subDay()]);

        $this->comoRecienLlegada($usuario)->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', true)->has('series.data', 0));
    }

    public function test_una_invitacion_que_vence_mañana_sigue_valiendo(): void
    {
        $this->sembrarBiblioteca();

        $usuario = $this->invitada();
        Invitacion::query()->update(['expira_en' => now()->addDay()]);

        $this->comoRecienLlegada($usuario)->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', false)->has('series.data', 1));
    }

    /** El administrador entra por configuración, no por la lista de invitados. */
    public function test_el_administrador_no_depende_de_ninguna_invitacion(): void
    {
        $this->sembrarBiblioteca();

        $this->actingAs($this->admin())->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', false)->has('series.data', 1));

        $this->assertSame(0, Invitacion::count());
    }

    /**
     * El error silencioso que esto evita: el cruce contra Google es un
     * `where('email', ...)` exacto y lo que llega de Google viene en
     * minúsculas. Una invitación guardada tal cual la escribió el admin no
     * serviría para nada y nadie se enteraría hasta que la persona rebotara.
     */
    public function test_el_email_se_guarda_normalizado(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/invitaciones', ['email' => '  Ana.Perez@Gmail.COM '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('invitaciones', ['email' => 'ana.perez@gmail.com']);
    }

    public function test_invitar_registra_quien_invito(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/settings/invitaciones', ['email' => 'ana@gmail.com']);

        $this->assertSame($admin->id, Invitacion::firstOrFail()->invitada_por);
    }

    public function test_se_puede_invitar_con_vencimiento(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/invitaciones', ['email' => 'ana@gmail.com', 'vence_en_dias' => 7])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Invitacion::firstOrFail()->expira_en);
    }

    /**
     * `email` es unique: sin esto, volver a escribir la dirección de alguien a
     * quien le dejaste de compartir explotaría contra el índice en vez de
     * hacer lo obvio.
     */
    public function test_volver_a_invitar_a_alguien_revocado_lo_restaura(): void
    {
        $this->sembrarBiblioteca();

        $usuario = $this->invitada();
        Invitacion::query()->update(['revocada_en' => now()]);

        $this->actingAs($this->admin())
            ->post('/settings/invitaciones', ['email' => $usuario->email])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Invitacion::count());
        $this->assertNull(Invitacion::firstOrFail()->revocada_en);

        $this->comoRecienLlegada($usuario)->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', false));
    }

    public function test_se_cancela_una_invitacion_que_nadie_uso(): void
    {
        $invitacion = Invitacion::create(['email' => 'ana@gmail.com']);

        $this->actingAs($this->admin())
            ->delete("/settings/invitaciones/{$invitacion->id}")
            ->assertRedirect();

        $this->assertSame(0, Invitacion::count());
    }

    /**
     * A quien ya entró no se le cancela, se le deja de compartir: borrar la
     * fila perdería el rastro de que esa persona estuvo, que es justo lo que
     * después uno quiere saber.
     */
    public function test_no_se_cancela_la_invitacion_de_alguien_que_ya_entro(): void
    {
        $this->invitada();
        $invitacion = Invitacion::firstOrFail();

        $this->actingAs($this->admin())
            ->delete("/settings/invitaciones/{$invitacion->id}")
            ->assertNotFound();

        $this->assertSame(1, Invitacion::count());
    }

    public function test_el_panel_muestra_la_actividad_de_cada_invitado(): void
    {
        $this->sembrarBiblioteca();

        $usuario = $this->invitada();
        $usuario->favoritos()->attach(Pista::firstOrFail()->id);
        $usuario->listas()->create(['nombre' => 'Para el colectivo']);

        $this->actingAs($this->admin())->get('/settings/invitaciones')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('settings/Invitaciones')
                ->where('invitaciones.0.estado', Invitacion::ACEPTADA)
                ->where('invitaciones.0.usuario.favoritos', 1)
                ->where('invitaciones.0.usuario.listas', 1)
                ->etc());
    }

    public function test_el_panel_es_solo_del_administrador(): void
    {
        $invitada = $this->invitada();
        $invitacion = Invitacion::firstOrFail();

        $this->actingAs($invitada)->get('/settings/invitaciones')->assertNotFound();
        $this->actingAs($invitada)->post('/settings/invitaciones', ['email' => 'otra@gmail.com'])->assertNotFound();
        $this->actingAs($invitada)->post("/settings/invitaciones/{$invitacion->id}/revocar")->assertNotFound();

        $this->assertNull($invitacion->fresh()->revocada_en);
    }

    public function test_revocar_desde_el_panel_corta_el_acceso(): void
    {
        $this->sembrarBiblioteca();

        $usuario = $this->invitada();
        $invitacion = Invitacion::firstOrFail();

        $this->actingAs($this->admin())
            ->post("/settings/invitaciones/{$invitacion->id}/revocar")
            ->assertRedirect();

        $this->comoRecienLlegada($usuario)->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', true));
    }

    public function test_no_se_invita_a_cualquier_cosa(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/invitaciones', ['email' => 'no es un correo'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Invitacion::count());
    }

    /**
     * Serie y series() aparte: si una fuente es privada, el invitado no la ve
     * aunque su invitación esté impecable. Las dos condiciones son
     * independientes y las dos tienen que cumplirse.
     */
    public function test_una_invitacion_vigente_no_abre_las_fuentes_privadas(): void
    {
        app(EscanearFuente::class)(Fuente::create([
            'nombre' => 'Privada',
            'tipo' => Fuente::TIPO_LOCAL,
            'ruta' => $this->raiz,
            'visibilidad' => Fuente::VISIBILIDAD_PRIVADA,
            'activa' => true,
        ]));

        $usuario = $this->invitada();

        $this->actingAs($usuario)->get('/biblioteca')
            ->assertInertia(fn (Assert $p) => $p->where('sinAcceso', false)->has('series.data', 0));

        $this->actingAs($usuario)->get('/series/'.Serie::firstOrFail()->id)->assertNotFound();
    }
}
