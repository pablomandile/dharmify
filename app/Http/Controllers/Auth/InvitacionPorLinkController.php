<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * La puerta de entrada de un link de invitación.
 *
 * No inicia sesión ni crea nada: guarda el token y manda a Google, que es la
 * única forma de entrar. El token se recupera del otro lado, al volver.
 *
 * Va en sesión y no como parámetro en la URL de vuelta porque esa URL la arma
 * Google con lo que le configuramos en la consola: no hay dónde meterle un dato
 * nuestro sin romper el `redirect_uri` registrado.
 */
class InvitacionPorLinkController extends Controller
{
    public const CLAVE = 'invitacion_token';

    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $invitacion = Invitacion::query()
            ->where('token', $token)
            ->whereNull('email')
            ->vigentes()
            ->first();

        /*
         * Un link ya usado, revocado o vencido no dice cuál de las tres cosas
         * pasó: para quien lo recibe es lo mismo —tiene que pedir otro— y
         * distinguirlas le confirmaría a un desconocido que ese token existió.
         */
        if (! $invitacion) {
            return redirect()->route('login')->with(
                'errorDeIngreso',
                'Ese link de invitación ya no sirve. Pedile uno nuevo al administrador.',
            );
        }

        /*
         * Si ya tiene la sesión abierta no hay nada que hacer con Google: se
         * reclama el link para la cuenta con la que está y listo. Es el caso de
         * alguien a quien le dejaron de compartir la biblioteca y le pasan un
         * link nuevo — sin esto tendría que cerrar sesión para poder usarlo.
         */
        if ($usuario = $request->user()) {
            Invitacion::reclamar($token, $usuario->email);

            return redirect()->route('biblioteca');
        }

        $request->session()->put(self::CLAVE, $token);

        return redirect()->route('google.redirect');
    }
}
