<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\AccesoNoAutorizado;
use App\Http\Controllers\Controller;
use App\Services\IngresoConGoogleService;
use App\Support\CuentaDeGoogle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as RespuestaDeSocialite;
use Throwable;

/**
 * Ingreso con Google, que es la única puerta de entrada a Dharmify.
 *
 * Las dos rutas devuelven 404 si no hay credenciales configuradas, en vez de
 * explotar con un error de Socialite: así el código puede estar desplegado
 * antes de que exista el proyecto en Google Cloud.
 */
class GoogleController extends Controller
{
    public function __construct(private readonly IngresoConGoogleService $ingreso) {}

    /**
     * Manda a la pantalla de Google.
     */
    public function redirigir(): RespuestaDeSocialite|RedirectResponse
    {
        abort_unless(IngresoConGoogleService::configurado(), 404);

        return Socialite::driver('google')->redirect();
    }

    /**
     * Vuelta de Google: resuelve la cuenta e inicia la sesión.
     */
    public function volver(Request $request): RedirectResponse
    {
        abort_unless(IngresoConGoogleService::configurado(), 404);

        // El usuario canceló en la pantalla de Google. No es un error: vuelve
        // al login sin ningún cartel rojo.
        if ($request->has('error')) {
            return redirect()->route('login');
        }

        $cuenta = null;

        try {
            // Se asigna antes de resolver para poder nombrar la cuenta en el
            // mensaje de rechazo: quien tiene varias sesiones de Google abiertas
            // no tiene forma de saber con cuál entró.
            $cuenta = CuentaDeGoogle::desdeSocialite(Socialite::driver('google')->user());

            $usuario = $this->ingreso->resolver($cuenta);
        } catch (AccesoNoAutorizado $e) {
            /*
             * Caso esperado, no un fallo: entró bien a Google pero no está
             * invitada. Igual se registra —con el email— porque sin eso el
             * rechazo no deja ningún rastro y no hay forma de saber si alguien
             * quedó afuera por error de configuración o porque no corresponde.
             */
            Log::info('Ingreso con Google rechazado.', ['motivo' => $e->getMessage()]);

            return $this->volverAlLoginCon(
                $cuenta
                    ? "La cuenta {$cuenta->email} no tiene acceso a la biblioteca. Pedile una invitación al administrador."
                    : 'Esa cuenta de Google no tiene acceso a la biblioteca. Pedile una invitación al administrador.',
            );
        } catch (Throwable $e) {
            /*
             * El detalle va al log y no a la pantalla: los mensajes de Socialite
             * traen partes de la respuesta de Google, que no le dicen nada al
             * usuario y pueden incluir datos de la cuenta.
             */
            Log::warning('Falló el ingreso con Google.', ['excepcion' => $e->getMessage()]);

            return $this->volverAlLoginCon(
                'No pudimos entrar con Google. Probá de nuevo en un momento.',
            );
        }

        Auth::login($usuario, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('biblioteca'));
    }

    /**
     * El cartel va por la sesión y lo muestra el login en línea, igual que su
     * `status`.
     *
     * NO por el flash de Inertia: la vuelta de Google es una carga de página
     * completa y no una visita, así que ese evento puede no dispararse; y el
     * Toaster que lo mostraría sólo está montado en los layouts de la app, no
     * en los de autenticación. El mensaje se emitía y no lo veía nadie: desde
     * afuera, el botón de Google parecía no hacer nada.
     */
    private function volverAlLoginCon(string $mensaje): RedirectResponse
    {
        return redirect()->route('login')->with('errorDeIngreso', $mensaje);
    }
}
