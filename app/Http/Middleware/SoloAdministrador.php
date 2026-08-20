<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el paso a quien no es administrador.
 *
 * 404 y no 403: quien fue invitado a escuchar no tiene por qué enterarse de que
 * existe una pantalla de configuración de fuentes. Un 403 confirma que la ruta
 * está ahí; un 404 no dice nada.
 */
class SoloAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->esAdmin(), 404);

        return $next($request);
    }
}
