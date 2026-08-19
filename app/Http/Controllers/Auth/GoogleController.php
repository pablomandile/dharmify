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
use Inertia\Inertia;
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

        try {
            $usuario = $this->ingreso->resolver(
                CuentaDeGoogle::desdeSocialite(Socialite::driver('google')->user()),
            );
        } catch (AccesoNoAutorizado) {
            // Caso esperado, no un fallo: entró bien a Google pero no está
            // invitada. Se le dice con claridad y sin tecnicismos.
            return $this->volverAlLoginCon(
                'Esa cuenta no tiene acceso a la biblioteca. Pedile una invitación al administrador.',
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

        return redirect()->intended(route('dashboard'));
    }

    /**
     * El cartel viaja por el mecanismo de flash de Inertia, que es el que la app
     * ya usa para los toasts (ver resources/js/lib/flashToast.ts). Inventar un
     * canal propio significaría que este error, y sólo este, se vea distinto.
     */
    private function volverAlLoginCon(string $mensaje): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $mensaje]);

        return redirect()->route('login');
    }
}
