<?php

namespace Tests\Feature\Auth;

use App\Exceptions\AccesoNoAutorizado;
use App\Models\Invitacion;
use App\Models\User;
use App\Services\IngresoConGoogleService;
use App\Support\CuentaDeGoogle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngresoConGoogleTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(string $email, string $id = 'g-1'): CuentaDeGoogle
    {
        return new CuentaDeGoogle(
            id: $id,
            email: $email,
            nombre: 'Alguien',
            avatar: 'https://example.test/foto.jpg',
        );
    }

    private function servicio(): IngresoConGoogleService
    {
        return app(IngresoConGoogleService::class);
    }

    public function test_el_email_configurado_entra_como_administrador(): void
    {
        config(['dharmify.admin_emails' => ['duenio@example.test']]);

        $usuario = $this->servicio()->resolver($this->cuenta('duenio@example.test'));

        $this->assertTrue($usuario->esAdmin());
        $this->assertSame('g-1', $usuario->google_id);
    }

    /**
     * Tener la cuenta personal y la del trabajo es lo normal, y Google elige por
     * vos con cuál entrás según qué sesión tengas abierta. Con un solo email
     * permitido, entrar con la otra te deja afuera de tu propia biblioteca.
     */
    public function test_admite_varios_administradores_separados_por_coma(): void
    {
        config(['dharmify.admin_emails' => ['uno@example.test', 'otro@example.test']]);

        foreach (['uno@example.test', 'otro@example.test'] as $i => $email) {
            $usuario = $this->servicio()->resolver($this->cuenta($email, "g-{$i}"));

            $this->assertTrue($usuario->esAdmin(), "no quedó admin: {$email}");
        }
    }

    public function test_quien_tiene_invitacion_entra_como_invitado(): void
    {
        Invitacion::create(['email' => 'amiga@example.test']);

        $usuario = $this->servicio()->resolver($this->cuenta('amiga@example.test'));

        $this->assertFalse($usuario->esAdmin());
        $this->assertSame(User::ROL_INVITADO, $usuario->rol);
        $this->assertNotNull(Invitacion::first()->aceptada_en);
    }

    /**
     * Lo que distingue la invitación de un ticket de entrada: revocarla tiene
     * que cortarle el paso a alguien que TODAVÍA no se dio de alta, no sólo a
     * quien ya tiene cuenta. Sin esto, dejar de compartir con una dirección que
     * nunca llegó a entrar seguiría dejándola crearse la cuenta después.
     */
    public function test_una_invitacion_revocada_no_deja_entrar_a_alguien_nuevo(): void
    {
        Invitacion::create(['email' => 'amiga@example.test', 'revocada_en' => now()]);

        $this->expectException(AccesoNoAutorizado::class);

        $this->servicio()->resolver($this->cuenta('amiga@example.test'));
    }

    public function test_una_invitacion_vencida_no_deja_entrar_a_alguien_nuevo(): void
    {
        Invitacion::create(['email' => 'amiga@example.test', 'expira_en' => now()->subDay()]);

        $this->expectException(AccesoNoAutorizado::class);

        $this->servicio()->resolver($this->cuenta('amiga@example.test'));
    }

    /**
     * El corazón de todo esto: entrar bien a Google NO da acceso. La biblioteca
     * es privada y sin invitación se rebota, por más impecable que sea la cuenta.
     */
    public function test_una_cuenta_de_google_sin_invitacion_no_entra(): void
    {
        config(['dharmify.admin_emails' => ['duenio@example.test']]);

        $this->expectException(AccesoNoAutorizado::class);

        $this->servicio()->resolver($this->cuenta('cualquiera@example.test'));

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Una cuenta creada a mano (por ejemplo por consola) que entra con Google
     * por primera vez tiene que quedar vinculada, no duplicada.
     */
    public function test_vincula_la_cuenta_de_google_a_un_usuario_que_ya_existia(): void
    {
        $existente = User::factory()->create([
            'email' => 'yo@example.test',
            'rol' => User::ROL_ADMIN,
        ]);

        $usuario = $this->servicio()->resolver($this->cuenta('yo@example.test', 'g-99'));

        $this->assertSame($existente->id, $usuario->id);
        $this->assertSame('g-99', $usuario->google_id);
        $this->assertDatabaseCount('users', 1);
        // Vincular no puede degradar a quien ya era administrador.
        $this->assertTrue($usuario->fresh()->esAdmin());
    }

    public function test_el_segundo_ingreso_refresca_los_datos_y_no_crea_otro_usuario(): void
    {
        Invitacion::create(['email' => 'amiga@example.test']);
        $this->servicio()->resolver($this->cuenta('amiga@example.test', 'g-7'));

        $usuario = $this->servicio()->resolver(new CuentaDeGoogle(
            id: 'g-7',
            email: 'amiga@example.test',
            nombre: 'Nombre Nuevo',
            avatar: 'https://example.test/otra.jpg',
        ));

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Nombre Nuevo', $usuario->name);
        $this->assertSame('https://example.test/otra.jpg', $usuario->avatar_url);
    }

    /**
     * Google ya verificó el email. Sin esto, el middleware `verified` deja al
     * usuario recién creado atrapado en la pantalla de confirmar el correo, sin
     * ninguna forma de confirmarlo.
     */
    public function test_da_el_email_por_verificado(): void
    {
        Invitacion::create(['email' => 'amiga@example.test']);

        $usuario = $this->servicio()->resolver($this->cuenta('amiga@example.test'));

        $this->assertNotNull($usuario->email_verified_at);
    }

    public function test_las_rutas_de_google_dan_404_sin_credenciales(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get(route('google.redirect'))->assertNotFound();
        $this->get(route('google.callback'))->assertNotFound();
    }

    /**
     * El bug que hacía que el botón de Google "no hiciera nada": el rechazo
     * volvía al login y el motivo no se veía por ningún lado. Tiene que llegar
     * como prop, porque la vuelta de Google es una carga de página completa.
     */
    public function test_el_login_muestra_por_que_rebotó_un_ingreso(): void
    {
        $this->withSession(['errorDeIngreso' => 'La cuenta x@y.test no tiene acceso.'])
            ->get(route('login'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('auth/Login')
                    ->where('errorDeIngreso', 'La cuenta x@y.test no tiene acceso.')
            );
    }

    public function test_el_login_ofrece_el_boton_de_google(): void
    {
        $this->get(route('login'))->assertOk();

        $this->assertStringContainsString(
            'google-login',
            file_get_contents(resource_path('js/pages/auth/Login.vue')),
        );
    }
}
