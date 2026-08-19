<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * La raíz del sitio.
 *
 * Dharmify no tiene landing: es una biblioteca privada, así que no hay nada que
 * mostrarle a quien todavía no entró. Quien llega sin sesión va derecho al
 * login; quien ya tiene sesión, a la app.
 *
 * Es un controlador y no un closure a propósito: en producción las rutas van
 * cacheadas (`artisan route:cache`) y un closure no se puede serializar.
 */
class InicioController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route(Auth::check() ? 'dashboard' : 'login');
    }
}
